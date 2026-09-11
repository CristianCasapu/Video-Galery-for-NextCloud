# Changelog

All notable changes to Video Gallery are recorded here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and the project uses [semantic versioning](https://semver.org/spec/v2.0.0.html).

## [1.3.0] — 2026-09-11

The library is arranged by the folders it is kept in, and what those folders
mean is something you decide rather than something the app assumes.

### Kinds of folder

- Folders are sorted into kinds — courses, series, camera, films, and whatever
  else you define — by **rules you write in the settings**: words that appear in
  a folder's path, or a pattern its file names match
- Each kind is shown the way it wants showing: **opened at the part worth
  watching next**, or **newest first**
- Each kind says whether one part follows another, which is what makes the next
  one start by itself when the last finishes
- The first kind that matches a folder wins, so the order is the order they are
  tried in, and it can be rearranged

### Where to pick things up

- A course shows the part you had reached, the next part nobody has started, or
  its beginning — whichever applies, marked as such on the card
- A folder of clips shows its newest
- The red line showing how far through a video you got now appears everywhere it
  should. It was only ever sent with the flat list, so the front page never had it

### Finding your way around

- Every card carries the folder it came from, and **the folder is a way in**:
  press it and that folder opens, in the order it should be watched
- A folder view with a path back up through it, and the folders inside it
- **Everything** is now a page of folders rather than a wall of files, each
  opened at the part worth opening
- **Timeline** is arranged by year, then by day, and within a day by the folder
  each video came from
- Browse no longer has a row per year; that belongs in the timeline

### Under it

- The whole arrangement is worked out from one query rather than one per folder,
  and kept for a minute or two, because a library of two hundred folders cannot
  afford two hundred round trips to be looked at



### Fixed

- **The player now covers the page.** It was drawn underneath Nextcloud's own
  header, which put the header's logo on top of the close button — so closing a
  video navigated away from the app instead. The player is moved out to the page
  body, and the rest of the page is hidden for as long as something is playing.
- **The library fills the window and scrolls.** Nextcloud hands an app a flex row
  with the overflow clipped and expects it to claim its room and scroll itself;
  without that the app was squeezed to the width of its widest child and its
  overflow simply disappeared.
- **The timeline is one continuous grid.** A day with three videos in it left
  most of a row empty and the page looked half used. The dates now sit across
  the full width as dividers and the videos flow on past them.
- **Reloading keeps you where you were.** The view, the search and the folder are
  in the address, so the timeline stays the timeline after a refresh.
- **Only two previews are made at once.** Opening the library asked for every
  visible picture in the same second, and each one that was missing started an
  encoder — forty cards meant forty encoders, which is how a server with nobody
  watching anything came to be at full load. Requests that find every place taken
  are turned away and come back a moment later.
- Bitrates under a megabit read as "0.0 Mbit/s"; they now read in kbit/s.
- Icons sat a pixel or two above and left of centre in their buttons, an inline
  SVG being placed on the text baseline.
- The page a share link opens sat in a narrow column with the sign-in wallpaper
  showing beside it.

### Added

- **Back**, where the close button used to be, and the browser's own back button
  and back gesture close the player too
- **Show where this file is**, from the player and the details panel
- **Edit details**: a title, a description and the date it was recorded. Kept
  beside the file rather than inside it, so reading the file again never undoes
  it; the file itself is touched only if you ask for it to be renamed
- Short links appear by themselves in the share dialog rather than waiting to be
  asked for, with the long address underneath
- Scrolling to the end of a list loads the next page before you reach it
- Every page and every dialog laid out for phones and tablets as well as desktops:
  dialogs become sheets, controls grow where there are fingers rather than a
  pointer, and grids reflow rather than squash



### Sharing

- Share a video or a whole folder from the gallery, with a person, a group, or
  anyone holding a link
- A link opens the player rather than the file list, and a folder link can be
  browsed at any depth — stopping, always, at the edge of what was shared
- Each share decides whether the file may be downloaded. Where it may not, the
  file itself is never served and no link to an outside player is offered, while
  watching carries on: a converted stream is watching, not downloading
- Shares are created through Nextcloud's own sharing, so every rule an
  administrator has set still applies
- Where the short links app is installed, a share can be given a short address
  as well as a long one, pointing at the player

### Tuning

- Every number the converter runs on is now a setting, and every setting can be
  left to work itself out from the hardware
- **Measure this machine** builds a sample as demanding as a real film, finds the
  largest frame allowance the card accepts, times decoding on the card against
  decoding beside it, times each preset, and verifies the winning combination
  through the whole pipeline before keeping it
- Machines with several graphics cards are supported: work goes to whichever
  enabled card is carrying least, and each can be switched off or given its own
  limit
- Trouble with a card is now recognised wherever it appears in the encoder's
  log rather than only at the end of it, which is where the consequences appear
  rather than the cause. Recovery takes about three seconds instead of thirty

### Watching in order

- A folder of numbered files is recognised as a series, and the next part is
  offered when one finishes, with a countdown that can be stopped
- Ordered the way a person would order it, so part 9 comes before part 10
- A folder of clips from a phone is not a series, and nothing starts by itself

### Leaving things out

- Files whose names contain "sample", or anything else on a list you can edit,
  are never collected
- Videos shorter than a second are set aside rather than deleted, so they are
  not found and opened again on every sweep

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
  testing each one rather than by reading build flags
- Decoding on the card is tested separately from encoding on it, because the
  answers differ, and a conversion that hits trouble gives up the decoder first
  and the encoder only if it must
- Video shot sideways is turned the right way up before it is scaled, which
  means its frames come back from the card, since rotation has no equivalent
  in the graphics pipeline

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

[1.1.0]: https://github.com/CristianCasapu/Video-Galery-for-NextCloud/releases/latest
[1.0.0]: https://github.com/CristianCasapu/Video-Galery-for-NextCloud/releases/latest
