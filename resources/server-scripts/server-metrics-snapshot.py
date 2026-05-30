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
import subprocess
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


def _stable_skip_threshold() -> float:
    """Per-metric absolute %-point delta below which we consider the sample 'unchanged'."""
    raw = os.environ.get("DPLY_METRICS_STABLE_THRESHOLD_PCT", "2.0")
    try:
        return max(0.0, float(raw))
    except ValueError:
        return 2.0


def _stable_max_skip() -> int:
    """Max consecutive 1-minute ticks to skip when stable. 4 = push at least once per 5 min."""
    raw = os.environ.get("DPLY_METRICS_STABLE_MAX_SKIP", "4")
    try:
        return max(0, int(raw))
    except ValueError:
        return 4


def _should_push_throttled(payload: dict) -> bool:
    """
    Adaptive cadence: cron fires every minute, but skip the POST when nothing
    interesting has changed. Push when:
      - first run (no state)
      - any of cpu_pct / mem_pct / disk_pct moved more than the threshold
      - we've already skipped DPLY_METRICS_STABLE_MAX_SKIP times in a row
        (heartbeat so charts stay continuous on quiet boxes)
    State lives in ~/.dply/metrics-throttle.json next to the netstate file.
    """
    state_path = Path.home() / ".dply" / "metrics-throttle.json"
    threshold = _stable_skip_threshold()
    max_skip = _stable_max_skip()

    previous: dict[str, float | int] | None = None
    try:
        if state_path.is_file():
            previous = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError):
        previous = None

    skip_count = 0
    must_push = True
    if isinstance(previous, dict):
        try:
            skip_count = int(previous.get("skip_count", 0))
        except (TypeError, ValueError):
            skip_count = 0
        try:
            prev_cpu = float(previous.get("cpu_pct", -1))
            prev_mem = float(previous.get("mem_pct", -1))
            prev_disk = float(previous.get("disk_pct", -1))
        except (TypeError, ValueError):
            prev_cpu = prev_mem = prev_disk = -1.0
        if prev_cpu >= 0 and prev_mem >= 0 and prev_disk >= 0:
            cur_cpu = float(payload.get("cpu_pct", 0) or 0)
            cur_mem = float(payload.get("mem_pct", 0) or 0)
            cur_disk = float(payload.get("disk_pct", 0) or 0)
            stable = (
                abs(cur_cpu - prev_cpu) <= threshold
                and abs(cur_mem - prev_mem) <= threshold
                and abs(cur_disk - prev_disk) <= threshold
            )
            must_push = (not stable) or (skip_count >= max_skip)

    if must_push:
        new_state = {
            "cpu_pct": float(payload.get("cpu_pct", 0) or 0),
            "mem_pct": float(payload.get("mem_pct", 0) or 0),
            "disk_pct": float(payload.get("disk_pct", 0) or 0),
            "skip_count": 0,
        }
    else:
        # Preserve last-pushed values; just bump the skip counter.
        new_state = {
            "cpu_pct": float(previous.get("cpu_pct", 0)) if isinstance(previous, dict) else 0.0,
            "mem_pct": float(previous.get("mem_pct", 0)) if isinstance(previous, dict) else 0.0,
            "disk_pct": float(previous.get("disk_pct", 0)) if isinstance(previous, dict) else 0.0,
            "skip_count": skip_count + 1,
        }

    try:
        state_path.parent.mkdir(parents=True, exist_ok=True)
        state_path.write_text(json.dumps(new_state), encoding="utf-8")
    except OSError:
        pass

    return must_push


