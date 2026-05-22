package main

// dply DigitalOcean Functions <-> Gin (net/http) adapter.
//
// Injected at deploy time by App\Services\Deploy\ServerlessGinAdapter.
// Do not edit in the user's repo — dply overwrites this file on every deploy.
//
// Go is statically compiled, so — unlike the dynamic-language adapters —
// dply cannot discover the router by introspection. The repo must export
//
//     func Router() http.Handler
//
// (a *gin.Engine satisfies http.Handler, as do chi, echo, gorilla/mux, and
// the net/http default mux). The adapter shares `package main` with the
// repo, drives that handler with an in-memory request rebuilt from the
// OpenWhisk web-action event, and dply points the action's exec.main at
// DplyMain. Each organic invocation is reported to dply's Logs page.

import (
	"bytes"
	"crypto/hmac"
	"crypto/sha256"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"strings"
	"time"
)

var dplyRouter http.Handler

func dplyOwRequest(event map[string]interface{}) *http.Request {
	method := "GET"
	if m, ok := event["__ow_method"].(string); ok && m != "" {
		method = strings.ToUpper(m)
	}

	path := "/"
	if p, ok := event["__ow_path"].(string); ok {
		path = "/" + strings.TrimLeft(p, "/")
	}
	target := path
	if q, ok := event["__ow_query"].(string); ok && q != "" {
		target = path + "?" + q
	}

	var body []byte
	if b, ok := event["__ow_body"].(string); ok {
		if encoded, _ := event["__ow_isBase64Encoded"].(bool); encoded {
			body, _ = base64.StdEncoding.DecodeString(b)
		} else {
			body = []byte(b)
		}
	}

	req := httptest.NewRequest(method, target, bytes.NewReader(body))
	if headers, ok := event["__ow_headers"].(map[string]interface{}); ok {
		for key, value := range headers {
			if s, ok := value.(string); ok {
				req.Header.Set(key, s)
			}
		}
	}
	return req
}

func dplyOwReport(event map[string]interface{}, status int, durationMs int64) {
	defer func() { _ = recover() }()

	if headers, ok := event["__ow_headers"].(map[string]interface{}); ok {
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
	if m, ok := event["__ow_method"].(string); ok && m != "" {
		method = strings.ToUpper(m)
	}
	path := "/"
	if p, ok := event["__ow_path"].(string); ok {
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

// DplyMain is the OpenWhisk entrypoint dply points the action at; it drives
// the repo's exported Router() with the request rebuilt from the OpenWhisk
// web-action event.
func DplyMain(event map[string]interface{}) map[string]interface{} {
	start := time.Now()

	if dplyRouter == nil {
		dplyRouter = Router()
	}

	recorder := httptest.NewRecorder()
	dplyRouter.ServeHTTP(recorder, dplyOwRequest(event))

	headers := map[string]interface{}{}
	for name := range recorder.Header() {
		headers[name] = recorder.Header().Get(name)
	}

	dplyOwReport(event, recorder.Code, time.Since(start).Milliseconds())

	return map[string]interface{}{
		"statusCode": recorder.Code,
		"headers":    headers,
		"body":       recorder.Body.String(),
	}
}
