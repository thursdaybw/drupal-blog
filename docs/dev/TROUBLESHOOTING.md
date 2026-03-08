# Dev Troubleshooting

## Error: `The content directory type 'sync' does not exist`

Cause:

- The expected sync directory is missing in local dev.

Fix:

```bash
mkdir -p content/sync
```

Then clear caches:

```bash
ddev drush cr
```
