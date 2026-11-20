# openDAW Stage

Your own music website for tracks made in [openDAW](https://opendaw.studio). You put these files on your webspace,
publish tracks straight from the studio, and visitors listen, like, comment and follow. You moderate everything on
a private admin page.

No database server, no build step, no Composer, no npm. Plain PHP, HTML, CSS and JavaScript.

How publishing works from the studio: [openDAW manual, Stage Website](https://dev.opendaw.studio/manuals/stage-website).

## Requirements

1. **PHP 8.1 or newer.** Every common shared host has it (Strato, IONOS, all-inkl, Hetzner, Netcup and the like).
2. **HTTPS.** Setup, uploads and logins refuse plain HTTP (localhost is the exception for testing).
3. **Apache with `.htaccess` enabled** (the default on shared hosting). The site writes `.htaccess` files that
    1. deny access to the data folder, `lib/` and `tests/`,
    2. stop scripts from running in `media/`,
    3. let browsers cache the audio and covers for a long time.

   On **nginx** the `.htaccess` files are ignored. The data folder still has a random, unguessable name and stored
   files never execute, but you should deny `/data-*`, `/lib` and `/tests` in your server configuration and add
   the short track addresses yourself: `rewrite ^/([a-z0-9-]+)$ /track.php?t=$1 last;` (adjust the path when the
   stage lives in a sub folder).
4. **mod_rewrite** for the short track addresses (`example.com/music/my-track`). Shared hosts have it switched on.
   Without it the short links on the pages lead nowhere, only `track.php?t=my-track` works.
5. **Write access** for PHP to the site folder. Setup creates `config.php`, the data folder and `media/`.
6. **Upload size.** Tracks arrive in 1 MB pieces, so the host's upload limits (`upload_max_filesize`,
   `post_max_size`) do not matter. One file may be up to 1 GB.
7. **Optional, for likes, comments and follow:** a Google OAuth client (free) and PHP's `mail()` function, which
   shared hosts provide.

## Installation

1. Download the zip of the
   [latest release](https://github.com/andremichelle/opendaw-stage/releases/latest) ("Source code (zip)"), unpack
   it and copy the contents of its folder into a folder of your webspace, for example `https://example.com/music/`.
2. In openDAW, open a project, go to the project info and click **Stage**. Click **Create** next to Token. The
   token is copied to your clipboard. Keep it in your password manager.
3. Open `https://example.com/music/setup.php`. Enter a title, the token from openDAW and an admin password (at
   least 10 characters). Google login comes later, on the admin page.
4. Back in openDAW, enter the address of your stage (`https://example.com/music/`) and click **Publish**.

### Setup window

Until you have run `setup.php`, anyone who finds the fresh installation could run the setup first and take over
the site. Run the setup right after uploading the files. Once `config.php` exists, `setup.php` refuses to run
again. If somebody was faster, delete `config.php` and the `data-…` folder and run the setup again.

### Google login and mails

Likes, comments and follow need a Google OAuth client, the mails to followers need a sender address on your
domain. [MANUAL.md](MANUAL.md) walks you through both, step by step. Without them the site works as a player.

## Changing the design

All pages are plain HTML templates in `templates/`, the design is `assets/style.css`.

1. Create a folder `custom/` next to `templates/`.
2. Copy the files you want to change into it (`custom/style.css`, `custom/list.html`, `custom/track.html`, …).
3. Edit them. A file in `custom/` always wins over the one in `templates/` or `assets/`, and updates of this
   repository never touch `custom/`.

The colours are CSS variables at the top of `style.css` (`--color-accent`, `--wave`, `--wave-played`, …). Your own favicon goes to `custom/favicon.svg`.

### Template syntax

| syntax | meaning |
|---|---|
| `{{title}}` | inserts a value, HTML escaped |
| `{{{descriptionHtml}}}` | inserts prepared HTML (only for values ending in `Html`) |
| `<!-- if:liked --> … <!-- end:liked -->` | shown when the value is set |
| `<!-- if:!liked --> … <!-- end:!liked -->` | shown when the value is not set |
| `<!-- each:tracks --> … <!-- end:tracks -->` | repeated for every track, comment, tag, … |
| `<!-- include:header -->` | inserts `header.html` |

`assets/stage.js` finds its elements by `data-` attributes (`data-player`, `data-play`, `data-waveform`,
`data-like`, `data-follow`, `data-comment-form`, `data-open`). Keep those attributes when you change the HTML.

## Updating

On the admin page, section **Updates**: **Check for updates**, then **Update to …**. Releases that need more than
new files say so and are installed by hand, see [MANUAL.md](MANUAL.md#4-updating). Nothing outside `config.php`,
the `data-…` folder, `media/` and `custom/` holds your data.

Every release carries `release.json` with its version, its file list and the oldest version that may update to it
automatically (`autoUpdateFrom`). It is written with `php tools/release.php <version> <autoUpdateFrom>` before the
release is tagged, and `tests/run.php` fails when it does not match the shipped files.

## Backup

Copy `config.php`, the `data-…` folder and `media/`. That is the whole site state.

## How it works

### Files

```
index.php            list of all tracks
track.php            single track page, reached as /<track> through .htaccess
admin.php            admin page (password)
oauth.php            Google login returns here
legal.php            privacy page and imprint, reached as /privacy and /imprint
setup.php            first run only
api.php              uploads from openDAW, likes, comments, follow, Google login, project for Open in openDAW
lib/                 the PHP code
templates/           the HTML templates
assets/              style.css and stage.js
custom/              your own templates and styles (optional, never overwritten)
config.php           written by setup and the admin page
data-<random>/       all data as JSON, never served
media/<track>/       cover, mixdown, peaks and project of each track, served directly
```

### Data

Everything is stored in JSON files, no database needed.

```
data-<random>/index.json                   list of all tracks
data-<random>/tracks/<id>/track.json       title, artist, description, tags, tempo, length, flags
data-<random>/tracks/<id>/likes.json       ids of the users who like it
data-<random>/tracks/<id>/comments.json    comments, pending and approved
data-<random>/users.json                   everyone who logged in with Google
data-<random>/followers.json               followers with mail address and unfollow key
```

Every change locks the file, writes a temporary copy and renames it into place, so a crash never leaves a half
written file. Likes and comments lock only their own track. A personal site stays far below what this handles
(about a hundred writes per second on shared hosting). The audio never passes through PHP.

### Publishing from openDAW

openDAW renders the mixdown, encodes it as Ogg Opus at 320 kbps, computes the waveform (2048 points) and, if you
choose, bundles the project with all its samples. It then sends the files to `api.php`:

1. `begin` clears an earlier, unfinished publish of this project.
2. `upload` sends small files at once, `chunk` and `commit` send large ones in 1 MB pieces, four at a time.
   Pieces are retried after network errors.
3. `publish` checks the files (meta data, image, Ogg Opus, waveform, zip) and only then makes the track live.

Each request carries the token in the `X-OpenDAW-Token` header. The site stores only its SHA-256 hash in
`config.php`, so the token cannot be read from the server. Requests from other websites are refused (CORS: only
openDAW and this site itself).

Publishing the same project again **replaces** the track: the address, likes and comments stay, the files and meta
data are swapped, and the comments show "Updated" with the date.

### Visitors

1. **Player:** one track plays at a time. Click the waveform to jump. Browsers that cannot play Ogg Opus (Safari
   before 18.4) get a disabled play button and a hint to update.
2. **Open in openDAW:** shown when the project was included. It opens the project in the studio. On phones and
   tablets the button is disabled, because openDAW runs on desktop. Each track opens the openDAW it was published
   from (opendaw.studio, or dev.opendaw.studio while testing). The admin page shows it per track, and "Open in PROD
   now" under Tracks moves all tracks published from dev.opendaw.studio to opendaw.studio.
3. **Likes and follow** need a Google login.
4. **Comments** need a Google login and wait for approval. The author sees their own comment marked "Waiting for
   approval", everybody else sees it once you approved it. One comment per user every 5 minutes.

### Admin page

`admin.php` asks for the admin password. Then you can hide, show and delete tracks, approve and delete comments,
block users (removes their likes, comments and follow), remove followers and change the title, token, password,
mail sender and Google keys.

### Caching

Pages are sent with `Cache-Control: no-cache` and an ETag: the browser always asks, and gets the newest page or a
short "not modified". Audio, covers and waveforms carry the publish time in their address, so browsers keep them
for a long time and fetch them again only after a replace.

### Security

1. Passwords are stored as hashes (`password_hash`), the token as SHA-256. Changing the token or the password
   needs the current password.
2. The admin page only works over HTTPS. Five wrong passwords from one address (or thirty in total) lock the
   login for 15 minutes.
3. Every form and action checks a CSRF token. Sessions are `HttpOnly`, `SameSite=Lax`, `Secure` on HTTPS, use
   strict mode and live in the data folder, not in the host's shared folder. A session starts only on login,
   plain page views create none.
4. All text from openDAW and visitors is escaped in the pages.
5. Uploaded files get fixed names chosen by the site, and their content is checked before they go live.
6. Hiding a track moves its files to a folder with a random name, so they are not reachable under the old
   address. "Allow downloading the mixdown" only shows or hides the download button: the player streams the
   file, so it is public while the track is visible.
7. Likes and follow are limited to one per second and user, comments to one every five minutes, the welcome mail
   is sent once per user.

## Tests

```
php tests/run.php
```

Copies the site to a temporary folder, runs it on PHP's built-in server and checks setup, uploads, publishing,
pages, visitor actions and the admin page.

## Licence

MIT, see `LICENSE`. Provided as is, without warranty. You run it on your own server and are responsible for its
security, backups, limits and costs.
