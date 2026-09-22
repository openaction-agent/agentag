# AgentTag Production Host Setup

This directory contains a host-based production deployment for Ubuntu 24.04 with nginx and PHP-FPM. It intentionally does not include Docker production images and does not install PostgreSQL. Point `DATABASE_URL` at the PostgreSQL service you already run, for example through Coolify.

The nginx and PHP-FPM settings are adapted from the `openaction/docker-php` PHP 8.4 configs, but shaped for a normal host install:

- `prod/nginx/agentag.conf` -> `/etc/nginx/sites-available/agentag.conf`
- `prod/php/agentag.ini` -> `/etc/php/8.4/fpm/conf.d/99-agentag.ini`
- `prod/php-fpm/agentag.conf` -> `/etc/php/8.4/fpm/pool.d/agentag.conf`
- `prod/systemd/agentag-worker@.service` -> `/etc/systemd/system/agentag-worker@.service`
- `prod/systemd/agentag-ack-worker.service` -> `/etc/systemd/system/agentag-ack-worker.service`

PHP-FPM runs web requests as `www-data` and only enqueues work. Messenger workers run as `root` with `HOME=/root` and `CODEX_HOME=/root/.codex`; they are the only production processes that launch `codex exec`, so Codex and every command Codex starts run as root on the host. Keep `MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0` in production; using an in-memory/synchronous transport would execute runs inside PHP-FPM instead.

## 1. Install System Packages

Run as root or with `sudo`.

```bash
apt-get update
apt-get install -y software-properties-common ca-certificates curl gnupg unzip git openssh-client acl nginx
add-apt-repository -y ppa:ondrej/php
apt-get update
apt-get install -y \
  php8.4-cli \
  php8.4-fpm \
  php8.4-apcu \
  php8.4-curl \
  php8.4-mbstring \
  php8.4-opcache \
  php8.4-pgsql \
  php8.4-readline \
  php8.4-xml \
  php8.4-zip
```

Install Composer:

```bash
EXPECTED_SIGNATURE="$(curl -fsSL https://composer.github.io/installer.sig)"
php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');"
ACTUAL_SIGNATURE="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
test "$EXPECTED_SIGNATURE" = "$ACTUAL_SIGNATURE"
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm /tmp/composer-setup.php
composer --version
```

Install Node.js and Codex system-wide for the root workers. The explicit prefix matters on hosts where root also has NVM: systemd uses `/usr/bin/codex`, not root's interactive NVM binary.

```bash
curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
apt-get install -y nodejs
/usr/bin/npm install -g --prefix /usr @openai/codex
env PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin codex --version
```

Authenticate Codex as root. Use the authentication method you normally use for the Codex CLI:

```bash
codex login
```

## 2. Deploy The Application

Create the runtime directories:

```bash
mkdir -p /srv/agentag/app /srv/agentag/workspace /srv/agentag/runs /srv/agentag/artifacts
```

Clone the app:

```bash
git clone git@github.com:openaction-codex/agentag.git /srv/agentag/app
cd /srv/agentag/app
```

Create the workspace template. Put your real `AGENTS.md`, skills, Codex plugins, MCP config, and shared docs here.

```bash
cat > /srv/agentag/workspace/AGENTS.md <<'EOF'
Answer only in French or English. Use English only when the latest user message is confidently English; otherwise use French. Keep Mattermost updates concise. Ask for confirmation before pushing main, force pushing, deleting, overwriting, or other destructive changes.
Complete each request directly in the current Codex session.
Document available repositories and clone instructions here. Clone repositories into the session workspace when needed.
EOF
```

Create production environment overrides. Replace placeholders with real values. PostgreSQL is not installed by this guide; use the DSN from your existing PostgreSQL service.

```bash
install -m 0640 -o root -g www-data /dev/null /srv/agentag/app/.env.local
cat > /srv/agentag/app/.env.local <<'EOF'
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=change-this
DEFAULT_URI=https://agentag.example.com
DATABASE_URL="postgresql://user:password@127.0.0.1:5432/agentag?serverVersion=16&charset=utf8"
MESSENGER_TRANSPORT_DSN=doctrine://default?auto_setup=0

AGENTAG_TAG=@Codex
AGENTAG_WORKSPACE_PATH=/srv/agentag/workspace
AGENTAG_CONTEXT_MAX_CHARS=12000
AGENTAG_RUN_TIMEOUT_SECONDS=1200
AGENTAG_REDACTION_PATTERNS=
AGENTAG_MODEL_SELECTION_MODEL=gpt-5.6-luna
AGENTAG_MODEL_SELECTION_TIMEOUT_SECONDS=20
AGENTAG_TASK_DEADLINE_SECONDS=86400
AGENTAG_MAX_RETRIES=2
AGENTAG_RETRY_DELAY_SECONDS=60
AGENTAG_NOTIFICATION_PREFERENCE=milestones

GITHUB_PAT_TOKEN=github_pat_change_this
MATTERMOST_WEBHOOK_TOKEN=change-this
MATTERMOST_BASE_URL=https://mattermost.example.com
MATTERMOST_BOT_TOKEN=change-this
MATTERMOST_RECENT_REPLY_LIMIT=20
EOF
chown root:www-data /srv/agentag/app/.env.local
chmod 0640 /srv/agentag/app/.env.local
```