def _collect_scheduler_heartbeats() -> list[dict]:
    """Collect dply-scheduler-tick sidecars + pair each with its cron expression.

    Reads every `*.json` sidecar under `/var/lib/dply/scheduler-heartbeats/`
    (configurable via DPLY_SCHEDULER_HEARTBEAT_DIR). Parses the current user's
    crontab to map (site_id, scheduler_kind) → cron expression so the ingest
    endpoint can store the cadence without dply having to look it up.

    Best-effort: any heartbeat we can't parse is skipped silently — never
    fails the whole metrics push.
    """
    heartbeat_dir = Path(os.environ.get("DPLY_SCHEDULER_HEARTBEAT_DIR", "/var/lib/dply/scheduler-heartbeats"))
    if not heartbeat_dir.is_dir():
        return []

    cron_map = _scheduler_cron_expressions()
    out: list[dict] = []

    for path in sorted(heartbeat_dir.glob("*.json")):
        try:
            sidecar = json.loads(path.read_text(encoding="utf-8"))
        except (OSError, ValueError):
            continue
        if not isinstance(sidecar, dict):
            continue

        site_id = sidecar.get("site_id")
        kind = sidecar.get("scheduler_kind")
        if not isinstance(site_id, str) or not isinstance(kind, str):
            continue

        cron_expression = cron_map.get((site_id, kind), "")
        if not cron_expression:
            # No cron line in this user's crontab references the sidecar's
            # (site_id, kind). Skip — the ingest endpoint requires a non-empty
            # cron expression, and shipping a stale sidecar with no cadence
            # would just produce ingest noise.
            continue

        out.append({
            "v": sidecar.get("v", 1),
            "site_id": site_id,
            "scheduler_kind": kind,
            "cron_expression": cron_expression,
            "last_tick_at": sidecar.get("finished_at"),
            "exit_code": sidecar.get("exit_code"),
            "duration_ms": sidecar.get("duration_ms"),
            "memory_peak_kb": sidecar.get("memory_peak_kb"),
            "circuit_open": bool(sidecar.get("circuit_open", False)),
        })

    return out


def _scheduler_cron_expressions() -> dict[tuple[str, str], str]:
    """Return {(site_id, kind): cron_expression} parsed from `crontab -l`.

    Wrapper invocation pattern installed by dply:
        <cron-expr...> /usr/local/bin/dply-scheduler-tick <site-id> <kind> -- <cmd>
    Empty / missing crontab returns an empty map (no warning — operators
    without a scheduler have no entries, that's fine).
    """
    try:
        result = subprocess.run(
            ["crontab", "-l"],
            capture_output=True,
            text=True,
            timeout=5,
        )
    except (FileNotFoundError, subprocess.TimeoutExpired, OSError):
        return {}
    if result.returncode != 0:
        return {}

    out: dict[tuple[str, str], str] = {}
    for line in result.stdout.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        if "dply-scheduler-tick" not in line:
            continue

        # Split into [cron-expression-tokens..., wrapper-path, site-id, kind, --, rest...]
        parts = line.split()
        # Cron expressions are 5 fields (standard) or 6 (with year). Find the
        # `dply-scheduler-tick` token to know where the cron expression ends.
        try:
            wrapper_idx = next(
                i for i, tok in enumerate(parts)
                if tok.endswith("/dply-scheduler-tick") or tok == "dply-scheduler-tick"
            )
        except StopIteration:
            continue
        if wrapper_idx + 3 >= len(parts):
            continue

        cron_expr = " ".join(parts[:wrapper_idx])
        site_id = parts[wrapper_idx + 1]
        kind = parts[wrapper_idx + 2]
        if not cron_expr or not site_id or not kind:
            continue
        out[(site_id, kind)] = cron_expr

    return out


