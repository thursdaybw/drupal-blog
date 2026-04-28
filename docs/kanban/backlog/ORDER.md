# Backlog grooming order

Date opened: 2026-04-28
Status: Living grooming index

## Guiding principle

The project goal is freedom and sovereignty.

The system should make the operator freer, not more trapped. That means functionality is not enough. The system must also become easier to reason about, easier to change, easier to run, easier to move, and harder to accidentally fossilize around today's host, provider, UI, or workaround.

Technical and architectural debt should be paid down sooner rather than later. The only normal exception is genuine crisis mode. Paying debt early helps prevent crisis. Do important work while it is still important, before it becomes urgent.

## How to use this file

The backlog directory is a fishing net. It captures raw items, including rough, duplicate, questionable, and low-confidence items.

This file is the grooming lens. It does not need to list every captured card. It should list the items that have been surfaced, grouped, and ordered enough to guide what to do next.

Useful states:

- `Now` — current focus; high leverage or debt-preventing work.
- `Next` — likely next focus after the current slice.
- `Later` — valid, but not near the top.
- `Capture pool` — captured but not groomed or ranked yet.
- `Rejected / obsolete` — deliberately not doing, with reason.

## Prioritization criteria

Prefer work that increases freedom and sovereignty:

- reduces architectural coupling;
- reduces hidden operational dependence on one host, one provider, one command path, or one human memory path;
- clarifies ownership boundaries;
- makes failure modes easier to understand and recover from;
- turns chat/history knowledge into docs, tests, or runbooks;
- gives confidence without requiring repeated manual debugging;
- prevents today's working transition from becoming tomorrow's trap.

Prefer paying off debt before it becomes urgent:

- architectural seams while they are still small;
- test coverage while the workflow is fresh;
- operator docs immediately after incidents or smoke passes;
- state semantics before more code depends on ambiguous fields;
- provider/launcher/storage abstractions before extraction pressure forces a rushed rewrite.

Do not prioritize only by visible feature output. A feature that increases lock-in or confusion may be negative progress.

## Now: compute_orchestrator freedom / portability / maintainability

This is the first groomed theme after production stabilization.

Reason:

- `compute_orchestrator` is now a shared production runtime path for Framesmith and AI listing.
- That success makes it more valuable and more dangerous: whatever coupling exists here will compound.
- Paying down the architectural debt now protects future extraction and prevents the working Drupal-hosted path from becoming a trap.

### Now candidates

### Current Now progress - 2026-04-28

- Architecture review card `42` is complete and moved to done.
- Operational semantics card `75` is active in progress; first operator-language slice is committed.
- Pool state normalization is active in progress; first state mutation helper slice is committed.
- Do not close `75` or pool state normalization until their remaining checklist items are resolved or explicitly moved to linked follow-up cards.

- `../done/2026-04-28-review-compute-orchestrator-architecture-and-drupal-coupling.md`
  - Status: done.
  - Outcome: architecture seams, Drupal Batch boundary, interface seam review, Framesmith task ownership, and follow-up implementation cards were recorded.

- `../in-progress/75-review-operational-semantics-across-compute-ui-commands-state-and-code.md`
  - Status: in progress.
  - Why now: semantic mismatch creates operator traps and future incidents.
  - Current remaining work: review code paths for hidden divergence and create focused follow-up cards for any mismatches discovered.

- `74-review-compute-task-crud-and-storage-ownership-boundary.md`
  - Grooming intent: review alongside `41-decide-durable-framesmith-task-persistence-model.md`.
  - Why now: task ownership affects whether Framesmith, compute jobs, and Drupal state can be separated cleanly.

- `72-define-drush-launcher-as-swappable-worker-adapter.md`
  - Grooming intent: keep as a separate candidate if the architecture review confirms the launcher seam is active debt.
  - Why now: Drush is acceptable as a current adapter, but bad as an implicit long-term contract.

