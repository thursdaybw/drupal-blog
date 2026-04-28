# Compute Orchestrator

Drupal module for provisioning and controlling compute workloads (including a generic vLLM instance pool).

## Configuration notes

This module intentionally sources some runtime prerequisites from the process environment (for local/dev and containerized deployments):

- `VAST_API_KEY`
  - Used to talk to Vast's API.
  - Recommended pattern: set in the container environment and override Drupal config in `settings.php`.
- `VAST_SSH_PRIVATE_KEY_CONTAINER_PATH`
  - Absolute path (inside the running Drupal/PHP container) to the SSH private key used to start workloads on Vast instances.
  - Example: `/run/secrets/vast_ssh_key` (a read-only bind mount).

See `USAGE.md` for operational commands and examples.

## Framesmith Staging Smoke Test

Framesmith is a separate product workflow from AI listing, but it uses this module's pooled compute runtime for Whisper transcription. Its staging smoke belongs here because it verifies the `compute_orchestrator`/`vllm_pool` integration that backs the Framesmith browser API.

Run it from the host with DDEV:

```bash
ddev test-framesmith-staging-smoke
```

What it proves:

- logs into staging with a one-time `drush uli` URL;
- drives the real `/framesmith/` browser UI with Selenium/DTT;
- uploads a video fixture and starts transcription through the UI/API path;
- acquires a pooled Whisper runtime through `compute_orchestrator`;
- completes a transcription task and records the runtime contract id;
- runs staging Drupal cron after the workflow;
- verifies the released warm runtime is reaped back to a stopped pool record.

Useful environment overrides:

```bash
FRAMESMITH_STAGING_BASE_URL=https://bb-drupal-staging.bevansbench.com
FRAMESMITH_STAGING_FIXTURE_PATH=/var/www/html/html/framesmith-browser-smoke.mp4
FRAMESMITH_STAGING_REAP_GRACE_SECONDS=0
```

Safety notes:

- This is a staging/operator assurance test, not a routine fast test.
- The wrapper temporarily shortens the staging idle-reap grace period and restores the previous value on exit.
- The one-time login URL is written to a temporary ignored file and removed after the run.
- The default video fixture path is `html/framesmith-browser-smoke.mp4`.
- That fixture is gitignored as a local/generated artifact; recreate it or override `FRAMESMITH_STAGING_FIXTURE_PATH` in a fresh dev environment.

## Roadmap note: UI submodule

The admin UI (`/admin/compute-orchestrator/*` routes, forms, and Drupal Batch wrappers) currently lives in this module for convenience. A future refactor may move that UI surface into a dedicated optional submodule (for example `compute_orchestrator_ui`) so the core provisioning/orchestration services and Drush commands can remain usable in headless deployments without shipping the UI.
