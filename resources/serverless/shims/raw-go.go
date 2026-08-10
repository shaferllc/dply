package main

// dply logging shim for a raw OpenWhisk Go action.
//
// Injected at deploy time by App\Services\Deploy\ServerlessLoggingShimInjector.
// Do not edit in the user's repo — dply overwrites this file on every deploy.
//
// The DigitalOcean Functions activations list API is structurally empty, so
// an un-wrapped raw action is invisible to dply. This shim wraps the repo's
// own Main and fire-and-forget POSTs each organic invocation to dply's
// ingest endpoint, exactly as the Laravel adapter does for framework apps.
//
// The shim shares package main with the user's action, so it calls Main
// directly; dply points the deployed action's exec.main at DplyMain.

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/url"
	"os"
	"strconv"
	"strings"
	"time"
)

func dplyReport(args map[string]interface{}, status int, durationMs int64) {
	defer func() { _ = recover() }()

	// dply-initiated invocations are already captured inline by the caller —
	// never double-report them.
	if headers, ok := args["__ow_headers"].(map[string]interface{}); ok {
		for _, marker := range []string{"x-dply-run", "x-dply-source"} {
			if v, present := headers[marker]; present {
				if s, _ := v.(string); strings.TrimSpace(s) != "" {
					return
				}
			}
		}
	}

	endpoint := os.Getenv("DPLY_LOG_INGEST_URL")
	secret := os.Getenv("DPLY_LOG_INGEST_SECRET")
	if endpoint == "" || secret == "" {
		return
	}

	parsed, err := url.Parse(endpoint)
	if err != nil {
		return
	}
	host := parsed.Hostname()
	if host == "" || host == "localhost" || host == "127.0.0.1" {
		return
	}

	method := "GET"
	if m, ok := args["__ow_method"].(string); ok && m != "" {
		method = strings.ToUpper(m)
	}
	path := "/"
	if p, ok := args["__ow_path"].(string); ok {
		path = "/" + strings.TrimLeft(p, "/")
	}

	payload, err := json.Marshal(map[string]interface{}{
		"method":      method,
		"path":        path,
		"status":      status,
		"duration_ms": durationMs,
		"logs":        []string{},
		"context":     map[string]interface{}{},
	})
	if err != nil {
		return
	}

	mac := hmac.New(sha256.New, []byte(secret))
	mac.Write(payload)

	req, err := http.NewRequest(http.MethodPost, endpoint, bytes.NewReader(payload))
	if err != nil {
		return
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Dply-Signature", hex.EncodeToString(mac.Sum(nil)))

	client := &http.Client{Timeout: 800 * time.Millisecond}
	if resp, err := client.Do(req); err == nil {
		_ = resp.Body.Close()
	}
}

// dplyCorsHeaders renders the response headers for the CORS policy dply binds
// as a default parameter. An origin outside the policy gets no CORS headers at
// all — that IS the rejection; inventing a header would defeat the allow-list.
func dplyCorsHeaders(policy map[string]interface{}, args map[string]interface{}) map[string]interface{} {
	origin := ""
	if headers, ok := args["__ow_headers"].(map[string]interface{}); ok {
		for _, key := range []string{"origin", "Origin"} {
			if v, present := headers[key]; present {
				if s, _ := v.(string); s != "" {
					origin = s
					break
				}
			}
		}
	}

	allowed := dplyStringSlice(policy["allow_origins"])
	if len(allowed) == 0 {
		allowed = []string{"*"}
	}
	credentials, _ := policy["allow_credentials"].(bool)

	allowOrigin := ""
	for _, candidate := range allowed {
		if candidate == "*" {
			// `*` cannot be combined with credentials, so echo the caller's
			// origin when credentials are in play.
			if credentials && origin != "" {
				allowOrigin = origin
			} else {
				allowOrigin = "*"
			}
			break
		}
		if origin != "" && candidate == origin {
			allowOrigin = origin
			break
		}
	}
	if allowOrigin == "" {
		return map[string]interface{}{}
	}

	headers := map[string]interface{}{"Access-Control-Allow-Origin": allowOrigin}
	if allowOrigin != "*" {
		headers["Vary"] = "Origin"
	}
	for key, header := range map[string]string{
		"allow_methods":  "Access-Control-Allow-Methods",
		"allow_headers":  "Access-Control-Allow-Headers",
		"expose_headers": "Access-Control-Expose-Headers",
	} {
		if values := dplyStringSlice(policy[key]); len(values) > 0 {
			headers[header] = strings.Join(values, ", ")
		}
	}
	if credentials {
		headers["Access-Control-Allow-Credentials"] = "true"
	}
	switch age := policy["max_age"].(type) {
	case float64:
		headers["Access-Control-Max-Age"] = strconv.Itoa(int(age))
	case int:
		headers["Access-Control-Max-Age"] = strconv.Itoa(age)
	}

	return headers
}

// dplyStringSlice reads a JSON array of strings out of a decoded parameter.
func dplyStringSlice(value interface{}) []string {
	raw, ok := value.([]interface{})
	if !ok {
		return nil
	}

	out := make([]string, 0, len(raw))
	for _, entry := range raw {
		if s, ok := entry.(string); ok && s != "" {
			out = append(out, s)
		}
	}

	return out
}

// DplyMain is the OpenWhisk entrypoint dply points the deployed action at; it
// wraps the repo's own Main so organic invocations reach dply's Logs page.
func DplyMain(event map[string]interface{}) map[string]interface{} {
	policy, hasPolicy := event["__dply_cors"].(map[string]interface{})

	method := "GET"
	if m, ok := event["__ow_method"].(string); ok && m != "" {
		method = strings.ToUpper(m)
	}

	// With web-custom-options in force the platform stops answering preflight,
	// so the function has to — before the user's handler, which knows nothing
	// about CORS.
	if hasPolicy && method == http.MethodOptions {
		dplyReport(event, 204, 0)
		return map[string]interface{}{
			"statusCode": 204,
			"headers":    dplyCorsHeaders(policy, event),
			"body":       "",
		}
	}

	start := time.Now()
	result := Main(event)

	status := 200
	switch s := result["statusCode"].(type) {
	case int:
		status = s
	case float64:
		status = int(s)
	}

	dplyReport(event, status, time.Since(start).Milliseconds())

	// The handler's own headers win — a function that sets its own CORS header
	// has made a deliberate choice the policy shouldn't overwrite.
	if hasPolicy {
		merged := dplyCorsHeaders(policy, event)
		if existing, ok := result["headers"].(map[string]interface{}); ok {
			for key, value := range existing {
				merged[key] = value
			}
		}
		result["headers"] = merged
	}

	return result
}
