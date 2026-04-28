# Decide durable Framesmith task persistence model

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

The current Framesmith Phase 1 task store is intentionally lightweight Drupal-state backed storage. That was good enough for smoke/dev and the pragmatic production stabilization milestone.

## Problem

If Framesmith becomes a real product surface with longer-running jobs, user-visible history, retry/recovery, or extraction into its own stack, task state probably needs a durable model rather than ad-hoc state storage.

## Acceptance criteria

- [ ] Decide whether Framesmith tasks need durable entity/table storage in the host phase.
- [ ] Define required task lifecycle fields:
  - task id;
  - owner/session/user relationship;
  - source file metadata;
  - lease id / pool record reference;
  - status;
  - result payload;
  - failure payload;
  - timestamps;
  - retry/recovery markers.
- [ ] Define retention and cleanup policy.
- [ ] Define how durable task state maps to future Framesmith extraction.
- [ ] Decide whether to implement now or explicitly defer until extraction pressure is clearer.
- [ ] Add tests if implemented.

## Links

- Completed Phase 1 card: `docs/kanban/done/2026-04-28-add-framesmith-drupal-api-backed-by-compute-orchestrator.md`
- Framesmith extraction: `docs/kanban/backlog/25-extract-framesmith-runtime-out-of-bevansbench-monolith.md`
