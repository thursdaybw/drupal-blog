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

## Roadmap note: UI submodule

The admin UI (`/admin/compute-orchestrator/*` routes, forms, and Drupal Batch wrappers) currently lives in this module for convenience. A future refactor may move that UI surface into a dedicated optional submodule (for example `compute_orchestrator_ui`) so the core provisioning/orchestration services and Drush commands can remain usable in headless deployments without shipping the UI.
