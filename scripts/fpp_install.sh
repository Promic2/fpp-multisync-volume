#!/bin/bash
set -e
# fpp-multisync-volume install script. Safe to run more than once.
# This only stops a copy left running by an earlier version; the daemon is
# started by postStart.sh when fppd starts and stopped by preStop.sh when it
# stops, so the plugin starts working after the fppd restart FPP asks for
# below. It then stays idle until "Enable" is switched on in
# Content Setup -> MultiSync Volume.
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"

DIR="$(cd "$(dirname "$0")" && pwd)"
chmod +x "$DIR"/*.sh 2>/dev/null
"$DIR/daemon.sh" stop

# Ask FPP to show its "restart fppd" banner: postStart.sh starts the daemon.
setSetting restartFlag 1