def _post_metrics_callback(payload: dict) -> None:
    env = _load_metrics_callback_env()
    url = env.get("DPLY_METRICS_CALLBACK_URL") or os.environ.get("DPLY_METRICS_CALLBACK_URL")
    token = env.get("DPLY_METRICS_CALLBACK_TOKEN") or os.environ.get("DPLY_METRICS_CALLBACK_TOKEN")
    sid = env.get("DPLY_METRICS_SERVER_ID") or os.environ.get("DPLY_METRICS_SERVER_ID")
    if not url or not token or not sid:
        return
    if not _should_push_throttled(payload):
        return
    captured = datetime.now(timezone.utc).replace(microsecond=0)
    iso = captured.isoformat().replace("+00:00", "Z")
    body = {
        "server_id": sid,
        "token": token,
        "metrics": payload,
        "captured_at": iso,
    }
    # Scheduler heartbeats ride alongside metrics — collected best-effort, no
    # exception escapes. Always include the key if any heartbeats exist so
    # the ingest endpoint can detect "no schedulers" as different from
    # "scheduler dir not yet present."
    try:
        heartbeats = _collect_scheduler_heartbeats()
    except Exception:  # noqa: BLE001 — defensive, must never break the metrics push
        heartbeats = []
    if heartbeats:
        body["scheduler_heartbeats"] = heartbeats
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


def _disk_io_totals() -> tuple[int, int]:
    """Sum read + write bytes across all real block devices.

    Reads /proc/diskstats. Skips loopbacks (loop*), ramdisks (ram*), and
    individual partitions (we use the parent disk like sda / nvme0n1 to
    avoid double-counting). The agent translates sectors to bytes assuming
    the standard 512-byte sector size — accurate to within a few percent
    for nearly all Linux filesystems and good enough for charting.
    """
    read_bytes = 0
    write_bytes = 0
    sector_size = 512
    try:
        with open("/proc/diskstats") as f:
            lines = f.readlines()
    except OSError:
        return 0, 0
    for raw in lines:
        parts = raw.split()
        if len(parts) < 14:
            continue
        name = parts[2]
        # Skip loop / ram / per-partition (e.g. sda1, nvme0n1p1).
        if name.startswith(("loop", "ram", "fd", "dm-")):
            continue
        if any(ch.isdigit() for ch in name) and name[-1].isdigit() and not name.startswith("nvme"):
            # Partitions like sda1, vda2 — skip.
            continue
        # Crude nvme handling: nvme0n1 (full device, keep), nvme0n1p1 (partition, skip).
        if "p" in name and name.startswith("nvme"):
            tail = name.split("p")[-1]
            if tail.isdigit():
                continue
        try:
            sectors_read = int(parts[5])
            sectors_written = int(parts[9])
        except (ValueError, IndexError):
            continue
        read_bytes += sectors_read * sector_size
        write_bytes += sectors_written * sector_size
    return read_bytes, write_bytes


def _disk_io_rates(now_ts: float) -> tuple[float | None, float | None]:
    """Bytes/sec read + written since the previous sample."""
    state_path = Path.home() / ".dply" / "metrics-io-state.json"
    read_total, write_total = _disk_io_totals()

    previous: dict[str, float | int] | None = None
    try:
        if state_path.is_file():
            previous = json.loads(state_path.read_text(encoding="utf-8"))
    except (OSError, ValueError, TypeError):
        previous = None

    try:
        state_path.parent.mkdir(parents=True, exist_ok=True)
        state_path.write_text(
            json.dumps({"captured_at": now_ts, "read_total": read_total, "write_total": write_total}),
            encoding="utf-8",
        )
    except OSError:
        pass

    if not previous:
        return None, None

    prev_ts = float(previous.get("captured_at", 0))
    prev_read = int(previous.get("read_total", 0))
    prev_write = int(previous.get("write_total", 0))
    elapsed = now_ts - prev_ts
    if elapsed <= 0:
        return None, None

    read_rate = max(0.0, (read_total - prev_read) / elapsed)
    write_rate = max(0.0, (write_total - prev_write) / elapsed)
    return round(read_rate, 1), round(write_rate, 1)


