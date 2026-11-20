# openDAW Stage Manual

This manual walks you through everything after the installation (see the [README](README.md)): Google login for
likes, comments and follow, and the mails to your followers. Without these two your stage works as a player.

## 1. Google login

Visitors log in with Google to like, comment and follow. Your stage needs its own free Google login client for that.
The admin page shows every value Google asks for under **Settings**, ready to copy.

1. **Your details:** on the admin page, under **Settings**, enter your name and contact address (the postal address
   is optional) and save. They appear on your stage's privacy page and imprint, which Google requires.
2. **Project:** open the [Google Cloud Console](https://console.cloud.google.com/). Google may open an existing
   project right away. Click the project name at the top left, then **New project**, name it after your stage and
   create it. Make sure it is selected at the top left.
3. **Consent screen:** menu, **APIs & Services**, **OAuth consent screen**, **Get started**. Enter your stage's
   title as app name, your address, choose **External**, accept and create. Google only says "OAuth configuration
   created", that is fine.
4. **Branding:** copy home page, privacy policy link, terms of service link and authorized domain from your
   Settings. Leave the logo empty, a logo makes Google review the app first. Save.
5. **Audience:** click **Publish app**. It stays disabled until step 4 is complete.
6. **Clients:** **Create client**, type **Web application**, add the authorized redirect URI from your Settings,
   create. Copy the client ID and the client secret right away, Google may not show the secret again.
7. **Keys:** paste both into **Settings** and save. **Log in**, **Follow** and the like buttons now appear on your
   stage.

If Google says `redirect_uri_mismatch`, the redirect URI in the client differs from the one in your Settings.

## 2. Mails to followers

Followers get a welcome mail and a mail for every new track you publish (not for a replaced one).

1. On the admin page, panel **Mail**, enter an address of your stage's own domain as **Mail sender**, for example
   `music@example.com`, and click **Save**. Create that address at your host if it does not exist. An address of
   another domain, like your Gmail address, does not work: mail services refuse such mails.
2. Click **Send test mail**. It goes to your contact address from the **Owner** panel.
3. If it lands in spam, mark it as not spam. If your host refuses it, ask your host whether PHP may send mails.

## 3. Moderation

Everything is on `admin.php`:

1. **Tracks:** hide a track (it disappears for visitors and its files move to a hidden folder, you still see
   it), show it again, or delete it with its likes and comments.
2. **Comments:** new comments wait under **Waiting for approval**. Approve or delete them. Approved comments
   can be deleted any time.
3. **Users:** everyone who logged in. **Block** removes their likes, comments and follow and stops them from
   liking, commenting and following again.
4. **Followers:** everyone who gets your mails. **Remove** stops the mails for that person.
5. **Settings:** title, token from openDAW, admin password, mail sender and Google keys.

## 4. Updating

### On the admin page

1. Open `admin.php`, section **Updates**, and click **Check for updates**.
2. When a new version is available, read **What is new** and click **Update to …**.
3. Your stage downloads the release from GitHub, checks it, keeps a copy of the current files and replaces them.
   Your tracks, settings and own designs (`config.php`, the `data-…` folder, `media/` and `custom/`) stay as they
   are. If something fails on the way, the previous files are restored.

An update also replaces the `.htaccess` files that come with your stage. If you added rules of your own there (an
HTTPS redirect, a password, an address limit), keep them in your host's settings or in the `.htaccess` of the
folder above your stage instead.

Some releases change more than files. Their release notes say so, and the admin page shows **This update must be
installed by hand** instead of the button. The page also says so when your host cannot unpack or download files.

### By hand

Only when the admin page asks for it:

1. Download the zip of the [latest release](https://github.com/andremichelle/opendaw-stage/releases/latest)
   ("Source code (zip)") and unpack it.
2. Read the release notes and follow any extra steps they name.
3. Upload the contents of its folder to your stage and replace all files, except these, which hold your data:
    1. `config.php`
    2. the `data-…` folder
    3. `media/`
    4. `custom/` (your own templates and styles)
4. Open your stage and the admin page once to check that both load.

## 5. Privacy and imprint

Your stage has its own privacy page (`privacy`) and imprint (`imprint`), linked in the footer. They show the
details you entered under **Settings** and describe what the stage stores: name, profile picture and mail address
of everyone who logs in, their likes, comments and follows. If you need other texts, copy `templates/privacy.html`
or `templates/imprint.html` into `custom/` and change them there.

When someone asks to have their data deleted, block them on the admin page: that removes their likes, comments
and follow.
