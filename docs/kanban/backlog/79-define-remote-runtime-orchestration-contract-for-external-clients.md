# Define remote runtime orchestration contract for external clients

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Groomed
Grooming priority: Now
Source: compute_orchestrator architecture review
Confidence: high
Size guess: medium
Urgency: soon
Product area: compute_orchestrator

## Context

Framesmith will move to its own project. When that happens, it should talk to `compute_orchestrator` remotely rather than sharing Drupal state or product-specific task storage inside the same module.

`compute_orchestrator` therefore needs a clear remote runtime orchestration contract for external clients such as Framesmith and AI listing.

## Decision

The remote contract should expose runtime orchestration, not product task ownership.

`compute_orchestrator` should provide operations such as:

- acquire runtime lease;
- prepare or switch workload;
- inspect lease/runtime state;
- release lease;
- optionally renew lease;
- inspect operator diagnostics;
- reap idle available runtimes through existing cron/operator paths.

Framesmith should own its transcription tasks and call this contract as a client.

## Acceptance criteria

- [ ] Define client-facing operations and payloads.
- [ ] Define authentication/authorization expectations for external clients.
- [ ] Define lease token or lease identifier semantics.
- [ ] Define runtime endpoint exposure rules and whether clients call runtimes directly or through a gateway.
- [ ] Define error model for unavailable capacity, provisioning pending, provider failure, readiness failure, and stale lease recovery.
- [ ] Define compatibility story for the current Drupal-hosted implementation.
- [ ] Decide whether the initial transport is Hypertext Transfer Protocol routes in Drupal, command invocation, or another remote access shape.
- [ ] Add tests for the chosen contract shape before external Framesmith depends on it.

## Related cards

- `docs/kanban/backlog/42-review-compute-orchestrator-architecture-and-drupal-coupling.md`
- `docs/kanban/backlog/72-define-drush-launcher-as-swappable-worker-adapter.md`
- `docs/kanban/backlog/73-define-compute-provider-boundary-beyond-vast-ai.md`
- `docs/kanban/backlog/74-review-compute-task-crud-and-storage-ownership-boundary.md`
