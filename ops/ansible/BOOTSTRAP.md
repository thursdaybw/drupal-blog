# Ansible Host Bootstrap

This file tracks one-time manual steps that must be done on the VPS before
Ansible can manage system-level configuration safely.

## Bootstrap sudo for bevan

Why:
- Ansible tasks for nginx, TLS, firewall, and service management need sudo.
- We want non-interactive runs, so `sudo -n` must work for `bevan`.

### 1) Create sudoers entry (on VPS)

Run as `root` (or via a root shell), then use `visudo`:

```bash
visudo -f /etc/sudoers.d/bevan-ansible
```

Add:

```sudoers
Cmnd_Alias BB_NGINX = /usr/sbin/nginx -t, /usr/bin/systemctl reload nginx, /usr/bin/systemctl status nginx
Cmnd_Alias BB_CERTBOT = /usr/bin/certbot, /usr/bin/certbot --version
Cmnd_Alias BB_DOCKER = /usr/bin/docker ps, /usr/bin/docker load *, /usr/bin/docker image inspect *, /usr/bin/docker compose *, /usr/bin/docker exec *
Cmnd_Alias BB_NGINX_WRITE = /usr/bin/tee /etc/nginx/sites-available/*
Cmnd_Alias BB_NGINX_LINK = /usr/bin/ln -sfn /etc/nginx/sites-available/* /etc/nginx/sites-enabled/*
Cmnd_Alias BB_BIND_MOUNT_PREP = /usr/bin/install -d -o bevan -g bevan -m 0755 /home/bevan/workspace/bb-platform-prod/html/sites/default/files, /usr/bin/install -d -o bevan -g bevan -m 0755 /home/bevan/workspace/bb-platform-prod/content, /usr/bin/install -d -o bevan -g bevan -m 0755 /home/bevan/workspace/bb-platform-prod/content/sync, /usr/bin/install -d -o bevan -g bevan -m 0755 /home/bevan/workspace/bb-platform-staging/html/sites/default/files, /usr/bin/install -d -o bevan -g bevan -m 0755 /home/bevan/workspace/bb-platform-staging/content, /usr/bin/install -d -o bevan -g bevan -m 0755 /home/bevan/workspace/bb-platform-staging/content/sync
bevan ALL=(root) NOPASSWD: BB_NGINX, BB_CERTBOT, BB_DOCKER, BB_NGINX_WRITE, BB_NGINX_LINK, BB_BIND_MOUNT_PREP
```

Set secure permissions:

```bash
chmod 0440 /etc/sudoers.d/bevan-ansible
```

### 2) Verify from laptop

```bash
ansible -i ops/ansible/inventory.ini bb-drupal-prod -m ansible.builtin.command -a "sudo -n true"
```

Expected:
- command succeeds with return code 0.

Verify bind-mount directory prep too:

```bash
ansible -i ops/ansible/inventory.ini bb-drupal-prod -m ansible.builtin.command -a "sudo -n install -d -o bevan -g bevan -m 0755 /home/bevan/workspace/bb-platform-prod/content/sync"
```

Expected:
- command succeeds with return code 0.

## Staging env file

Why:
- Staging compose uses `.env` values for MySQL initialization and app runtime.
- `deploy-activate` now copies a local env file from your laptop to the VPS.

### 1) Create local staging env file (on laptop)

Create from template:

```bash
cp ops/compose/staging/.env.example ops/compose/staging/.env
```

Fill real values for:
- `MYSQL_ROOT_PASSWORD`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `MYSQL_DATABASE`
- `EBAY_CONNECTOR_ENVIRONMENT`
- `EBAY_CONNECTOR_PRODUCTION_CLIENT_ID`
- `EBAY_CONNECTOR_PRODUCTION_CLIENT_SECRET`
- `EBAY_CONNECTOR_PRODUCTION_RU_NAME`

Notes:
- `ops/compose/staging/.env` is gitignored.
- `APP_IMAGE` is auto-updated by `deploy-activate`.
- `APP_PORT` and `MYSQL_VOLUME_NAME` are auto-managed by Ansible for staging.
- `ebay_connector.settings` is config-ignored and runtime-overridden from env.

### 2) Run activate/deploy

```bash
ddev deploy-activate
```

or full pipeline:

```bash
ddev deploy
```

### 3) If activation fails on missing env keys

Re-open `ops/compose/staging/.env` and ensure all required keys above are set to non-empty values, then rerun.

## Staging DB import

Why:
- After first staging bring-up, Drupal points to `/core/install.php` until a DB is loaded.

Command:

```bash
ddev deploy-db-staging
```

What it does:
- exports current local dev DB to `.ddev/.artifacts/staging-db-current.sql.gz` (if no file is provided),
- uploads the dump to the staging host,
- imports it into staging MySQL.

Optional:

```bash
ddev deploy-db-staging --sql-file=/path/to/dump.sql.gz
```

## Production env file

Create local prod env file from template:

```bash
cp ops/compose/prod/.env.example ops/compose/prod/.env
```

Fill real values for:
- `MYSQL_ROOT_PASSWORD`
- `MYSQL_USER`
- `MYSQL_PASSWORD`
- `MYSQL_DATABASE`
- `EBAY_CONNECTOR_ENVIRONMENT`
- `EBAY_CONNECTOR_PRODUCTION_CLIENT_ID`
- `EBAY_CONNECTOR_PRODUCTION_CLIENT_SECRET`
- `EBAY_CONNECTOR_PRODUCTION_RU_NAME`

Notes:
- `ops/compose/prod/.env` is gitignored.
- `APP_IMAGE` is auto-updated by `deploy-prod-activate`.
- `APP_PORT` and `MYSQL_VOLUME_NAME` are auto-managed by Ansible for prod.
- `ebay_connector.settings` is config-ignored and runtime-overridden from env.

## Production deployment

Build/upload/activate production stack (container + DB service + nginx/TLS):

```bash
ddev deploy-prod
```

Or step-by-step:

```bash
ddev deploy-build
ddev deploy-upload
ddev deploy-prod-activate
```

Deploy behavior:
- `deploy-prod` now prints the resolved local git context before running phases.
- `deploy-build` derives the image tag from the current local `HEAD`.
- Existing artifacts are reused only when a matching `.meta` sidecar proves they were built for the same `HEAD`.
- If artifact metadata is missing or mismatched, deploy fails hard instead of reusing a stale artifact.
- If local `HEAD` is ahead of `origin/main`, deploy prints a warning and still uses the local committed `HEAD`.
- Use `--force-rebuild` only when you explicitly want to replace the current artifact for the resolved `HEAD`.

## Production DB import

Import current local dev DB into production DB container:

```bash
ddev deploy-db-prod
```

Optional file override:

```bash
ddev deploy-db-prod --sql-file=/path/to/dump.sql.gz
```
