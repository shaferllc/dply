"""dply DigitalOcean Functions <-> WSGI adapter (Flask, Django, …).

Injected at deploy time by App\\Services\\Deploy\\ServerlessFlaskAdapter and
App\\Services\\Deploy\\ServerlessDjangoAdapter.
Do not edit in the user's repo — dply overwrites this file on every deploy.

DigitalOcean Functions invokes main($args) with a raw OpenWhisk web-action
event; a Flask or Django app is a WSGI application. This file is the
OpenWhisk-side counterpart to the Laravel adapter: it translates the __ow_*
event into a WSGI environ, runs the repo's WSGI app, maps the response back
to the {statusCode, headers, body} shape OpenWhisk expects, and
fire-and-forget reports each organic invocation to dply's Logs page.
"""
import base64
import hashlib
import hmac
import importlib.util
import io
import json
import os
import time
import urllib.request
from urllib.parse import urlparse

_spec = importlib.util.spec_from_file_location(
    "_dply_flask_app",
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "{{DPLY_ENTRY}}"),
)
_module = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_module)
# The Flask app is itself a WSGI callable (Flask.__call__ -> wsgi_app).
_wsgi_app = getattr(_module, "{{DPLY_APP_VAR}}")


def _request_body(args):
    body = args.get("__ow_body", "") or ""
    if args.get("__ow_isBase64Encoded"):
        return base64.b64decode(body)
    if isinstance(body, str):
        return body.encode("utf-8")
    return bytes(body or b"")


def _build_environ(args):
    headers = (args or {}).get("__ow_headers") or {}
    body = _request_body(args)

    environ = {
        "REQUEST_METHOD": str((args or {}).get("__ow_method", "GET")).upper(),
        "SCRIPT_NAME": "",
        "PATH_INFO": "/" + str((args or {}).get("__ow_path", "")).lstrip("/"),
        "QUERY_STRING": str((args or {}).get("__ow_query", "")),
        "SERVER_NAME": str(headers.get("host", "localhost")),
        "SERVER_PORT": "443",
        "SERVER_PROTOCOL": "HTTP/1.1",
        "CONTENT_LENGTH": str(len(body)),
        "wsgi.version": (1, 0),
        "wsgi.url_scheme": "https",
        "wsgi.input": io.BytesIO(body),
        "wsgi.errors": io.StringIO(),
        "wsgi.multithread": False,
        "wsgi.multiprocess": False,
        "wsgi.run_once": False,
    }

    content_type = headers.get("content-type") or headers.get("Content-Type")
    if content_type:
        environ["CONTENT_TYPE"] = content_type
    for key, value in headers.items():
        name = str(key).upper().replace("-", "_")
        if name not in ("CONTENT_TYPE", "CONTENT_LENGTH"):
            environ["HTTP_" + name] = value

    return environ


def _dply_report(args, status, duration_ms):
    try:
        headers = (args or {}).get("__ow_headers") or {}
        if headers.get("x-dply-run") or headers.get("x-dply-source"):
            return
        endpoint = os.environ.get("DPLY_LOG_INGEST_URL", "")
        secret = os.environ.get("DPLY_LOG_INGEST_SECRET", "")
        if not endpoint or not secret:
            return
        host = urlparse(endpoint).hostname or ""
        if host in ("", "localhost", "127.0.0.1"):
            return
        payload = json.dumps({
            "method": str((args or {}).get("__ow_method", "GET")).upper(),
            "path": "/" + str((args or {}).get("__ow_path", "")).lstrip("/"),
            "status": status,
            "duration_ms": duration_ms,
            "logs": [],
            "context": {},
        }).encode("utf-8")
        signature = hmac.new(secret.encode("utf-8"), payload, hashlib.sha256).hexdigest()
        request = urllib.request.Request(
            endpoint,
            data=payload,
            method="POST",
            headers={"Content-Type": "application/json", "X-Dply-Signature": signature},
        )
        urllib.request.urlopen(request, timeout=0.8).close()
    except Exception:  # noqa: BLE001 — fire-and-forget
        pass


def _dply_cors_headers(policy, args):
    """Response headers for the CORS policy dply binds as a default parameter.

    An origin outside the policy gets no CORS headers at all — that IS the
    rejection; inventing a header would defeat the allow-list.
    """
    request_headers = (args or {}).get("__ow_headers") or {}
    origin = request_headers.get("origin") or request_headers.get("Origin") or ""
    allowed = policy.get("allow_origins") or ["*"]

    if "*" in allowed:
        # `*` cannot be combined with credentials, so echo the caller's
        # origin when credentials are in play.
        allow_origin = origin if (policy.get("allow_credentials") and origin) else "*"
    elif origin and origin in allowed:
        allow_origin = origin
    else:
        return {}

    headers = {"Access-Control-Allow-Origin": allow_origin}
    if allow_origin != "*":
        headers["Vary"] = "Origin"
    if policy.get("allow_methods"):
        headers["Access-Control-Allow-Methods"] = ", ".join(policy["allow_methods"])
    if policy.get("allow_headers"):
        headers["Access-Control-Allow-Headers"] = ", ".join(policy["allow_headers"])
    if policy.get("expose_headers"):
        headers["Access-Control-Expose-Headers"] = ", ".join(policy["expose_headers"])
    if policy.get("allow_credentials"):
        headers["Access-Control-Allow-Credentials"] = "true"
    if policy.get("max_age") is not None:
        headers["Access-Control-Max-Age"] = str(policy["max_age"])

    return headers


def dplyMain(args):
    args = args or {}

    policy = args.get("__dply_cors")
    policy = policy if isinstance(policy, dict) else None

    # With web-custom-options in force the platform stops answering preflight,
    # so the function has to — before the app, which may well 405 an OPTIONS
    # request to a route it never registered.
    if policy is not None and str(args.get("__ow_method", "GET")).upper() == "OPTIONS":
        _dply_report(args, 204, 0)
        return {"statusCode": 204, "headers": _dply_cors_headers(policy, args), "body": ""}

    start = time.time()
    captured = {}

    def start_response(status, response_headers, exc_info=None):
        captured["status"] = status
        captured["headers"] = response_headers
        return lambda _data: None

    thrown = None
    try:
        chunks = _wsgi_app(_build_environ(args), start_response)
        body = b"".join(c if isinstance(c, bytes) else str(c).encode("utf-8") for c in chunks)
        if hasattr(chunks, "close"):
            chunks.close()
        status_code = int(str(captured.get("status", "200")).split()[0])
        headers = {k: v for k, v in captured.get("headers", [])}
    except Exception as exc:  # noqa: BLE001
        thrown = exc
        status_code = 500
        body = str(exc).encode("utf-8")
        headers = {"content-type": "text/plain"}

    _dply_report(args, status_code, int((time.time() - start) * 1000))

    if thrown is not None:
        raise thrown

    # The app's own headers win — a view that sets its own CORS header has
    # made a deliberate choice the policy shouldn't overwrite.
    if policy is not None:
        merged = _dply_cors_headers(policy, args)
        merged.update(headers)
        headers = merged

    return {
        "statusCode": status_code,
        "headers": headers,
        "body": body.decode("utf-8", errors="replace"),
    }
