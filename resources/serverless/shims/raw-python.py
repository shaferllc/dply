"""dply logging shim for a raw OpenWhisk Python action.

Injected at deploy time by App\\Services\\Deploy\\ServerlessLoggingShimInjector.
Do not edit in the user's repo — dply overwrites this file on every deploy.

The DigitalOcean Functions activations list API is structurally empty, so an
un-wrapped raw action is invisible to dply. This shim wraps the repo's own
action and fire-and-forget POSTs each organic invocation to dply's ingest
endpoint, exactly as the Laravel adapter does for framework apps.
"""
import hashlib
import hmac
import importlib.util
import json
import os
import time
import urllib.request
from urllib.parse import urlparse

_spec = importlib.util.spec_from_file_location(
    "_dply_user_action",
    os.path.join(os.path.dirname(os.path.abspath(__file__)), "{{DPLY_ENTRY}}"),
)
_dply_user_action = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(_dply_user_action)
_dply_user_main = _dply_user_action.main


def _dply_report(args, status, duration_ms):
    try:
        headers = (args or {}).get("__ow_headers") or {}
        # dply-initiated invocations are already captured inline by the
        # caller — never double-report them.
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


def _dply_cors_policy(args):
    """The CORS policy dply binds as a default parameter.

    Absent means the platform is still answering preflight itself, so the
    shim stays out of the way entirely.
    """
    policy = (args or {}).get("__dply_cors")
    return policy if isinstance(policy, dict) else None


def _dply_cors_headers(policy, args):
    request_headers = (args or {}).get("__ow_headers") or {}
    origin = request_headers.get("origin") or request_headers.get("Origin") or ""
    allowed = policy.get("allow_origins") or ["*"]

    # An origin outside the policy gets no CORS headers at all — that IS the
    # rejection; inventing a header here would defeat the allow-list.
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

    policy = _dply_cors_policy(args)
    method = str(args.get("__ow_method", "GET")).upper()

    # With web-custom-options in force the platform stops answering
    # preflight, so the function has to — before the user's handler, which
    # knows nothing about CORS.
    if policy is not None and method == "OPTIONS":
        _dply_report(args, 204, 0)
        return {"statusCode": 204, "headers": _dply_cors_headers(policy, args), "body": ""}

    start = time.time()
    thrown = None
    try:
        result = _dply_user_main(args)
        status = result.get("statusCode", 200) if isinstance(result, dict) else 200
    except Exception as exc:  # noqa: BLE001
        thrown = exc
        status = 500
        result = {"statusCode": 500, "body": str(exc)}

    _dply_report(args, status, int((time.time() - start) * 1000))

    if thrown is not None:
        raise thrown

    # The handler's own headers win — a function that sets its own CORS
    # header has made a deliberate choice the policy shouldn't overwrite.
    if policy is not None and isinstance(result, dict):
        merged = _dply_cors_headers(policy, args)
        merged.update(result.get("headers") or {})
        result["headers"] = merged

    return result