def _per_disk_usage() -> list[dict]:
    """List of mount points with usage. Filtered to real local filesystems."""
    real_fs = {"ext2", "ext3", "ext4", "xfs", "btrfs", "zfs", "f2fs", "reiserfs", "jfs"}
    seen: set[str] = set()
    out: list[dict] = []
    try:
        with open("/proc/mounts") as f:
            mounts = f.readlines()
    except OSError:
        return out
    for raw in mounts:
        parts = raw.split()
        if len(parts) < 3:
            continue
        device, mount_point, fs_type = parts[0], parts[1], parts[2]
        if fs_type not in real_fs:
            continue
        if mount_point in seen:
            continue
        # Skip snap mounts and docker overlay-style internals.
        if mount_point.startswith(("/snap", "/var/lib/docker", "/var/lib/containerd")):
            continue
        seen.add(mount_point)
        try:
            u = shutil.disk_usage(mount_point)
        except OSError:
            continue
        if u.total <= 0:
            continue
        out.append({
            "mount": mount_point,
            "device": device,
            "fs_type": fs_type,
            "total_bytes": u.total,
            "used_bytes": u.used,
            "free_bytes": u.free,
            "pct": round(100.0 * u.used / u.total, 1),
        })
    # Stable order by mount path; cap at 12 for payload size.
    out.sort(key=lambda d: d["mount"])
    return out[:12]


def _top_processes(limit: int = 5) -> dict[str, list[dict]]:
    """Top N processes by CPU% and by RAM% via `ps`. Falls back to empty lists if ps fails."""
    by_cpu: list[dict] = []
    by_mem: list[dict] = []
    cmd_base = ["ps", "-eo", "pid,user,comm,pcpu,pmem", "--no-headers"]
    try:
        # CPU sort
        cpu_proc = subprocess.run(
            cmd_base + ["--sort=-pcpu"],
            capture_output=True,
            text=True,
            timeout=4,
            check=False,
        )
        if cpu_proc.returncode == 0:
            for line in cpu_proc.stdout.splitlines()[:limit]:
                row = _parse_ps_line(line)
                if row is not None:
                    by_cpu.append(row)
        # Memory sort
        mem_proc = subprocess.run(
            cmd_base + ["--sort=-pmem"],
            capture_output=True,
            text=True,
            timeout=4,
            check=False,
        )
        if mem_proc.returncode == 0:
            for line in mem_proc.stdout.splitlines()[:limit]:
                row = _parse_ps_line(line)
                if row is not None:
                    by_mem.append(row)
    except (subprocess.SubprocessError, FileNotFoundError, OSError):
        pass
    return {"by_cpu": by_cpu, "by_mem": by_mem}


def _parse_ps_line(line: str) -> dict | None:
    parts = line.split(None, 4)
    if len(parts) < 5:
        return None
    pid_s, user, comm, cpu_s, mem_s = parts
    try:
        pid = int(pid_s)
        cpu = float(cpu_s)
        mem = float(mem_s)
    except ValueError:
        return None
    return {
        "pid": pid,
        "user": user[:32],
        "command": comm[:64],
        "cpu_pct": round(cpu, 1),
        "mem_pct": round(mem, 1),
    }


# ---------------------------------------------------------------------------
# Webserver / edge-proxy health collectors
# ---------------------------------------------------------------------------
#
# Each collector returns a normalized health block for a single engine, or
# None when the engine isn't running / isn't reachable. Output shape:
#
#   {
#     "engine": "<engine-key>",
#     "active_connections": <int>,        # current concurrent connections
#     "requests_total": <int|null>,       # cumulative since daemon start
#     "requests_per_sec": <float|null>,   # rate (if exposed natively)
#     "errors_5xx_total": <int|null>,     # cumulative; rate is derived later
#     "uptime_sec": <int|null>,           # daemon uptime (best-effort)
#     "backends": [...optional, edge proxies only]
#   }
#
# All values are best-effort; missing fields are simply omitted. The dply
# server-side computes deltas across snapshots to derive 5xx-rate, etc.
# Scrapes never raise — every collector wraps its body in try/except and
# returns None on any error.


