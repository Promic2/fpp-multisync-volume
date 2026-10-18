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
- Re-sends the volume every minute, so a remote that rebooted gets back in
  step on its own.
- The master's own volume is never changed: each remote gets its own
  MultiSync "Volume Set" command addressed only to it.
- Off after install until you enable it.

## Setup

1. Install the plugin from the Plugin Manager on the **master**.
2. Open **Content Setup → MultiSync Volume**.
3. Tick **Enable** and choose **All remotes** or **Selected remotes only**.
4. Optionally set an offset per remote and press **Save remotes**.

The remotes need nothing installed. They only have to be in Remote mode and
receive MultiSync from the master.

Volume changes are forwarded within a fraction of a second. Changes on the
settings page (enable, mode, offsets) and newly discovered remotes are picked
up within about 5 seconds.

## How it works

A small Python daemon (started with fppd, stopped with it) reads the
master's volume through FPP's HTTP API several times a second and, when it
changes, triggers FPP's own `Volume Set` command with MultiSync addressed to
each remote. It only uses FPP's documented HTTP and command APIs.

Log: `plugin-fpp-multisync-volume.log` in FPP's logs (also in the Support Zip).

## Privacy

Sends only the volume level, and only to the FPP remotes on your own network,
and only while enabled. No internet access, no data collected.

## Compatibility

Tested on FPP 10.2 (master in a Debian VM, remote on a Raspberry Pi 4).

## License

MIT, see [LICENSE](LICENSE).
