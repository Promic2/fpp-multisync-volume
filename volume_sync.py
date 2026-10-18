#!/usr/bin/env python3
"""fpp-multisync-volume daemon.

Mirrors the FPP master's volume (the volume slider on the status page,
playlist "Volume Set" entries, presets, ...) to the FPP remotes it syncs with.
FPP keeps sequences and media in sync across MultiSync but not the volume, so
on a show where the audio comes out of a remote, the master's slider does
nothing audible.

Each remote gets its own MultiSync "Volume Set" command (multisyncHosts =
that remote), which leaves the master's own volume untouched and allows a
per-remote offset. Nothing is sent while the plugin is disabled.
"""
import json
import logging
import os
import signal
import sys
import time
import urllib.parse
import urllib.request

REPO = "fpp-multisync-volume"
API = "http://127.0.0.1/api"
POLL_S = 0.25          # how often the master volume is read
REFRESH_S = 5.0        # how often settings and the remote list are re-read
REASSERT_S = 60.0      # re-send even without a change (remote rebooted, ...)

logdir = os.environ.get("LOGDIR", "/home/fpp/media/logs")
logging.basicConfig(
    filename=os.path.join(logdir, f"plugin-{REPO}.log"),
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger(REPO)

running = True


def _stop(*_):
    global running
    running = False


signal.signal(signal.SIGTERM, _stop)
signal.signal(signal.SIGINT, _stop)


def http_get(path, timeout=2):
    with urllib.request.urlopen(f"{API}{path}", timeout=timeout) as r:
        return r.read().decode()


def get_setting(key, default=""):
    try:
        data = json.loads(http_get(f"/plugin/{REPO}/settings/{urllib.parse.quote(key)}"))
        value = data.get(key)
        return default if value in (None, "") else str(value)
    except Exception:
        return default


def parse_remote_config(text):
    """'192.168.10.14=-10,192.168.10.20=0' -> {'192.168.10.14': -10, ...}"""
    result = {}
    for part in (text or "").split(","):
        part = part.strip()
        if not part:
            continue
        ip, _, offset = part.partition("=")
        try:
            result[ip.strip()] = int(offset or 0)
        except ValueError:
            result[ip.strip()] = 0
    return result


def discover_remotes():
    """IPv4 addresses of systems in remote mode that the master sees."""
    data = json.loads(http_get("/fppd/multiSyncSystems", timeout=3))
    found = []
    for s in data.get("systems", []):
        addr = s.get("address", "")
        if s.get("fppModeString") == "remote" and addr and ":" not in addr and addr not in found:
            found.append(addr)
    return found


def send_volume(ip, volume):
    body = json.dumps({
        "command": "Volume Set",
        "args": [str(volume)],
        "multisyncCommand": True,
        "multisyncHosts": ip,
    }).encode()
    req = urllib.request.Request(f"{API}/command", data=body, method="POST",
                                 headers={"Content-Type": "application/json"})
    with urllib.request.urlopen(req, timeout=2) as r:
        r.read()


def main():
    log.info("started")
    enabled = False
    targets = {}            # ip -> offset
    sent = {}               # ip -> last volume sent
    last_refresh = 0.0
    last_reassert = 0.0

    while running:
        now = time.monotonic()
        try:
            if now - last_refresh >= REFRESH_S:
                last_refresh = now
                was_enabled = enabled
                enabled = get_setting("Enabled", "0") == "1"
                if enabled != was_enabled:
                    log.info("enabled" if enabled else "disabled")
                    sent.clear()
                if enabled:
                    offsets = parse_remote_config(get_setting("Remotes", ""))
                    if get_setting("TargetMode", "all") == "selected":
                        ips = [ip.strip() for ip in get_setting("Selected", "").split(",") if ip.strip()]
                    else:
                        ips = discover_remotes()
                    new_targets = {ip: offsets.get(ip, 0) for ip in ips}
                    if new_targets != targets:
                        log.info("targets: %s", ", ".join(f"{ip} ({off:+d})" for ip, off in new_targets.items()) or "none")
                        targets = new_targets
                        sent = {ip: v for ip, v in sent.items() if ip in targets}

            if enabled and targets:
                master = int(http_get("/fppd/volume?simple=true").strip())
                reassert = now - last_reassert >= REASSERT_S
                if reassert:
                    last_reassert = now
                for ip, offset in targets.items():
                    volume = max(0, min(100, master + offset))
                    if reassert or sent.get(ip) != volume:
                        send_volume(ip, volume)
                        if sent.get(ip) != volume:
                            log.info("volume %d -> %s", volume, ip)
                        sent[ip] = volume
        except Exception as e:  # fppd restarting, remote list not ready, ...
            log.debug("waiting: %s", e)
            sent.clear()
            time.sleep(2)
        time.sleep(POLL_S)

    log.info("stopped")


if __name__ == "__main__":
    sys.exit(main())
