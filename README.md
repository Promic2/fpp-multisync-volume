<img src="icon.png" alt="" width="96" align="right">

# MultiSync Volume (fpp-multisync-volume)

An [FPP (Falcon Player)](https://github.com/FalconChristmas/fpp) plugin that
mirrors the master's volume to its MultiSync remotes.

## Why

FPP MultiSync keeps sequences and media in sync between a master and its
remotes, but not the volume. A common setup has the master driving the light
controllers while a remote (for example a Raspberry Pi on the HDMI screen)
plays the video and audio. In that setup the volume slider on the master's
status page changes nothing you can hear, and "Volume Set" commands in the
master's playlists only reach the master.

With this plugin enabled, the master's volume is sent to the remotes whenever
it changes, from the volume slider, a playlist "Volume Set" entry, a command
preset, or anything else.

## Features

- Follows the master's volume within a fraction of a second.
- **All remotes** (found automatically through MultiSync) or **selected
  remotes only**.
- **Per-remote offset**, e.g. `-10` for a remote with louder speakers
  (the result is clamped to 0 to 100).
- Volume changes are sent right away. As a safety net the current volume is
  also sent again at a set interval (off, 10 s, 30 s, 1 min or 5 min; default
  1 min), so a remote that restarted at its default volume is corrected on
  its own.
- The master's own volume is never changed: each remote gets its own
  MultiSync "Volume Set" command addressed only to it.
- Runs on the master only: on an FPP in remote or bridge mode the daemon
  sends nothing, and the plugin's own box is never a target.
- Off after install until you enable it.

## Setup

1. Install the plugin from the Plugin Manager on the **master** (the FPP in
   player mode). It is not needed, and does nothing, on the remotes.
2. Restart fppd when FPP offers to. The daemon is started by fppd and stopped
   with it, so the plugin starts working after that restart.
3. Open **Content Setup → MultiSync Volume**.
4. Tick **Enable** and choose **All remotes** or **Selected remotes only**.
5. Optionally set an offset per remote and press **Save remotes**.

The remotes need nothing installed. They only have to be in Remote mode and
receive MultiSync from the master.

Volume changes are forwarded within a fraction of a second while the volume is
being adjusted; after it has been steady for a while the first change takes up
to a second. Changes on the settings page (enable, mode, offsets) are picked up
within about 2 seconds, newly discovered remotes within 30.

## How it works

A small Python daemon (started with fppd by `postStart.sh`, stopped with it by
`preStop.sh`) reads the master's volume through FPP's HTTP API and, when it
changes, triggers FPP's own `Volume Set` command with MultiSync addressed to
each remote. It only uses FPP's documented HTTP and command APIs.

To keep the cost on the master low it does no work it does not need to: the
settings come straight from FPP's plugin config file and are only re-parsed
when that file's timestamp changes (no PHP requests at all), the MultiSync
system list is read every 30 seconds, and the volume poll backs off from four
times a second to once a second when the volume has been steady for 15
seconds. While the plugin is disabled, or while FPP is not in player mode, the
daemon makes no requests at all.

Log: `plugin-fpp-multisync-volume.log` in FPP's logs (also in the Support Zip).

## Privacy

Sends only the volume level, and only to the FPP remotes on your own network,
and only while enabled. No internet access, no data collected.

## Compatibility

Requires FPP 10.0 or newer, and a master with more than one CPU core
(`minCpuCores: 2`), so the polling never competes with the show on a
single-core board such as a BeagleBone Black or a Pi Zero. The remotes can be
any board — nothing is installed on them.

Tested on FPP 10.2 (master in a Debian VM, remote on a Raspberry Pi 4).

## License

MIT, see [LICENSE](LICENSE).
