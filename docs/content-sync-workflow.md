# Content Sync Workflow (Dev -> Staging/Prod)

This runbook documents the target-based content sync commands.

## Quick Staging Test Flow

```bash
# Optional: preview delta first.
ddev content-sync-report-delta --target=staging

# Export dev delta, upload to staging workspace, then import on staging.
ddev content-sync-export-delta --target=staging
ddev content-sync-upload --target=staging
ddev content-sync-import --target=staging
```

## Command Set

1. `ddev content-sync-report-delta`
2. `ddev content-sync-export-delta`
3. `ddev content-sync-upload`
4. `ddev content-sync-import`

All commands support `--target=staging|prod`.

## End-to-End Flow

### 1) Report what differs from target

```bash
ddev content-sync-report-delta --target=staging
```

Optional:

```bash
ddev content-sync-report-delta --target=staging --show-uuids
ddev content-sync-report-delta --target=staging --limit=25
```

### 2) Export all missing + changed listings from dev

```bash
ddev content-sync-export-delta --target=staging
```

Optional:

```bash
ddev content-sync-export-delta --target=staging --dry-run
ddev content-sync-export-delta --target=staging --limit=25
```

Notes:

- This command computes delta and exports each listing graph automatically.
- Output is written to `content/sync/`.

### 3) Upload export payload to target host

```bash
ddev content-sync-upload --target=staging
```

Optional:

```bash
ddev content-sync-upload --target=staging --dry-run
ddev content-sync-upload --target=staging --source=content/sync
```

### 4) Import payload on target

```bash
ddev content-sync-import --target=staging
```

Default import actions are `create,update`.

Optional:

```bash
ddev content-sync-import --target=staging --dry-run
ddev content-sync-import --target=staging --actions=create,update,delete
ddev content-sync-import --target=staging --skiplist
ddev content-sync-import --target=staging --compare-dates
```

## Production Flow

Use the same commands with `--target=prod`.

```bash
ddev content-sync-report-delta --target=prod
ddev content-sync-export-delta --target=prod
ddev content-sync-upload --target=prod
ddev content-sync-import --target=prod
```

## Operational Notes

1. `content-sync-export-delta` clears existing `content/sync/entities` and `content/sync/files` before writing new delta output.
2. If report/export says remote does not have `bb-ai-listing-sync:fingerprint-map`, deploy current code to that target first.
3. Import defaults to `--actions=create,update` to avoid accidental deletes.

## Troubleshooting

### Remote command missing

Symptom:

- `Remote staging/prod does not have bb-ai-listing-sync:fingerprint-map yet.`

Fix:

- Deploy current code to that environment.

### Import cannot find drush binary

The import command uses the configured container path (`../vendor/bin/drush`).

If that path changes in a future image layout, update the `DRUSH_BIN` constant in:

- `.ddev/commands/host/content-sync-import`
- `.ddev/commands/host/content-sync-report-delta`
- `.ddev/commands/host/content-sync-export-delta`

### Permission issues in target `sites/default/files`

Deploy playbook handles file ownership/mode inside the app container after compose up.
