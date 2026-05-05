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
    }
    print(json.dumps(out))
    _post_metrics_callback(out)


if __name__ == "__main__":
    main()
