# Extract Framesmith task tracking out of compute_orchestrator

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Groomed
Grooming priority: Now
Source: compute_orchestrator task ownership decision
Confidence: high
Size guess: medium
Urgency: soon
Product area: Framesmith

## Context

Framesmith transcription task tracking currently lives in `compute_orchestrator` through the transitional `FramesmithTranscriptionTaskStore` and related services.

The architecture decision is now explicit: Framesmith task tracking is product-specific and should move to Framesmith-owned code when Framesmith is extracted. A generic compute job facility may be designed later, but should not be created accidentally by promoting the current Framesmith task store.

## Decision

Framesmith owns:

- transcription task identity;
- uploaded media relationship;
- user-facing task state;
- transcript result;
- retry/user history;
- product-specific task lifecycle.

`compute_orchestrator` owns:

- runtime lease acquisition/release;
- workload preparation;
- readiness;
- provider lifecycle;
- pool state;
- diagnostics;
- idle reap behaviour.

## Acceptance criteria

- [ ] Inventory all `Framesmith*` services, routes, commands, tests, and state keys inside `compute_orchestrator`.
- [ ] Decide what moves to the Framesmith project/module and what remains as generic runtime orchestration.
- [ ] Define a migration path for current Drupal-state task records if any need preservation.
- [ ] Replace in-process task-store coupling with calls to the remote runtime orchestration contract.
- [ ] Keep existing production behaviour working during transition.
- [ ] Add or preserve browser smoke coverage for Framesmith transcription after extraction.
- [ ] Remove or deprecate `FramesmithTranscriptionTaskStore` from `compute_orchestrator` once Framesmith owns task persistence.

## Related cards

- `docs/kanban/backlog/25-extract-framesmith-runtime-out-of-bevansbench-monolith.md`
- `docs/kanban/backlog/41-decide-durable-framesmith-task-persistence-model.md`
- `docs/kanban/backlog/74-review-compute-task-crud-and-storage-ownership-boundary.md`
- `docs/kanban/backlog/79-define-remote-runtime-orchestration-contract-for-external-clients.md`