def _http_get(url: str, timeout: float = 2.5) -> str | None:
    """Tiny stdlib HTTP GET. Returns the body as text, or None on any error."""
    try:
        req = urllib.request.Request(url, headers={"User-Agent": "dply-metrics/1"})
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            return resp.read().decode("utf-8", errors="replace")
    except (urllib.error.URLError, OSError, ValueError):
        return None


def _systemctl_active(unit: str) -> bool:
    try:
        rc = subprocess.run(
            ["systemctl", "is-active", "--quiet", unit],
            check=False,
            timeout=2.0,
        ).returncode
        return rc == 0
    except (subprocess.SubprocessError, FileNotFoundError):
        return False


def _collect_nginx() -> dict | None:
    # dply writes a localhost-only stub_status block on :9091.
    # Format (plain text):
    #   Active connections: 12
    #   server accepts handled requests
    #    30 30 100
    #   Reading: 1 Writing: 2 Waiting: 9
    if not _systemctl_active("nginx"):
        return None
    body = _http_get("http://127.0.0.1:9091/nginx_status")
    if not body:
        return None
    try:
        lines = body.strip().splitlines()
        active = int(lines[0].split(":")[1].strip())
        accepts, handled, requests = (int(x) for x in lines[2].split())
        last = lines[3].split()
        reading = int(last[1])
        writing = int(last[3])
        waiting = int(last[5])
        return {
            "engine": "nginx",
            "active_connections": active,
            "requests_total": requests,
            "accepts_total": accepts,
            "handled_total": handled,
            "reading": reading,
            "writing": writing,
            "waiting": waiting,
        }
    except (IndexError, ValueError):
        return None


def _collect_apache() -> dict | None:
    # mod_status ?auto endpoint on :9092 (dply-managed).
    # Plain key:value text. Keys of interest: BusyWorkers, IdleWorkers,
    # Total Accesses, Total kBytes, Uptime, ReqPerSec.
    if not _systemctl_active("apache2"):
        return None
    body = _http_get("http://127.0.0.1:9092/server-status?auto")
    if not body:
        return None
    kv: dict[str, str] = {}
    for line in body.splitlines():
        if ":" not in line:
            continue
        k, _, v = line.partition(":")
        kv[k.strip()] = v.strip()
    try:
        return {
            "engine": "apache",
            "active_connections": int(kv.get("BusyWorkers", "0")),
            "idle_workers": int(kv.get("IdleWorkers", "0")),
            "requests_total": int(kv.get("Total Accesses", "0")),
            "requests_per_sec": float(kv.get("ReqPerSec", "0")) if "ReqPerSec" in kv else None,
            "uptime_sec": int(kv.get("Uptime", "0")) if "Uptime" in kv else None,
        }
    except ValueError:
        return None


def _caddy_admin_metrics_url() -> str | None:
    """Resolve Caddy admin /metrics URL from the global Caddyfile when possible."""
    default = "http://127.0.0.1:2019/metrics"
    try:
        with open("/etc/caddy/Caddyfile", encoding="utf-8", errors="replace") as handle:
            for raw in handle:
                line = raw.strip()
                if not line.startswith("admin "):
                    continue
                parts = line.split()
                listen = parts[1] if len(parts) > 1 else "localhost:2019"
                if listen.lower() == "off":
                    return None
                host, _, port = listen.partition(":")
                if host in ("localhost", "::1"):
                    host = "127.0.0.1"
                if not port:
                    port = "2019"
                return f"http://{host}:{port}/metrics"
    except OSError:
        pass
    return default