The worker passes `GITHUB_PAT_TOKEN` to each Codex CLI process. Keep it in `.env.local`; systemd workers do not source `/root/.bashrc`.

### MCP startup timeout and alerts

Codex allows 10 seconds for an MCP server to initialize by default. For a remote server that needs more time, add `startup_timeout_sec = 60` to its existing `[mcp_servers.<name>]` section in `/root/.codex/config.toml`, for example:

```toml
[mcp_servers.oa-ecologistes]
url = "https://administrer.lesecologistes.fr/mcp"
startup_timeout_sec = 60
```

When Codex reports that an MCP server did not start, AgentTag posts a warning in the Mattermost thread and continues the task without that server's tools. It never includes the raw startup error in Mattermost because it can contain sensitive connection details.

Install PHP dependencies. Disable Composer auto-scripts here so `cache:clear` is not run as root and does not create a root-owned Symfony cache.

```bash
cd /srv/agentag/app
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader --no-scripts
```

Set write permissions. PHP-FPM needs write access for Symfony cache/logs and for initial session workspace preparation. Codex itself is launched only by the root worker and can write anywhere root can write.

```bash
mkdir -p /srv/agentag/app/var/log
touch /srv/agentag/app/var/log/prod.log
chown -R root:www-data /srv/agentag
chown -R root:www-data /srv/agentag/app/public
chown -R www-data:www-data /srv/agentag/app/var /srv/agentag/runs /srv/agentag/artifacts
find /srv/agentag -type d -exec chmod 0750 {} \;
find /srv/agentag -type f -exec chmod 0640 {} \;
chmod +x /srv/agentag/app/bin/console
```

Configure SSH for root if your workspace `AGENTS.md` asks Codex to clone private repositories.

```bash
mkdir -p /root/.ssh
chmod 0700 /root/.ssh
ssh-keyscan github.com >> /root/.ssh/known_hosts
chmod 0644 /root/.ssh/known_hosts
```

Add your deploy key or configure your SSH agent, then verify:

```bash
ssh -T git@github.com || true
```

## 3. Install Host Config

Copy the provided configuration:

```bash
cp /srv/agentag/app/prod/php/agentag.ini /etc/php/8.4/fpm/conf.d/99-agentag.ini
cp /srv/agentag/app/prod/php-fpm/agentag.conf /etc/php/8.4/fpm/pool.d/agentag.conf
cp /srv/agentag/app/prod/nginx/agentag.conf /etc/nginx/sites-available/agentag.conf
cp /srv/agentag/app/prod/systemd/agentag-worker@.service /etc/systemd/system/agentag-worker@.service
cp /srv/agentag/app/prod/systemd/agentag-ack-worker.service /etc/systemd/system/agentag-ack-worker.service
```

Edit the nginx hostname:

```bash
sed -i 's/agentag.example.com/YOUR_DOMAIN_HERE/g' /etc/nginx/sites-available/agentag.conf
```

Disable the default nginx site and enable AgentTag:

```bash
rm -f /etc/nginx/sites-enabled/default
ln -sf /etc/nginx/sites-available/agentag.conf /etc/nginx/sites-enabled/agentag.conf
```

Validate config:

```bash
php8.4 -v
php8.4 -m | sort
php-fpm8.4 -t
nginx -t
```

## 4. Initialize The App

Run database migrations against your existing PostgreSQL service:

```bash
cd /srv/agentag/app
runuser -u www-data -- env APP_ENV=prod APP_DEBUG=0 php8.4 bin/console doctrine:migrations:migrate --no-interaction
```

Warm Symfony cache and validate AgentTag configuration:

```bash
runuser -u www-data -- env APP_ENV=prod APP_DEBUG=0 php8.4 bin/console cache:clear
runuser -u www-data -- env APP_ENV=prod APP_DEBUG=0 php8.4 bin/console agentag:config:validate
```

