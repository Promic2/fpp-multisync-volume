#!/bin/bash
# Start/stop the fpp-multisync-volume daemon. Used by the FPP hooks and the
# install/uninstall scripts. Returns quickly; the daemon runs in the background
# as the fpp user and logs to FPP's plugin log.
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"

REPO=fpp-multisync-volume
: "${CFGDIR:=${MEDIADIR}/config}"
: "${SETTINGSFILE:=${MEDIADIR}/settings}"
PLUGIN_DIR="${MEDIADIR}/plugins/${REPO}"
DATA_DIR="${MEDIADIR}/plugindata/${REPO}"
PIDFILE="${DATA_DIR}/daemon.pid"
PLUGIN_LOG="${LOGDIR}/plugin-${REPO}.log"

is_running() {
	[ -f "$PIDFILE" ] && kill -0 "$(cat "$PIDFILE")" 2>/dev/null
}

case "$1" in
	start)
		is_running && exit 0
		mkdir -p "$DATA_DIR"
		chown fpp:fpp "$DATA_DIR" 2>/dev/null
		touch "$PLUGIN_LOG"
		chown fpp:fpp "$PLUGIN_LOG" 2>/dev/null
		# The pid file gets the python process itself (exec), not a wrapper.
		LAUNCH="echo \$\$ > '$PIDFILE'; exec env LOGDIR='$LOGDIR' CFGDIR='$CFGDIR' SETTINGSFILE='$SETTINGSFILE' python3 '${PLUGIN_DIR}/volume_sync.py'"
		if [ "$(id -u)" = "0" ] && command -v runuser >/dev/null 2>&1; then
			nohup runuser -u fpp -- bash -c "$LAUNCH" >>"$PLUGIN_LOG" 2>&1 &
		else
			nohup bash -c "$LAUNCH" >>"$PLUGIN_LOG" 2>&1 &
		fi
		for _ in 1 2 3 4 5 6 7 8 9 10; do [ -s "$PIDFILE" ] && break; sleep 0.1; done
		;;
	stop)
		if is_running; then
			kill "$(cat "$PIDFILE")" 2>/dev/null
			for _ in 1 2 3 4 5 6 7 8 9 10; do is_running || break; sleep 0.2; done
			is_running && kill -9 "$(cat "$PIDFILE")" 2>/dev/null
		fi
		rm -f "$PIDFILE"
		;;
	*)
		echo "usage: $0 start|stop"
		exit 1
		;;
esac
exit 0
