# openDAW Stage

> **Status:** Plan. Nothing implemented.

## TL;DR

A personal music site for openDAW users. The user copies this repo onto any PHP webspace, runs the setup page once, and publishes tracks straight from the studio. Visitors listen, like and comment (Google login), the owner moderates on a private admin page.

1. Plain PHP, plain HTML, plain CSS, plain JS. No build step, no Composer, no npm, no database server.
2. Three pages: the track list, a single track page, the admin page.
3. Works on mobile: all three pages are responsive and touch friendly, the player and waveform included.
4. The design lives in HTML templates and one CSS file the user edits freely.
5. Storage is JSON files behind one PHP interface, so MySQL can be added later without touching the pages.
6. Upload auth and chunked uploads work exactly like the custom server (`plans/custom-server.md` in the openDAW repo).

---

## Repository layout

```
index.php            track list
track.php            single track page
admin.php            admin page (password)
setup.php            first run only, locks itself afterwards
api.php              studio uploads, likes, comments, Google login callback
lib/                 Storage, Upload, Auth, Google, Template, Http
templates/           list.html, track.html, admin.html (user editable)
assets/style.css     the whole design (user editable)
assets/stage.js      player, waveform, likes, comments
data/                JSON, never served (.htaccess deny, unguessable name on nginx)
media/<slug>/        cover, mixdown, peaks, bundle (served as static files)
tests/               PHP CLI test runner against php -S
```

---

## Setup

1. The user uploads the repo contents and opens `setup.php`.
2. The page asks for the studio token (generated in the studio, shown once there), the admin password, the site title and optionally the Google client id and secret.
3. It writes `config.php` with the SHA-256 hash of the token, the password hash (`password_hash`), the Google keys and a random data folder name, then refuses to run again while `config.php` exists.
4. The admin page can replace the token, the password and the Google keys later.

Requirements: PHP 8.1 or newer, HTTPS.

---

## Storage

One folder per track keeps locks and damage local to that track.

```
data/index.json                    list of tracks, small summary per track
data/tracks/<uuid>/meta.json       full meta
data/tracks/<uuid>/likes.json      Google user ids
data/tracks/<uuid>/comments.json   approved and pending comments
data/users.json                    Google user id, name, avatar, blocked flag
data/followers.json                Google user id, mail address, unfollow key
```

1. Every write: `flock` on the track, write to a temp file, `rename` into place. A crash never leaves a half written file.
2. The track key is the openDAW project UUID. Publishing the same project again replaces the track.
3. Estimate: about 1 to 5 ms per write on shared hosting, 100+ writes per second before queueing. The audio never touches PHP.
4. `Storage` is one PHP interface, `JsonStorage` its only implementation for now.

---

## Upload protocol (studio to site)

Same rules as the custom server.

1. Header `X-OpenDAW-Token`, checked with `hash_equals` against the stored hash. Wrong token answers 401 without detail.
2. CORS only for `https://opendaw.studio`, `https://dev.opendaw.studio` and localhost for development. Preflight before the token check.
3. Only GET and POST. Files up to one chunk use `upload`, larger ones `chunk` plus `commit` (1 MB chunks, four in flight, idempotent, retried).
4. Cap of 1 GB per file, below that the server's own limits apply.
5. Stored files never execute: fixed file names per kind, `.htaccess` in `media/` disables PHP.

A publish sends these files in parallel, then one `publish` call that makes them live at once:

| file | always | |
|---|---|---|
| `meta.json` | yes | title, artist, description, tags, BPM, duration, download flags |
| `cover.jpg` | yes | |
| `mixdown.opus` | yes | Ogg Opus at 320 kbps VBR, rendered and encoded by the studio |
| `peaks.bin` | yes | 2048 min/max pairs as int8, about 4 KB |
| `project.odb` | optional | the bundle, needed for "Open in openDAW" |

Replacing keeps the URL, likes and comments, swaps files and meta, and adds a system comment "Updated" with the timestamp.

---

## Pages

### List (`index.php`)

Every track with cover, title, artist, meta, a small waveform, a play button and the like and comment counts. One player at a time.

### Single track (`/<slug>`, served by `track.php`)

1. Cover, meta, description, tags.
2. Player with the waveform on a canvas: progress, seek on click. Colours from CSS variables.
3. When `audio.canPlayType("audio/ogg; codecs=opus")` is empty: "Your browser cannot play this track, please update it" instead of the play button.
4. "Open in openDAW" when a bundle exists, linking to `opendaw.studio/open-stage?url=<bundle url>`. Disabled on phones and tablets (coarse pointer), with the hint "openDAW runs on desktop".
5. Download buttons for mixdown and bundle when the artist allowed them on publish.
6. Like, follow and comments, all need Google login.
7. Open Graph tags (title, description, cover, audio) for link cards.