Start services:

```bash
systemctl daemon-reload
systemctl enable --now php8.4-fpm
systemctl restart php8.4-fpm
systemctl enable --now nginx
systemctl restart nginx
systemctl disable --now agentag-worker || true
systemctl enable --now agentag-ack-worker
systemctl disable --now agentag-worker@5 agentag-worker@6 || true
systemctl enable --now agentag-worker@1 agentag-worker@2 agentag-worker@3 agentag-worker@4
```

The web process creates the pending task card immediately. The dedicated preparation worker then runs the ephemeral Luna/low model selector without waiting behind long jobs. The four `agentag-worker@N` instances execute tasks directly on the selected profile and allow four different chat threads to run at the same time. AgentTag still serializes work inside one thread.

Check service state and HTTP endpoints:

```bash
systemctl status php8.4-fpm --no-pager
systemctl status nginx --no-pager
systemctl status agentag-worker@1 agentag-worker@2 agentag-worker@3 agentag-worker@4 --no-pager
systemctl status agentag-ack-worker --no-pager
systemctl show agentag-worker@1 -p User -p Group -p Environment
curl -i http://YOUR_DOMAIN_HERE/health
curl -i http://YOUR_DOMAIN_HERE/ready
```

Check logs:

```bash
journalctl -u agentag-ack-worker -u agentag-worker@1 -u agentag-worker@2 -u agentag-worker@3 -u agentag-worker@4 -f
tail -f /srv/agentag/app/var/log/prod.log
tail -f /var/log/nginx/agentag.error.log
tail -f /var/log/php8.4-fpm-agentag.slow.log
```

For a webhook HTTP 500, check both `tail -f /srv/agentag/app/var/log/prod.log` and `journalctl -u php8.4-fpm -f` while repeating the request.

If Mattermost typing indicators do not appear, verify `MATTERMOST_BASE_URL`, `MATTERMOST_BOT_TOKEN`, and that the bot can access the channel. Failed Mattermost API calls are logged in `prod.log` and the relevant systemd journal.

## 5. Mattermost

Configure a Mattermost outgoing webhook or integration to send requests to:

```text
http://YOUR_DOMAIN_HERE/integrations/mattermost/webhook
```

Use HTTPS if TLS is terminated on this nginx instance or upstream from it. Set the Mattermost token in `MATTERMOST_WEBHOOK_TOKEN`.

`DEFAULT_URI` must use that same public HTTPS origin. Task-card buttons call `POST /integrations/mattermost/action`; if AgentTag resolves to a private address, add it to Mattermost's `AllowedUntrustedInternalConnections`. The Mattermost bot must be allowed to create and edit its own posts.

## 6. Deploy Updates

Pull code and update dependencies:

```bash
cd /srv/agentag/app
git pull --ff-only
APP_ENV=prod APP_DEBUG=0 composer install --no-dev --optimize-autoloader --no-scripts
chown -R root:www-data /srv/agentag /srv/agentag/app/public
chown -R www-data:www-data /srv/agentag/app/var /srv/agentag/runs /srv/agentag/artifacts
find /srv/agentag -type d -exec chmod 0750 {} \;
find /srv/agentag -type f -exec chmod 0640 {} \;
chmod +x /srv/agentag/app/bin/console
runuser -u www-data -- env APP_ENV=prod APP_DEBUG=0 php8.4 bin/console doctrine:migrations:migrate --no-interaction
runuser -u www-data -- env APP_ENV=prod APP_DEBUG=0 php8.4 bin/console cache:clear
```

Restart services so PHP-FPM OPcache and the worker see the new code:

```bash
systemctl restart php8.4-fpm
systemctl restart agentag-ack-worker
systemctl restart agentag-worker@1 agentag-worker@2 agentag-worker@3 agentag-worker@4
nginx -t && systemctl reload nginx
```

Update `/srv/agentag/workspace` manually whenever you change your shared instructions, skills, Codex plugins, MCP config, or docs.

## 7. Cleanup Old Runtime Directories

Dry run first:

```bash
cd /srv/agentag/app
APP_ENV=prod APP_DEBUG=0 php8.4 bin/console agentag:workspace:cleanup --older-than-days=14
```

Delete matching old isolated workspaces/artifacts:

```bash
APP_ENV=prod APP_DEBUG=0 php8.4 bin/console agentag:workspace:cleanup --older-than-days=14 --force
```

Cleanup never deletes database run, session, or event history. A stopped task's workspace is advertised as retained for 24 hours, so do not use an `--older-than-days` value below 1.
