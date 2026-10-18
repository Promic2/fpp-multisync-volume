#!/bin/bash
# fppd stopping: stop what postStart.sh started.
"$(dirname "$0")/daemon.sh" stop
