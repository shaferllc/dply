#!/usr/bin/env python3
"""
Dply guest metrics snapshot — prints one JSON object to stdout (no extra lines).

Installed under ~/.dply/bin/server-metrics-snapshot.py by the control plane; can also
be run manually or copied to other hosts with Python 3.

If ~/.dply/metrics-callback.env exists (or env vars), POSTs the same payload to the
Dply app at /api/metrics/guest-push so you can cron this script (e.g. every 5 minutes)
for continuous monitoring.
"""
from __future__ import annotations

import json
import os
import shutil
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path


def _jiffies() -> tuple[int, int]:
    with open("/proc/stat") as f:
        fields = f.readline().split()
    nums = [int(x) for x in fields[1:]]
    total = sum(nums)
    idle = nums[3] + nums[4]
    return total, idle


def _load_metrics_callback_env() -> dict[str, str]:
    p = Path.home() / ".dply" / "metrics-callback.env"
    if not p.is_file():
        return {}
    out: dict[str, str] = {}
    try:
        raw_text = p.read_text(encoding="utf-8", errors="replace")
    except OSError:
        return {}
    for raw in raw_text.splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        k, _, v = line.partition("=")
        k = k.strip()
        v = v.strip().strip('"').strip("'")
        if k:
            out[k] = v
    return out


def _post_metrics_callback(payload: dict) -> None:
    env = _load_metrics_callback_env()
    url = env.get("DPLY_METRICS_CALLBACK_URL") or os.environ.get("DPLY_METRICS_CALLBACK_URL")
    token = env.get("DPLY_METRICS_CALLBACK_TOKEN") or os.environ.get("DPLY_METRICS_CALLBACK_TOKEN")
    sid = env.get("DPLY_METRICS_SERVER_ID") or os.environ.get("DPLY_METRICS_SERVER_ID")
    if not url or not token or not sid:
        return
    captured = datetime.now(timezone.utc).replace(microsecond=0)
    iso = captured.isoformat().replace("+00:00", "Z")
    body = {
        "server_id": sid,
        "token": token,
        "metrics": payload,
        "captured_at": iso,
    }
    data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=data,
        headers={"Content-Type": "application/json", "Accept": "application/json"},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            if 200 <= resp.status < 300:
                return
    except (urllib.error.URLError, urllib.error.HTTPError, TimeoutError, OSError):
        return


def main() -> None:
    t1, i1 = _jiffies()
    time.sleep(0.25)
    t2, i2 = _jiffies()
    dt = t2 - t1
    di = i2 - i1
    cpu = round(100.0 * (1.0 - di / dt), 1) if dt > 0 else 0.0

    info: dict[str, int] = {}
    with open("/proc/meminfo") as f:
        for line in f:
            if ":" not in line:
                continue
            key, rest = line.split(":", 1)
            key = key.strip()
            parts = rest.split()
            if parts and parts[0].isdigit():
                info[key] = int(parts[0])

    mem_total_kb = info.get("MemTotal", 0)
    mem_avail_kb = info.get("MemAvailable", info.get("MemFree", 0))
    mem_pct = round(100.0 * (1.0 - mem_avail_kb / mem_total_kb), 1) if mem_total_kb else 0.0

    u = shutil.disk_usage("/")
    disk_pct = round(100.0 * u.used / u.total, 1) if u.total else 0.0

    with open("/proc/loadavg") as lf:
        parts = lf.read().split()
    load1, load5, load15 = float(parts[0]), float(parts[1]), float(parts[2])

    out = {
        "cpu_pct": cpu,
        "mem_pct": mem_pct,
        "disk_pct": disk_pct,
        "load_1m": load1,
        "load_5m": load5,
        "load_15m": load15,
        "mem_total_kb": mem_total_kb,
        "disk_total_bytes": u.total,
        "disk_used_bytes": u.used,
    }
    print(json.dumps(out))
    _post_metrics_callback(out)


if __name__ == "__main__":
    main()
