# Define long-term project boundaries for compute_orchestrator, Framesmith, and AI listing

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

The current monolith-hosted architecture is now working as a pragmatic transition:

- Framesmith uses Drupal host APIs backed by `compute_orchestrator`.
- AI listing inference uses the same pooled compute runtime.
- Shared runtime control lives in the host repo for now.

Long term, the intended split is into distinct projects/services for `compute_orchestrator`, Framesmith, and AI listing.

## Problem

The split is directionally clear but not yet specified enough to execute safely. The current working production system should be used to inform the boundaries, not destabilized prematurely.

## Acceptance criteria

- [ ] Define the responsibility boundary for `compute_orchestrator`.
- [ ] Define the responsibility boundary for Framesmith.
- [ ] Define the responsibility boundary for AI listing.
- [ ] Identify shared contracts that need versioning or documentation:
  - lease acquire/release;
  - workload switch;
  - task status/result payloads;
  - runtime operator state;
  - smoke-test expectations.
- [ ] Decide what remains in the Drupal host during the transition.
- [ ] Decide the first extraction candidate and why.
- [ ] Define rollback strategy for each extraction phase.
- [ ] Update existing extraction cards with concrete milestones.

## Links

- Framesmith extraction: `docs/kanban/backlog/25-extract-framesmith-runtime-out-of-bevansbench-monolith.md`
- AI listing dev environment: `docs/kanban/backlog/17-define-a-separate-dev-environment-for-the-bb-ai-listing-product.md`
- Standalone AI listing env: `docs/kanban/backlog/18-stand-up-a-standalone-bb-ai-listing-dev-environment-with-docker-compose.md`
- Pool state model: `docs/kanban/in-progress/normalize-vllm-pool-record-state-fields.md`
- Compute architecture/coupling review: `docs/kanban/done/2026-04-28-review-compute-orchestrator-architecture-and-drupal-coupling.md`
