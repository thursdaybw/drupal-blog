# Kanban Board Scope

This board tracks delivery work for the `bevansbench.com` codebase, including transitional dependencies that still live here.

## Scope

- Host-site delivery work stays on this board.
- Framesmith migration work stays on this board while the runtime is still embedded in this repo.
- Once Framesmith is extracted into its own stack/repo, day-to-day Framesmith delivery should move to that dedicated board.

## Current migration focus

- Extract Framesmith runtime out of the monolith:
  - [25-extract-framesmith-runtime-out-of-bevansbench-monolith.md](./backlog/25-extract-framesmith-runtime-out-of-bevansbench-monolith.md)

## Working agreement

- Keep `in-progress` intentionally small (target 1-3 cards).
- Keep cards outcome-focused with a clear next action.
- Record incident evidence on the relevant card (commands, key output, commits).
