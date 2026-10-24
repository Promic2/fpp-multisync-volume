#!/usr/bin/env python3
"""fpp-multisync-volume daemon.

Mirrors the FPP master's volume (the volume slider on the status page,
playlist "Volume Set" entries, presets, ...) to the FPP remotes it syncs with.
FPP keeps sequences and media in sync across MultiSync but not the volume, so
on a show where the audio comes out of a remote, the master's slider does
nothing audible.

Each remote gets its own MultiSync "Volume Set" command (multisyncHosts =
that remote), which leaves the master's own volume untouched and allows a
per-remote offset. Nothing is sent while the plugin is disabled, and nothing
is sent at all unless this FPP is in player mode: this box's own addresses are
never targets, and a remote must not push its volume to the rest of the show.

This plugin's own settings are read straight from its config file and only
re-read when that file changes, so nothing here goes through PHP. Everything
that belongs to FPP, this box's mode included, comes from FPP's API. The
volume poll slows down once the volume has been steady for a while.
"""
import json
import logging
import os
import signal
import sys
import time
import urllib.request

REPO = "fpp-multisync-volume"
API = "http://127.0.0.1/api"
POLL_ACTIVE_S = 0.25      # volume poll right after a change
POLL_IDLE_S = 1.0         # volume poll once the volume has been steady
ACTIVE_WINDOW_S = 15.0    # how long a change keeps the fast poll
IDLE_S = 2.0              # loop interval while disabled or not on a player
CONFIG_CHECK_S = 2.0      # how often the config file's timestamp is looked at
SYSTEMS_REFRESH_S = 30.0  # how often the MultiSync system list is re-read
DEFAULT_RESEND_S = 60     # re-send without a change (remote rebooted, ...); 0 = off
PLAYER_MODES = ("player", "master")

logdir = os.environ.get("LOGDIR", "/home/fpp/media/logs")
cfgdir = os.environ.get("CFGDIR", "/home/fpp/media/config")
CONFIG_FILE = os.path.join(cfgdir, f"plugin.{REPO}")

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


def read_config():
    """This plugin's own settings: 'Key = "value"' (or 'Key=value') per line."""
    result = {}
    try:
        with open(CONFIG_FILE, "r") as f:
            for line in f:
                key, sep, value = line.partition("=")
                if not sep:
                    continue
                result[key.strip()] = value.strip().strip('"')
    except FileNotFoundError:
        pass
    except OSError as e:
        log.debug("reading %s: %s", CONFIG_FILE, e)
    return result


def config_stamp():
    """Cheap fingerprint of the config file, so it is only parsed when changed."""
    try:
        st = os.stat(CONFIG_FILE)
        return (st.st_mtime_ns, st.st_size)
    except OSError:
        return None


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


def fetch_systems():
    data = json.loads(http_get("/fppd/multiSyncSystems", timeout=3))
    return data.get("systems", []) or []


def local_info(systems):
    """(this FPP's mode, its own addresses), from the entries the MultiSync
    list marks as local. With no local entry the mode is unknown, and an
    unknown mode sends nothing."""
    addrs = set()
    mode = ""
    for s in systems:
        if s.get("local"):
            addr = s.get("address", "")
            if addr:
                addrs.add(addr)
            if not mode:
                mode = (s.get("fppModeString") or "").strip().lower()
    return mode, addrs


def remote_addresses(systems):
    """IPv4 addresses of systems in remote mode."""
    found = []
    for s in systems:
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
    target_mode = "all"
    selected = []
    offsets = {}
    resend_s = DEFAULT_RESEND_S
    targets = {}            # ip -> offset
    sent = {}               # ip -> last volume sent
    mode_ok = False
    mode_logged = None
    last_volume = None
    cfg_stamp = "unread"    # never equal to a real stamp, so the first pass reads
    refresh_targets = True
    now = time.monotonic()
    last_cfg_check = now - CONFIG_CHECK_S
    last_systems = now - SYSTEMS_REFRESH_S
    last_change = now
    last_reassert = now

    while running:
        now = time.monotonic()
        sleep_s = IDLE_S
        try:
            if now - last_cfg_check >= CONFIG_CHECK_S:
                last_cfg_check = now
                stamp = config_stamp()
                if stamp != cfg_stamp:
                    cfg_stamp = stamp
                    cfg = read_config()
                    was_enabled = enabled
                    enabled = cfg.get("Enabled", "0") == "1"
                    if enabled != was_enabled:
                        log.info("enabled" if enabled else "disabled")
                        sent.clear()
                    target_mode = cfg.get("TargetMode", "all") or "all"
                    selected = [ip.strip() for ip in cfg.get("Selected", "").split(",") if ip.strip()]
                    offsets = parse_remote_config(cfg.get("Remotes", ""))
                    try:
                        new_resend = max(0, int(cfg.get("ResendSeconds", DEFAULT_RESEND_S)))
                    except ValueError:
                        new_resend = DEFAULT_RESEND_S
                    if new_resend != resend_s:
                        log.info("re-send interval: %s", f"{new_resend} s" if new_resend else "off")
                        resend_s = new_resend
                    refresh_targets = True

            if enabled and (refresh_targets or now - last_systems >= SYSTEMS_REFRESH_S):
                last_systems = now
                systems = fetch_systems()
                mode, local_addrs = local_info(systems)
                mode_ok = mode in PLAYER_MODES
                if mode != mode_logged:
                    mode_logged = mode
                    if mode_ok:
                        log.info("fpp mode: %s", mode)
                    else:
                        log.warning("fpp mode is '%s': this plugin only sends from a "
                                    "player (the master), so nothing is sent",
                                    mode or "unknown")
                    sent.clear()
                if mode_ok:
                    ips = selected if target_mode == "selected" else remote_addresses(systems)
                    # Never target this box: a Volume Set addressed to our own
                    # address is applied locally and would move the master's volume.
                    new_targets = {ip: offsets.get(ip, 0) for ip in ips if ip not in local_addrs}
                else:
                    new_targets = {}
                refresh_targets = False
                if new_targets != targets:
                    log.info("targets: %s", ", ".join(f"{ip} ({off:+d})" for ip, off in new_targets.items()) or "none")
                    targets = new_targets
                    sent = {ip: v for ip, v in sent.items() if ip in targets}

            if enabled and mode_ok and targets:
                master = int(http_get("/fppd/volume?simple=true").strip())
                if master != last_volume:
                    last_volume = master
                    last_change = now
                reassert = resend_s > 0 and now - last_reassert >= resend_s
                if reassert:
                    last_reassert = now
                for ip, offset in targets.items():
                    volume = max(0, min(100, master + offset))
                    if reassert or sent.get(ip) != volume:
                        send_volume(ip, volume)
                        if sent.get(ip) != volume:
                            log.info("volume %d -> %s", volume, ip)
                        sent[ip] = volume
                sleep_s = POLL_ACTIVE_S if now - last_change < ACTIVE_WINDOW_S else POLL_IDLE_S
        except Exception as e:  # fppd restarting, remote list not ready, ...
            log.debug("waiting: %s", e)
            sent.clear()
            # Retry the system list shortly instead of waiting out the refresh
            # interval: right after an fppd restart the first call often fails.
            refresh_targets = True
            sleep_s = IDLE_S
        time.sleep(sleep_s)

    log.info("stopped")


if __name__ == "__main__":
    sys.exit(main())
