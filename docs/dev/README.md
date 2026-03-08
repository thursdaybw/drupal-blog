# Local Development Setup (DDEV)

This is the canonical setup guide for local development.

## Prerequisites

- `ddev` installed
- Docker running

## Start The Project

From the repository root:

```bash
ddev start
```

## Install PHP Dependencies

From the repository root:

```bash
ddev composer install
```

## Ensure Content Sync Directory Exists

`content_sync` expects a sync directory path in the app filesystem.
For local dev, keep this directory in the repo at:

- `content/sync`

It maps into the container as:

- `/var/www/html/content/sync`

This repository tracks the directory with `.gitkeep` so new checkouts have it.

## Useful Commands

```bash
ddev drush cr
ddev drush status
ddev describe
```

## Environment Consistency

Staging and production get the same directory pattern via Ansible bootstrap:

- `<workspace>/content/sync`

That keeps local, staging, and production aligned for `content_sync`.