### Admin (`admin.php`)

1. Login with the password, session cookie, CSRF token on every form.
2. Tracks: hide, show, delete.
3. Pending comments: approve, delete. Approved comments: delete.
4. Users: block (their comments and likes disappear, new ones are refused).
5. Settings: replace token, password, Google keys, site title.

---

## Visitors

1. **Google login:** OAuth authorization code flow with plain `curl`, the id token comes straight from Google's token endpoint over TLS, PHP session afterwards.
2. **Likes:** one per Google user and track, toggle.
3. **Comments:** saved as pending. The author sees "Thanks, your comment is waiting for approval" and their own comment marked as waiting. Everybody else sees it after approval. One comment per user every 5 minutes.
4. **Follow:** a follow button (Google login) on the list and the single page. When the artist publishes a new track (not a replace), every follower gets a mail with title and link, sent with PHP's `mail()`. Following sends a welcome mail at once, asking to fish it out of the spam folder and add the sender to the contacts. Each mail carries a one click unfollow link. The admin page shows the follower count and can remove followers.

---

## Caching

1. Pages and JSON: `Cache-Control: no-cache` plus ETag. The browser asks every time and gets the newest version, unchanged content costs a 304 without body.
2. Media files: long cache, URLs carry `?v=<updated>`, so a replaced track is fetched fresh.

---

## Templates

1. `templates/*.html` are plain HTML with `{{value}}`, `<!-- if:x -->`, `<!-- each:x -->` and `<!-- include:x -->`.
2. All styling in `assets/style.css`, no inline styles in PHP or JS.
3. Changed files go into `custom/`, which wins over `templates/` and `assets/` and is never touched by updates.

---

## openDAW side

1. **Publish dialog:** site address and token (stored in `localStorage` like the custom server), "Create token" with copy button, meta prefilled from the project (title, artist, description, tags, cover), checkboxes "Allow opening in openDAW" and "Allow downloading the mixdown". No project download, opening it in openDAW covers that.
2. **Pipeline:** reuses `Contest.ts`: bundle, offline render, `OpusEncoder` with FFmpeg fallback, peaks, chunked upload.
3. **Open route:** `/open-stage?url=<bundle url>`, generalising `OpenBundlePage` (today fixed to `api.opendaw.studio/music`). Asks before loading from a foreign address.
4. **Manual** `/manuals/stage` and changelog.

---

## Phases

1. ~~**Skeleton and upload.** Setup page, config, `JsonStorage`, upload API, PHP CLI tests against `php -S` (token, origin, chunks, commit, replace, 1 GB cap, no execution in `media/`).~~ Done: `setup.php`, `api.php` (alive, status, begin, upload, chunk, commit, publish), `lib/`, `tests/run.php` (60 tests).
2. **Studio publish.** Dialog and pipeline against a local `php -S`. Built on next-release (uncommitted): `src/stage/` (StageClient, StagePublish, StageDialog), Stage button in the project info, `/open-stage?url=` route, `OpusMixdown` shared with the contest. Type checks, not yet run against a real stage.
3. ~~**List page.**~~ Done, design in the studio colours.
4. ~~**Single page.**~~ Done: player, waveform, Open Graph, mixdown download, Open in openDAW (disabled on touch devices).
5. **Visitors.** Built: Google login, likes, comments with approval, follow, welcome and announce mails. Google login and mails untested (need real keys and a mail server).
6. ~~**Admin page.**~~ Done: tracks, comments, users, followers, settings. `tests/run.php` covers pages, visitors and admin (106 tests).
7. **Real host.** Strato from localhost, then from DEV. Manual and changelog.

---

## Decisions

1. Name: openDAW Stage, repo `opendaw-stage`.
2. Auth like the custom server, token entered on setup, replaceable on the admin page.
3. JSON storage first, MySQL later behind the same interface.
4. Google is the only login provider for now.
5. Ogg Opus mixdown, old Safari gets an update hint.
6. Comments wait for approval, one per user every 5 minutes.
7. Opus at 320 kbps VBR, high quality first.
8. MIT licence.
9. The studio side lands on next-release and launches on DEV first.
10. Mobile ready, Open in openDAW disabled there.
11. The setup window (anyone could run a fresh setup first) is accepted and explained in the README.
12. Mails with PHP's `mail()`, plus the welcome mail on follow.
13. "Open in openDAW" opens the openDAW a track was published from (the `Origin` of the publish request, PROD when unknown). The admin button "Open in PROD now" moves all DEV tracks to PROD, pressed on launch day (openDAW's `UPDATE.md`). The route stays `/open-stage?url=`, `/open-bundle` belongs to the music page.