- `73-define-compute-provider-boundary-beyond-vast-ai.md`
  - Grooming intent: keep as candidate; likely not first implementation, but important design boundary.
  - Why now: provider assumptions become expensive if baked into pool lifecycle/state semantics.

- `64-split-compute-orchestrator-admin-ui-into-optional-ui-layer-if-needed.md`
  - Grooming intent: do not implement immediately by default; use it as an outcome of card `42`.
  - Why now: the UI split may be premature, but the boundary decision is important.

### Related hardening cards to keep visible

- `65-require-semantic-readiness-progress-markers-in-compute-orchestrator.md`
- `66-add-early-gpu-sanity-check-after-ssh.md`
- `67-harden-compute-orchestrator-bootstrap-failure-handling-and-bad-host-policy.md`
- `69-add-stale-leased-job-recovery-with-explicit-heartbeats.md`
- `70-verify-stopped-instance-reuse-after-idle-reap.md`
- `71-add-detached-runner-task-log-visibility.md`
- `../in-progress/normalize-vllm-pool-record-state-fields.md`
- `38-write-vast-pool-operator-runbook-and-manual-recovery-guide.md`

These are probably not all `Now`, but they are part of the same freedom/operability debt field.

## Next: assurance and operator confidence

After the architecture/freedom slice is groomed, the next likely slice is operational assurance:

- `36-commit-true-ui-staging-smoke-gates-for-ai-listing-and-bulk-intake.md`
  - Most of this is now committed; remaining question is whether to re-run after cleanup and then close/supersede.
- `37-verify-cron-sidecar-idle-reap-after-real-production-workload.md`
- `38-write-vast-pool-operator-runbook-and-manual-recovery-guide.md`
- `../in-progress/normalize-vllm-pool-record-state-fields.md`

Reason:

- These reduce incident risk and repeated manual debugging.
- They turn the production stabilization win into durable confidence.

## Next: product extraction boundaries

- `39-reconcile-bb-ai-listing-fork-divergence-against-host-module.md`
- `40-define-long-term-project-boundaries-for-compute-framesmith-and-ai-listing.md`
- `25-extract-framesmith-runtime-out-of-bevansbench-monolith.md`
- `17-define-a-separate-dev-environment-for-the-bb-ai-listing-product.md`
- `18-stand-up-a-standalone-bb-ai-listing-dev-environment-with-docker-compose.md`

Reason:

- Splitting too early can create churn.
- Waiting too long can make the monolith a trap.
- The right next step is boundary clarity before large extraction.

## Later / capture pool

The raw capture cards `46` through `78` remain valid backlog fish, but most are not groomed yet. They should not be treated as ordered priorities just because their filenames are numbered.

During grooming, each capture card should become one of:

- promoted to `Groomed` and added to this file;
- merged into an existing card;
- rejected with reason;
- left in capture pool.

## Immediate grooming checklist

- [x] Read card `42` fully and decide whether it should become the umbrella `Groomed` architecture review.
  - Decision: card `42` is promoted to the groomed `Now` theme as the umbrella architecture review; cards `64`, `72`, `73`, `74`, and `75` remain visible child/seam cards pending full grooming.
- [x] Read cards `64`, `72`, `73`, `74`, and `75` fully before changing their states.
  - Decision: `75` and `74` are `Groomed / Now`; `72` and `73` are `Groomed / Next`; `64` is `Candidate / Later` pending card `42` outcome.
- [x] Decide which cards merge into `42` versus remain separate workstreams.
  - Decision: do not merge them away. `42` is the umbrella; `75`, `74`, `72`, and `73` remain separate child/seam workstreams. `64` remains a candidate outcome, not current implementation work.
- [x] Update each groomed card with `Capture state: Groomed` or equivalent metadata.
  - Updated `42`, `75`, `74`, `72`, and `73`; updated `64` to `Candidate / Later`.
- [ ] Do not move any card to done during grooming unless all remaining work is captured elsewhere and linked.
- [ ] Keep this file updated as the ordered view, separate from capture chronology.
