# openDAW Stage Manual

This manual walks you through everything after the installation (see the [README](README.md)): Google login for
likes, comments and follow, and the mails to your followers. Without these two your stage works as a player.

## 1. Google login

Visitors log in with their Google account to like, comment and follow. For that your stage needs its own
**OAuth client** at Google. It is free and takes about ten minutes.

### 1.1 Create a Google Cloud project

1. Open the [Google Cloud Console](https://console.cloud.google.com/) and log in with your Google account.
2. Click the project picker at the top, then **New Project**.
3. Name it after your stage, for example `My Stage`, and click **Create**.
4. Make sure the new project is selected in the project picker.

### 1.2 Set up the consent screen

This is what visitors see when they log in: the name of your stage and the data it asks for.

1. Open the menu, **APIs & Services**, then **OAuth consent screen** (newer consoles call it **Google Auth
   Platform**). Click **Get started** if Google asks.
2. **App name:** the title of your stage. **User support email:** your address.
3. **Audience:** choose **External**, so every Google account can log in, not only your own.
4. **Contact information:** your address again. Accept the policy and click **Create**.
5. Under **Audience**, click **Publish app** and confirm. While the app is in "Testing", only the test users you
   list there can log in.

Your stage asks only for name, profile picture and mail address (the scopes `openid`, `email` and `profile`).
These basic scopes need no review by Google.

### 1.3 Create the OAuth client

1. Go to **Clients** (older consoles: **Credentials**, then **Create credentials**, **OAuth client ID**).
2. Click **Create client**. **Application type:** **Web application**. **Name:** for example `Stage`.
3. Under **Authorized redirect URIs** click **Add URI** and enter the address that your admin page shows under
   **Settings**, for example:

   ```
   https://example.com/music/oauth.php
   ```

   It must match exactly: `https`, the same domain (with or without `www`, as your visitors use it) and the
   same folder.
4. Click **Create**. Google shows the **Client ID** and the **Client secret**. Copy both. Newer consoles show
   the secret only once, download the JSON file if you want to keep it.

### 1.4 Enter the keys

1. Open `admin.php` on your stage and log in.
2. Under **Settings**, paste the **Google client id** and the **Google client secret** and click **Save**.

### 1.5 Check it

1. Open your stage in a private browser window. **Log in**, **Follow** and the like buttons appear now.
2. Click **Log in**, choose a Google account and allow the access. You land back on your stage, logged in.
3. Like a track, write a comment. The comment shows "Waiting for approval".
4. On the admin page, under **Comments**, approve it. Everybody sees it now.

### When something goes wrong

| what you see | why | what to do |
|---|---|---|
| Google says `redirect_uri_mismatch` | the redirect URI in the client differs from your stage | copy the address from **Settings** into the client, exactly |
| Google says the app is blocked or only for test users | the consent screen is still in Testing | **Audience**, **Publish app** |
| "Login failed. Google did not confirm the login." | wrong client secret, or your server cannot reach Google | paste the secret again; ask your host whether PHP may open outgoing HTTPS connections |
| "Login failed. The login did not complete." | the login took too long, or cookies are blocked | try again, allow cookies for your stage |

## 2. Mails to followers

When a visitor follows your stage, they get a welcome mail right away. Whenever you publish a **new** track
(not when you replace one), every follower gets a mail with the link. Every mail has an unfollow link.

Your stage sends these mails with PHP's built-in `mail()`, which shared hosts provide. There is nothing to
install, but a few settings decide whether the mails arrive or land in spam.

### 2.1 Choose the sender address

1. Create a mailbox or an address on **your own domain** at your host, for example `music@example.com`. A real
   mailbox is best, because some followers reply.
2. On the admin page, under **Settings**, enter it as **Mail sender** and click **Save**.

Without a sender your stage uses `stage@` plus your domain. Mails from addresses that do not belong to your
domain are almost always marked as spam, or refused by the host.

### 2.2 Check your domain (SPF)

Mail providers trust a mail more when your domain says that your host may send for it. This is the **SPF**
record in the DNS settings of your domain.

1. Most hosts set it up for you when the domain and the webspace are at the same host. You find it in the DNS
   settings as a `TXT` record that starts with `v=spf1`.
2. If there is none, your host's help pages name the exact record to add (search for "SPF" and the host's
   name).

### 2.3 Check it

1. Follow your own stage with a Google account whose mail you can read.
2. The welcome mail arrives within a few minutes. If it is in the spam folder, mark it as **not spam** and add
   the sender to your contacts. The welcome mail asks every follower to do the same.
3. Publish a new track from openDAW. Every follower gets a mail with its link.

### When something goes wrong

| what you see | why | what to do |
|---|---|---|
| no mail at all | the host blocks `mail()` or refuses the sender | use an address of your domain; ask your host whether PHP `mail()` is enabled |
| mails land in spam | no SPF record, or a sender of another domain | add the SPF record, use your own domain as sender |
| some followers get nothing | their provider rejects mails without SPF or DKIM | add SPF, ask your host about DKIM |

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

## 4. Privacy

Your stage stores the name, profile picture and mail address of everyone who logs in, in the data folder on your
webspace. Followers' addresses are used only for the mails about new tracks. Mention this in the privacy notice
of your website, and delete a user's data on request (block removes their likes, comments and follow, the user
entry itself stays so the block keeps working).
