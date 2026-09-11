# Video Gallery for Nextcloud

Every video in your account, in one place, playing in the browser — including the
formats a browser cannot open on its own.

Nextcloud will happily store a 4K HEVC film, a phone clip shot upright, and a
twelve year old AVI in the same folder. It will not play any of them. This app
finds all of them, arranges them by the date they were actually shot, and plays
each one, converting on the graphics card only where converting is needed.

![The library](screenshots/library.png)

## What it does

**Finds everything.** Every video file in an account, wherever it is — the home
folder, a shared folder, an external mount. The library is built from one indexed
query per mount, so a sweep of a large account costs almost nothing, and it keeps
itself level with the file tree as files arrive, move and go.

**Sorts by when it happened.** The date comes from inside the file — the capture
time a camera or phone wrote into it — not from when it landed on the server. A
holiday copied across last week still sits under the summer it belongs to. Where
the file says nothing, the date in its name is read; failing that, its timestamp.

**Plays anything.** Four things can happen when you press play, and the app picks
between them for the file and the browser in front of it:

| | |
|---|---|
| **Sent untouched** | The browser can open the file and the connection can carry it. Nothing is converted; the original is streamed as it is. |
| **Repackaged** | The picture and sound are fine but the container is not — an MKV holding H.264, say. The streams are copied into a new wrapper, untouched. Cheap. |
| **Sound converted** | The picture is fine and the sound is not: DTS, TrueHD, a six channel mix. Only the audio is re-encoded. |
| **Re-encoded** | Nothing else will do — HEVC, 10-bit, VC-1, MPEG-2, AV1 on an old browser. The video is converted on the graphics card, into a stream the browser plays with ordinary controls. |

**Measures the connection first.** Being able to decode a file is not the same as
being able to receive it. A 40 Mbit/s film plays perfectly over Wi-Fi at home and
stutters on mobile data, so the browser times a real download against the server
before playing and the file is only sent untouched when the link will carry it.
The measurement is repeated throughout, and the quality follows the connection
down when it has to and back up when it can.

**Learns.** What was decided and how it went are noted against the kind of browser
and the kind of file. A combination met before is played the way that already
worked — which starts sooner, and quietly corrects the cases where a browser
claims a codec it then stumbles over. Nothing about who watched what is kept.

