# Decide durable Framesmith task persistence model

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Groomed
Grooming priority: Now

## Context

The current Framesmith Phase 1 task store is intentionally lightweight Drupal-state backed storage. That was good enough for smoke/dev and the pragmatic production stabilization milestone.

## Problem

If Framesmith becomes a real product surface with longer-running jobs, user-visible history, retry/recovery, or extraction into its own stack, task state probably needs a durable model rather than ad-hoc state storage.

## Ownership decision - 2026-04-28

Framesmith task persistence belongs to Framesmith, not `compute_orchestrator`, long term.

The current Drupal-state backed task store inside `compute_orchestrator` is transitional. When Framesmith moves to its own project/module, task tracking should move with it. `compute_orchestrator` should expose runtime orchestration operations over a remote contract; Framesmith should own its product-specific task lifecycle and persistence.

A generic compute job/task facility may be designed later if multiple clients need it. Do not treat the current Framesmith task store as that generic facility by default.

## Acceptance criteria

- [x] Decide whether Framesmith tasks need durable entity/table storage in the host phase.
  - Decision: durable task persistence is Framesmith-owned. Host-phase implementation may remain transitional until extraction pressure warrants migration.
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
- [x] Define how durable task state maps to future Framesmith extraction.
  - It moves with Framesmith; compute_orchestrator keeps only runtime orchestration state.
- [ ] Decide whether to implement now or explicitly defer until extraction pressure is clearer.
- [ ] Add tests if implemented.

## Links

- Completed Phase 1 card: `docs/kanban/done/2026-04-28-add-framesmith-drupal-api-backed-by-compute-orchestrator.md`
- Framesmith extraction: `docs/kanban/backlog/25-extract-framesmith-runtime-out-of-bevansbench-monolith.md`