def _collect_caddy() -> dict | None:
    # Caddy admin API exposes Prometheus exposition at /metrics.
    if not _systemctl_active("caddy"):
        return None
    metrics_url = _caddy_admin_metrics_url()
    if metrics_url is None:
        return None
    body = _http_get(metrics_url)
    if not body or ("caddy_" not in body and "# HELP caddy" not in body):
        return None
    requests_total = 0
    errors_5xx_total = 0
    in_flight = 0
    for line in body.splitlines():
        if line.startswith("#") or not line.strip():
            continue
        if line.startswith("caddy_http_requests_total"):
            try:
                value = float(line.rsplit(" ", 1)[1])
            except (IndexError, ValueError):
                continue
            requests_total += int(value)
            if 'code="5' in line:
                errors_5xx_total += int(value)
        elif line.startswith("caddy_http_requests_in_flight"):
            try:
                in_flight = max(in_flight, int(float(line.rsplit(" ", 1)[1])))
            except (IndexError, ValueError):
                continue
    return {
        "engine": "caddy",
        "active_connections": in_flight,
        "requests_total": requests_total,
        "errors_5xx_total": errors_5xx_total,
    }


def _collect_openlitespeed() -> dict | None:
    # OLS writes real-time stats to /tmp/lshttpd/.rtreport{,.1,.2,...}.
    # Format is plain key: value lines. One file per worker; sum across.
    if not _systemctl_active("lshttpd"):
        return None
    paths = [p for p in Path("/tmp/lshttpd").glob(".rtreport*") if p.is_file()] if Path("/tmp/lshttpd").exists() else []
    if not paths:
        return None
    total_plain = 0
    total_ssl = 0
    total_reqs = 0
    req_per_sec = 0.0
    uptime_sec = None
    for path in paths:
        try:
            content = path.read_text(errors="replace")
        except OSError:
            continue
        for line in content.splitlines():
            # Lines like:
            #   PLAINCONN: 0, AVAILCONN: 10000, IDLECONN: 0, SSLCONN: 0
            #   REQ_RATE []: REQ_PROCESSING: 0, REQ_PER_SEC: 0.0, TOT_REQS: 0
            for chunk in line.replace(",", " ").split():
                if ":" not in chunk:
                    continue
                k, _, v = chunk.partition(":")
                k = k.strip().rstrip("[]")
                v = v.strip()
                if not v:
                    continue
                try:
                    if k == "PLAINCONN":
                        total_plain += int(v)
                    elif k == "SSLCONN":
                        total_ssl += int(v)
                    elif k == "TOT_REQS":
                        total_reqs += int(v)
                    elif k == "REQ_PER_SEC":
                        req_per_sec += float(v)
                    elif k == "UPTIME" and uptime_sec is None:
                        # OLS UPTIME is like "12s" or "1h:23m" — parse loosely.
                        uptime_sec = _parse_ols_uptime(v)
                except ValueError:
                    continue
    return {
        "engine": "openlitespeed",
        "active_connections": total_plain + total_ssl,
        "plain_connections": total_plain,
        "ssl_connections": total_ssl,
        "requests_total": total_reqs,
        "requests_per_sec": round(req_per_sec, 2),
        "uptime_sec": uptime_sec,
    }


def _parse_ols_uptime(value: str) -> int | None:
    # OLS UPTIME formats observed: "12s", "1h:23m:45s", "2d:3h:4m:5s".
    total = 0
    units = {"s": 1, "m": 60, "h": 3600, "d": 86400}
    for piece in value.replace(":", " ").split():
        if not piece or piece[-1] not in units:
            try:
                return int(piece)  # bare seconds
            except ValueError:
                return None
        try:
            total += int(piece[:-1]) * units[piece[-1]]
        except ValueError:
            return None
    return total or None


def _collect_traefik() -> dict | None:
    # Traefik's prometheus metrics endpoint, bound by dply on 127.0.0.1:9093.
    if not _systemctl_active("traefik"):
        return None
    body = _http_get("http://127.0.0.1:9093/metrics")
    if not body:
        return None
    requests_total = 0
    errors_5xx_total = 0
    open_conns = 0
    saw = False
    for line in body.splitlines():
        if line.startswith("#") or not line.strip():
            continue
        # traefik_service_requests_total{code="200",...} 42
        if line.startswith("traefik_service_requests_total") or line.startswith("traefik_entrypoint_requests_total"):
            saw = True
            try:
                value = float(line.rsplit(" ", 1)[1])
            except (IndexError, ValueError):
                continue
            requests_total += int(value)
            if 'code="5' in line:
                errors_5xx_total += int(value)
        elif line.startswith("traefik_entrypoint_open_connections"):
            try:
                open_conns += int(float(line.rsplit(" ", 1)[1]))
            except (IndexError, ValueError):
                pass
    if not saw:
        return None
    return {
        "engine": "traefik",
        "active_connections": open_conns,
        "requests_total": requests_total,
        "errors_5xx_total": errors_5xx_total,
    }


