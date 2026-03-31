#!/usr/bin/env python3
"""
Dply guest metrics snapshot — prints one JSON object to stdout (no extra lines).

Installed under ~/.dply/bin/server-metrics-snapshot.py by the control plane; can also
be run manually or copied to other hosts with Python 3.

If ~/.dply/metrics-callback.env exists (or env vars), POSTs the same payload to the
Dply app at /api/metrics so you can cron this script (e.g. every 5 minutes)
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


def _network_totals() -> tuple[int, int]:
    rx_total = 0
    tx_total = 0
    with open("/proc/net/dev") as f:
        for line in f:
            if ":" not in line:
                continue
            iface, stats = line.split(":", 1)
            name = iface.strip()
            if name == "lo":
                continue
            fields = stats.split()
            if len(fields) < 16:
                continue
            rx_total += int(fields[0])
            tx_total += int(fields[8])
    return rx_total, tx_total


def _network_rates(now_ts: float) -> tuple[float | None, float | None]:
    state_path = Path.home() / ".dply" / "metrics-net-state.json"
    rx_total, tx_total = _network_totals()

    previous: dict[str, float | int] | None = None
    try:
        if state_path.is_file():
            previous = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError):
        previous = None

    try:
        state_path.parent.mkdir(parents=True, exist_ok=True)
        state_path.write_text(
            json.dumps({"captured_at": now_ts, "rx_total": rx_total, "tx_total": tx_total}),
            encoding="utf-8",
        )
    except OSError:
        pass

    if not previous:
        return None, None

    prev_ts = float(previous.get("captured_at", 0))
    prev_rx = int(previous.get("rx_total", 0))
    prev_tx = int(previous.get("tx_total", 0))
    elapsed = now_ts - prev_ts
    if elapsed <= 0:
        return None, None

    rx_rate = max(0.0, (rx_total - prev_rx) / elapsed)
    tx_rate = max(0.0, (tx_total - prev_tx) / elapsed)
    return round(rx_rate, 1), round(tx_rate, 1)


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
    swap_total_kb = info.get("SwapTotal", 0)
    swap_free_kb = info.get("SwapFree", 0)
    swap_used_kb = max(0, swap_total_kb - swap_free_kb)

    u = shutil.disk_usage("/")
    disk_pct = round(100.0 * u.used / u.total, 1) if u.total else 0.0
    statvfs = os.statvfs("/")
    inode_total = statvfs.f_files
    inode_free = statvfs.f_ffree
    inode_used = max(0, inode_total - inode_free)
    inode_pct_root = round(100.0 * inode_used / inode_total, 1) if inode_total else 0.0

    with open("/proc/loadavg") as lf:
        parts = lf.read().split()
    load1, load5, load15 = float(parts[0]), float(parts[1]), float(parts[2])
    cpu_count = os.cpu_count() or 1
    load_per_cpu_1m = round(load1 / cpu_count, 3) if cpu_count > 0 else None
    with open("/proc/uptime") as uf:
        uptime_seconds = int(float(uf.read().split()[0]))
    now_ts = time.time()
    rx_rate, tx_rate = _network_rates(now_ts)

    out = {
        "cpu_pct": cpu,
        "mem_pct": mem_pct,
        "disk_pct": disk_pct,
        "load_1m": load1,
        "load_5m": load5,
        "load_15m": load15,
        "mem_total_kb": mem_total_kb,
        "mem_available_kb": mem_avail_kb,
        "swap_total_kb": swap_total_kb,
        "swap_used_kb": swap_used_kb,
        "disk_total_bytes": u.total,
        "disk_used_bytes": u.used,
        "disk_free_bytes": u.free,
        "inode_pct_root": inode_pct_root,
        "cpu_count": cpu_count,
        "load_per_cpu_1m": load_per_cpu_1m,
        "uptime_seconds": uptime_seconds,
        "rx_bytes_per_sec": rx_rate,
        "tx_bytes_per_sec": tx_rate,
    }
    print(json.dumps(out))
    _post_metrics_callback(out)


if __name__ == "__main__":
    main()
