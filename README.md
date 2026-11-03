<div align="center">

<img src="./public/standalonecoders-mark.svg" alt="StandaloneCoders logo" width="96" />

# StandaloneCoders Cloud

**Deploy apps, databases and services on your own servers, with email built in.**

Built on [Coolify](https://github.com/coollabsio/coolify) and kept up to date with it every day.

</div>

## What you get

Everything Coolify does: deploy from Git or Docker images, managed databases with backups, one-click services,
automatic HTTPS, multiple servers over SSH, teams and an API. On top of that:

- **Built-in mail server.** Add a domain, create mailboxes and aliases, and see which DNS records are still
  missing, all under **Settings > Mail server**. It runs the lightweight [Maddy](https://maddy.email) mail server
  next to the platform, with DKIM, SPF and DMARC, and a Let's Encrypt certificate shared with the proxy.
- **Webmail** at `https://mail.<your-domain>` ([Roundcube](https://roundcube.net)), plus IMAP and SMTP for any mail app.
- **Branded email.** Notifications, invitations and password resets go out from your own `noreply@` mailbox
  in a StandaloneCoders layout with your logo.
- **SMS sign-in codes.** After a correct password, users with a mobile number get a 6-digit code by SMS.
- **StandaloneCoders design**: the Developer Slate theme, refreshed cards, type and motion.
- **One-click updates.** New builds show up under **Settings > Updates**.

## Install

On a fresh Ubuntu or Debian server (2 CPUs, 2 GB RAM, 30 GB disk or more), as root:

```bash
curl -fsSL https://raw.githubusercontent.com/Roastcoder/standalonecoders-cloud/main/custom/install.sh | sudo bash
```

Then open `http://<server-ip>:8000` and create the admin account. The same command switches an existing
Coolify server to StandaloneCoders Cloud; your projects, servers and settings stay.

## Update

Open **Settings > Updates** and click **Update** when a new build is available. Or on the server:

```bash
sudo bash /data/coolify/source/update-custom.sh
```

## Set up email

1. Point an `A` record for `mail.<your-domain>` at your server.
2. Open **Settings > Mail server** and add your domain. The mail server and webmail start on their own.
3. Add the DNS records the page lists (MX, SPF, DKIM, DMARC), then click **Check DNS** until all show **Found**.
4. Set the reverse DNS (PTR) of your server IP to `mail.<your-domain>` at your hosting provider.
5. Create mailboxes, and click **Use for Coolify's emails** so the platform sends from `noreply@<your-domain>`.

Ports 25, 143, 465, 587 and 993 must be open. Some hosting providers block outgoing port 25 until you ask.

## Turn on SMS sign-in codes

Add your SMS gateway settings to `/data/coolify/source/.env`, then run the update command above:

```bash
SMS_BASE_URL=http://sms.example.com
SMS_USER=your-user
SMS_PASSWORD=your-password
SMS_SENDER_ID=SENDER
SMS_ENTITY_ID=your-dlt-entity-id
SMS_TEMPLATE_ID_SIGNUP_OTP=your-dlt-template-id
```

Each user then adds a 10-digit mobile number on their **Profile** page. Users without a number sign in with
their password only. If the gateway is down, users with a number can't sign in; removing `SMS_PASSWORD` and
updating turns the codes off.

## How this repository works

- `main` is Coolify plus our changes. A workflow merges Coolify's `main` into it every day at 03:00 UTC and
  opens an issue named "Upstream sync conflict" when our changes and theirs touch the same lines.
- Every push to `main` builds `ghcr.io/roastcoder/standalonecoders-cloud` for amd64 and arm64.
- Our changes are marked "Fork-only" in the code. More detail is in [custom/README.md](custom/README.md).

## License and credits

StandaloneCoders Cloud is a modified version of [Coolify](https://github.com/coollabsio/coolify) by coolLabs,
released under the [Apache License 2.0](LICENSE). The mail server is [Maddy](https://github.com/foxcpp/maddy)
(GPL-3.0) and webmail is [Roundcube](https://github.com/roundcube/roundcubemail) (GPL-3.0); both run from their
official, unmodified images.