**Shares.** A video or a whole folder, with a person, a group, or anyone holding
a link. A folder link can be browsed and everything in it watched. Each share
decides for itself whether the file may be downloaded — and where it may not,
the file is never sent and no outside player is offered, while watching carries
on as normal. Shares are created through Nextcloud's own sharing, so every rule
an administrator has set still applies, and where the
[short links](https://github.com/CristianCasapu/shortcloud) app is installed a
link can have a short address as well as a long one.

**Watches in order.** A course or a lecture series is dozens of numbered files in
one folder. Where a folder looks like that, the next part is offered when one
finishes, with a countdown you can stop.

**Cleans up after itself.** Nothing reaches the scratch disk without a database
row naming it, and nothing keeps a row without a file behind it. A crashed
encoder, a closed tab, a power cut and an uninstall all leave the same
recognisable, removable mess, and it is removed.

## Tuning itself to the machine

Nothing in the conversion pipeline is a fixed number. How many frames a card
will hold at once, which of its presets are worth using, whether decoding on it
is faster than decoding beside it — these differ between two cards from the same
maker in the same year, and guessing wrongly costs either speed or reliability.

**Measure this machine**, on the settings page, finds out. It builds a sample as
demanding as a real film, walks the frame allowance up until the card refuses,
times both decode paths against each other, times every preset, and then puts
the winning combination through exactly what playback will put it through before
keeping it. Every figure it sets can be overridden by hand.

On a machine with several cards, work goes to whichever enabled card is carrying
least. Each can be switched off or given its own limit.

When something fails anyway — and it does, because a card that handles one file
will refuse another — the card is given up a piece at a time: the decoder first,
which costs a fraction of the speed, and the encoder only if it must.

## The player

* Full screen, and on a phone the screen turns to match the film
* Chapter marks along the progress bar, where the file carries chapters
* Drag along the bar to see the frame you are heading for
* Swipe up the left of the picture to dim it, up the right for volume, across to scrub
* Every embedded subtitle track, converted to WebVTT as it is asked for
* Every audio track, switchable without losing your place
* Quality: automatic, or pinned by hand
* Picture in picture, and resume where you left off, across devices
* **Open in another player** — hands the untouched original to VLC, Infuse, or
  whatever is installed on the device. No browser can embed VLC; the plugin
  interfaces that once allowed it were removed years ago. This is the way that
  works, and it is the right choice for a television or a file you want bit for bit.

## Getting it

Download `videogallery.tar.gz` from the [latest release](https://github.com/CristianCasapu/Video-Galery-for-NextCloud/releases/latest),
unpack it into your Nextcloud `apps` directory, and enable it:

```bash
cd /path/to/nextcloud/apps
curl -L https://github.com/CristianCasapu/Video-Galery-for-NextCloud/releases/latest/download/videogallery.tar.gz | tar xz
cd ..
sudo -u www-data php occ app:enable videogallery
sudo -u www-data php occ videogallery:scan
```

The release file has no version in its name, so the same command upgrades it.

## What it needs

**PHP 8.4 or newer, and ffmpeg.** That is the whole list. The app finds it by itself in the usual
places, works out what this machine can really do with it — by encoding a frame
and seeing whether it worked, not by reading build flags — chooses a scratch
directory, creates it, and starts.

```bash
sudo apt install ffmpeg      # Debian, Ubuntu
sudo dnf install ffmpeg      # Fedora, RHEL
sudo apk add ffmpeg          # Alpine
```

Nextcloud itself still runs on older PHP, so this app will refuse to enable on a
server below 8.4 rather than half working on it.

Everything else it can arrange or do without. If something is missing or
unreachable, the settings page says so in a sentence and gives the command that
fixes it. `occ videogallery:status` says the same thing from a terminal.

### Hardware conversion

Software conversion works everywhere and needs nothing. It also occupies several
processor cores per stream, so on a machine with a graphics card it is worth
getting the card involved. The app tests NVENC, Quick Sync, VA-API and
VideoToolbox on startup and uses the best one that actually works.

Encoding on a card and decoding on it are separate questions, and the answer is
often different — a machine can encode perfectly and refuse to decode a frame,
usually because the ffmpeg build and the installed driver disagree about how
many frames may be held on the card at once. Both are tested separately, and if
decoding fails during a conversion the app gives up that half and keeps the
encoder, which costs a fraction of the speed rather than most of it.

Two things commonly stop a card being used, and both are about permission rather
than hardware:

**A hardened service unit.** Many distributions ship PHP-FPM with
`PrivateDevices=true`, which gives the service its own `/dev` with no graphics
card in it. ffmpeg then reports "no CUDA-capable device is detected" even though
the same command works from a shell. The app notices this exact situation and
tells you; the fix is a drop-in:

```bash
sudo systemctl edit php8.3-fpm       # your PHP version
```
```ini
[Service]
PrivateDevices=no
DevicePolicy=closed
DeviceAllow=/dev/nvidia0 rw
DeviceAllow=/dev/nvidiactl rw
DeviceAllow=/dev/nvidia-uvm rw
DeviceAllow=/dev/nvidia-uvm-tools rw
DeviceAllow=/dev/nvidia-modeset rw
```
```bash
sudo systemctl daemon-reload && sudo systemctl restart php8.3-fpm
```

**Group membership**, for Intel and AMD cards:

```bash
sudo usermod -aG render www-data && sudo systemctl restart php8.3-fpm
```

### The scratch disk

Converting video writes constantly and in quantity. Point the app at a disk that
is not the one the system runs from — an NVMe, ideally — in
**Settings → Administration → Video Gallery**. Left alone it picks the best
place it can find and says which.

Whatever it uses, it stays inside a single directory, marked with a file of its
own, and it is that mark which permits anything there to be deleted. A mistyped
setting cannot turn into a wiped system directory, and a directory that already
holds someone else's files is refused rather than adopted.

## Commands

```
occ videogallery:scan [user] [--reprobe] [--discover-only]   find and read video files
occ videogallery:preview [user] [--limit=N]                  make cover pictures and hover clips
occ videogallery:status [--probe]                            what this server can play
occ videogallery:selftest [--file=ID] [--keep]               convert part of a real file and check it plays
occ videogallery:cleanup [--all]                             end stale sessions, clear the scratch disk
```

`videogallery:selftest` is the one to run when something is wrong. Every other
check asks whether a thing looks right; this one takes an actual file out of the
library, converts a piece of it both ways a viewer might, seeks into the middle,
confirms what came out is a playable stream, and confirms that closing the
session left nothing behind.

## Privacy

The library index holds what is needed to show and play a file: where it is, how
long it is, what it is made of. Watch positions are per account. What the app
learns about playback is keyed by a hash of the file's shape and the browser's
abilities, and holds no account, no file name and no address.

Links handed to an external player carry a signed token naming one file, one
account and an expiry, because a player on a television has no session to use.
Nothing is stored to make those work, so nothing is left behind when they expire.

## Building from source

```bash
npm ci && npm run build
```

## Licence

AGPL-3.0-or-later
