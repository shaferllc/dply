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


def dplyMain(args):
    args = args or {}
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
    return result
