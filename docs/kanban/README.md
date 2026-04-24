# Kanban Board Scope

This board tracks delivery work for the `bevansbench.com` codebase, including transitional dependencies that still live here.

## Scope

- Host-site delivery work stays on this board.
- Framesmith migration work stays on this board while the runtime is still embedded in this repo.
- Once Framesmith is extracted into its own stack/repo, day-to-day Framesmith delivery should move to that dedicated board.

## Current migration focus

- Make Framesmith functional through a short-term Drupal API backed by `compute_orchestrator`:
  - [32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md](./in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md)

## Migration direction

- Short term: keep Framesmith served from this host and call Drupal endpoints backed by `compute_orchestrator`.
- Medium term: remove active dependence on `video_forge` for Framesmith transcription orchestration.
- Long term: extract Framesmith runtime and shared compute orchestration out of the host-site monolith:
  - [25-extract-framesmith-runtime-out-of-bevansbench-monolith.md](./backlog/25-extract-framesmith-runtime-out-of-bevansbench-monolith.md)

## Working agreement

- Keep `in-progress` intentionally small (target 1-3 cards).
- Keep cards outcome-focused with a clear next action.
- Record incident evidence on the relevant card (commands, key output, commits).
