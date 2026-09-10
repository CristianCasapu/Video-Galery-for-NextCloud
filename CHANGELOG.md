# Changelog

All notable changes to Video Gallery are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] — 2026-09-10

First release.

### The library

- Every video file in an account is collected into one library, from the home
  folder, shared folders and external mounts alike
- Arranged by the date each video was shot, read from the file's own metadata,
  falling back to a date in the file name and then to the file's timestamp
- Rows on the front page for what you were watching, your own video folder, what
  arrived recently, this day in earlier years, each year with enough in it, long
  films, short clips, the folders you already sort things into, and something
  from more than a year ago worth another look
- A timeline view grouped by day, and a searchable grid with filters
- Cover pictures, and a short silent clip that plays under the pointer
- A folder named `Video` is created in each account as the obvious place to put
  something new

### Playing

- Files the browser can open are streamed untouched, with byte ranges so seeking
  works without downloading everything up to the point you jumped to
- Files it cannot are repackaged or converted on the fly and delivered as HLS,
  cut into MPEG-TS or fragmented MP4 depending on what the streams need
- Only what needs changing is changed: an MKV holding H.264 and DTS keeps its
  picture and has only its sound converted
- Seeking into a part that has not been produced restarts the encoder there,
  rather than making you wait for it to grind through the intervening hour
- Segments already produced survive a restart, so seeking back costs nothing
- The encoder is paused once it is far enough ahead of the viewer, and started
  again when they catch up, so an abandoned film is not converted in full
- Hardware conversion on NVENC, Quick Sync, VA-API or VideoToolbox, chosen by
  testing each one rather than by reading build flags, with software as the
  fallback — including at runtime, if the card turns out to be unreachable

### The connection

- The browser measures a real download against the server before playing, and a
  file is only sent untouched when the link will carry it
- The player keeps reporting what it is getting, and the quality is walked down
  when the link cannot keep up and back up when it can, with hysteresis so a
  brief calm patch does not start the cycle again
- Quality can be pinned by hand, and the reason for any automatic change is shown

### Learning

- What was decided and how it went are recorded against the shape of the browser
  and the shape of the file, and a situation met before is answered from
  experience rather than worked out again
- A way of playing that has failed for a situation is not chosen for it again
  while another remains

### The player

- Full screen, with the screen turning to match the film on a phone
- Chapter marks along the progress bar, and a menu to jump between chapters
- Thumbnails while dragging along the bar, from a strip made once per file
- Swipe gestures on touch screens: dim, volume, and scrub
- Embedded subtitle tracks converted to WebVTT as they are asked for
- Every audio track, switchable without losing your place
- Picture in picture, and resume where you left off across devices
- "Open in another player" hands the untouched original to VLC, Infuse or
  whatever is installed, through a signed link that needs no session

### Looking after itself

- Everything written outside the database lives under one directory, marked as
  the app's own, and nothing outside that mark is ever deleted
- Every file on that disk has a database row; every row has a file. Sweeping is
  comparing the two, not guessing
- Sessions whose player has stopped checking in are ended and their encoders
  stopped; a closed tab says so with a beacon rather than being waited out
- Cached previews are dropped oldest-first when the size limit is reached, and
  after a period nobody has opened them
- Removing the app takes everything it put on disk with it

### Administration

- A settings page that says what this machine can and cannot do, and gives the
  command for anything missing — including the two permission problems that
  commonly hide a graphics card from the web server
- The same findings appear on the Nextcloud administration overview
- `occ videogallery:selftest` converts part of a real file, seeks into it, checks
  the result plays, and confirms nothing was left behind

[1.0.0]: https://github.com/CristianCasapu/Video-Galery-for-NextCloud/releases/tag/v1.0.0
