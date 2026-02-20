# Compute Orchestrator Usage Guide

## Environment prerequisites
- `VAST_API_KEY` must be set in the runtime (host and DDEV) before running `drush compute:test-vast`.
- `VAST_SSH_KEY_PATH` should point to the private key registered with Vast (default `~/.ssh/id_rsa_vastai`). Export it inside `ddev ssh` or configure `.ddev/.env` so the key is available to both the REST probes and your manual SSH attempts.

## Running the validation command
```
ddev drush cr
# inside ddev ssh or direct drush run if env vars are set:
export VAST_SSH_KEY_PATH=/home/bevan/.ssh/id_rsa_vastai
drush compute:test-vast
```
- This command provision a temporary instance, waits for SSH/vLLM readiness, then destroys the instance on success.

## Strictness levels
The command reads `compute_orchestrator.strictness` (default `strict`) from Drupal state.
- `strict` → reliability ≥ 0.995, port requirement ≥ 16, favors successful hosts.
- `balanced` → reliability ≥ 0.98, port requirement ≥ 8.
- `aggressive` → reliability ≥ 0.95, port requirement ≥ 4, does not favor success history.

Change level with `drush state:set compute_orchestrator.strictness balanced` (or `aggressive`).

## Inspecting host reputation
```
ddev drush compute:bad-hosts      # list persistently bad hosts
ddev drush compute:bad-hosts --clear  # reset list
```

The module also tracks host stats in `compute_orchestrator.host_stats` and infrastructure fatal hosts in `compute_orchestrator.global_bad_hosts`.

## Notes
- The command uses TinyLlama for infrastructure validation, but the `createOptions` block can be updated later to point to heavier models once infrastructure proves stable.
- Logs are timestamped and include SSH/vLLM diagnostics to help identify GPU/CUDA startup issues.
