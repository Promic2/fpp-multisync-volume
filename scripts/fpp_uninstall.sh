#!/bin/bash
# fpp-multisync-volume uninstall script. Safe to run more than once.
: "${FPPDIR:=/opt/fpp}"
. "${FPPDIR}/scripts/common"

REPO=fpp-multisync-volume
DIR="$(cd "$(dirname "$0")" && pwd)"
[ -x "$DIR/daemon.sh" ] && "$DIR/daemon.sh" stop
rm -rf "${MEDIADIR}/plugindata/${REPO}"
rm -f "${MEDIADIR}/config/plugin.${REPO}"
exit 0