def _collect_haproxy() -> dict | None:
    # HAProxy stats socket. The dply haproxy.cfg writes it to
    # /run/haproxy/admin.sock; "show stat" returns CSV.
    if not _systemctl_active("haproxy"):
        return None
    sock_path = "/run/haproxy/admin.sock"
    if not Path(sock_path).exists():
        return None
    try:
        result = subprocess.run(
            ["socat", "-", f"UNIX-CONNECT:{sock_path}"],
            input="show stat\n",
            capture_output=True,
            text=True,
            timeout=3.0,
            check=False,
        )
    except (subprocess.SubprocessError, FileNotFoundError):
        return None
    if result.returncode != 0 or not result.stdout:
        return None

    lines = result.stdout.strip().splitlines()
    if not lines:
        return None
    # First line is a CSV header starting with `# pxname,svname,...`.
    header = [c.strip().lstrip("#").strip() for c in lines[0].split(",")]
    try:
        idx_pxname = header.index("pxname")
        idx_svname = header.index("svname")
        idx_status = header.index("status")
        idx_scur = header.index("scur")
        idx_stot = header.index("stot")
        idx_hrsp_5xx = header.index("hrsp_5xx")
    except ValueError:
        return None
    active = 0
    requests_total = 0
    errors_5xx_total = 0
    backends: list[dict] = []
    for raw in lines[1:]:
        cells = raw.split(",")
        if len(cells) <= max(idx_pxname, idx_svname, idx_status, idx_scur, idx_stot):
            continue
        sv = cells[idx_svname]
        if sv == "FRONTEND":
            try:
                active += int(cells[idx_scur] or 0)
                requests_total += int(cells[idx_stot] or 0)
                errors_5xx_total += int(cells[idx_hrsp_5xx] or 0)
            except ValueError:
                pass
        elif sv not in ("BACKEND", ""):
            # Per-server backend row.
            backends.append({
                "backend": cells[idx_pxname],
                "name": sv,
                "status": cells[idx_status],
            })
    return {
        "engine": "haproxy",
        "active_connections": active,
        "requests_total": requests_total,
        "errors_5xx_total": errors_5xx_total,
        "backends": backends,
    }


def _collect_webserver_health() -> list[dict]:
    """Run all per-engine collectors. Returns a list of health blocks for
    every engine that's running + reachable. dply server side reads the
    whole list and renders one chart panel per engine on the workspace."""
    out: list[dict] = []
    for fn in (_collect_nginx, _collect_apache, _collect_caddy,
               _collect_openlitespeed, _collect_traefik, _collect_haproxy):
        try:
            block = fn()
        except Exception:
            block = None
        if block is not None:
            out.append(block)
    return out


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
    io_read_bps, io_write_bps = _disk_io_rates(now_ts)
    disks = _per_disk_usage()
    top_procs = _top_processes()

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
        "io_read_bps": io_read_bps,
        "io_write_bps": io_write_bps,
        "disks": disks,
        "top_cpu": top_procs.get("by_cpu", []),
        "top_mem": top_procs.get("by_mem", []),
        # Per-engine health blocks for whichever webservers / edge proxies
        # are running and have stats endpoints reachable. The dply server
        # iterates this list to render the per-engine Overview charts.
        # Empty list when no engines are running or no scrapes succeed.
        "webserver_health": _collect_webserver_health(),
    }
    print(json.dumps(out))
    _post_metrics_callback(out)


if __name__ == "__main__":
    main()
