#!/bin/bash
set -e
# fpp-multisync-volume install script. Safe to run more than once.
# Starts the daemon right away so no fppd restart is needed; it stays idle
# until "Enable" is switched on in Content Setup -> MultiSync Volume.
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"

DIR="$(cd "$(dirname "$0")" && pwd)"
chmod +x "$DIR"/*.sh 2>/dev/null
"$DIR/daemon.sh" stop
"$DIR/daemon.sh" start
