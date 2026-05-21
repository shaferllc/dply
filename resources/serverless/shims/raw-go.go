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

// DplyMain is the OpenWhisk entrypoint dply points the deployed action at; it
// wraps the repo's own Main so organic invocations reach dply's Logs page.
func DplyMain(event map[string]interface{}) map[string]interface{} {
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
	return result
}
