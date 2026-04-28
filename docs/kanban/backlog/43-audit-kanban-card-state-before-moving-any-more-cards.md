# Audit kanban card state before moving any more cards

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

A bad cleanup attempt tried to move several cards to `done` based on headline outcomes instead of fully reading the cards and preserving all unresolved follow-up work. That is exactly how refactors, cleanups, and architectural obligations disappear.

The attempted moves were reverted before commit. This card records the safer rule and the current audit findings.

## Rule for this cleanup pass

Do not move a card to `done` merely because its title-level outcome appears true.

Before moving any card:

- [ ] read the whole card;
- [ ] identify every unchecked item;
- [ ] identify every `follow-up`, `next action`, `remaining`, `future`, `not yet`, or `open question` section;
- [ ] decide whether each item is actually done, intentionally obsolete, or still required;
- [ ] create or update concrete follow-up cards for every still-required item;
- [ ] cross-link the original card to those follow-ups;
- [ ] only then move the card, and only if the remaining content is explicitly captured elsewhere.

## Immediate repair already performed

- [x] Restored the original locations of:
  - `docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md`
  - `docs/kanban/in-progress/33-review-framesmith-pool-live-smoke-followups.md`
  - `docs/kanban/in-progress/34-add-staging-framesmith-browser-smoke.md`
  - `docs/kanban/backlog/27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md`
  - `docs/kanban/backlog/29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md`
- [x] Removed the untracked duplicate `done/2026-04-28-*` copies created by the bad move.
- [x] Kept the new follow-up cards untracked for review rather than treating them as permission to move cards.

## Current high-risk cards

### `32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md`

Do **not** move this card to done as-is.

Although the production Framesmith path now works, the card contains major unresolved work and design notes, including:

- fake runtime executor / fake lease follow-ups;
- browser automation final-state assertion follow-ups;
- task observability alignment with pool-admin workflow;
- stopped-instance reuse after reap;
- headless `compute_orchestrator` direction;
- architectural portability and Drupal-coupling review;
- Vast.ai provider coupling boundary;
- detached-runner/task-log visibility;
- Drush launcher as temporary adapter only;
- task CRUD/storage ownership boundary;
- bad-host bootstrap failure handling;
- operational semantics as source-of-truth.

Candidate follow-up cards already drafted or existing:

- `docs/kanban/backlog/42-review-compute-orchestrator-architecture-and-drupal-coupling.md`
- `docs/kanban/backlog/41-decide-durable-framesmith-task-persistence-model.md`
- `docs/kanban/backlog/37-verify-cron-sidecar-idle-reap-after-real-production-workload.md`
- `docs/kanban/backlog/38-write-vast-pool-operator-runbook-and-manual-recovery-guide.md`
- `docs/kanban/backlog/normalize-vllm-pool-record-state-fields.md`
- `docs/kanban/in-progress/31-capture-compute-orchestrator-ssh-probe-history-to-local-jsonl-log.md`

Missing or still-needs-explicit-card candidates from `32`:

- [ ] stopped-instance reuse after idle reap;
- [ ] bad-host bootstrap failure handling;
- [ ] detached runner/task-log visibility;
- [ ] operational semantics review across commands, UI labels, state labels, and code paths;
- [ ] fake runtime/fake lease strategy if not already completed in code;
- [ ] Framesmith fake/browser automation final-state assertion if not already completed.

### `29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md`

Do **not** move this card to done until it is split carefully.

Headline Phase 1 is production-proven, but the card itself still says hardening remains, especially:

- fresh fallback persistence;
- idle reap verification;
- supporting Framesmith integration;
- explicit pool operations hardening.

Some follow-ups now exist, but this card needs a deliberate split into:

- [ ] Phase 1 completed milestone or completion note;
- [ ] pool hardening follow-up cards;
- [ ] pool state-model follow-up;
- [ ] provider/price/selection follow-up;
- [ ] operator runbook/recovery follow-up.

### `27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md`

Do **not** move this card to done without updating stale text.

The headline migration is true, but the card has old prerequisite/next-action language pointing at `32`. It needs to be rewritten as either:

- superseded by the production stabilization milestone plus links to follow-ups; or
- moved to done only after all remaining non-migration concerns are clearly transferred elsewhere.

### `34-add-staging-framesmith-browser-smoke.md`

This may be a valid done candidate, but only after reading the follow-up section and deciding where those follow-ups live.

Remaining follow-ups listed in the card:

- decide whether to generate local MP4 fixture automatically;
- prefer `drush uli` login URLs;
- revisit fallback staging credentials if CI-operated.

Potential action:

- [ ] keep card open until these are tracked elsewhere, or
- [ ] move to done with explicit follow-up links if the smoke gate itself is the completed scope.

### `33-review-framesmith-pool-live-smoke-followups.md`

This looks like the safest done candidate.

The card status says review follow-ups resolved, and its checklist is checked. Still, before moving:

- [ ] verify that the durable task persistence follow-up exists and is linked;
- [ ] verify no hidden remaining work is only in prose;
- [ ] then move to done with a short completion note.

## Other active/backlog cards reviewed

These remain valid and should not be moved merely because related production work succeeded:

- `17-define-a-separate-dev-environment-for-the-bb-ai-listing-product.md`
- `18-stand-up-a-standalone-bb-ai-listing-dev-environment-with-docker-compose.md`
- `21-adopt-standalone-bulk-image-intake-architecture-in-host-site-until-retirement.md`
- `22-harden-bulk-intake-chunk-transport-for-50-set-internet-latency-runs.md`
- `23-add-operator-grade-bulk-intake-telemetry-and-recovery-controls.md`
- `24-fix-bulk-intake-post-stage-operator-clarity-and-table-semantics.md`
- `25-extract-framesmith-runtime-out-of-bevansbench-monolith.md`
- `26-harden-framesmith-provisioning-observability-and-preflight-guards.md`
- `28-normalize-ai-inference-publication-year-before-saving-to-listing-fields.md`
- `normalize-vllm-pool-record-state-fields.md`
- `30-cap-generic-vllm-vast-offer-selection-by-max-hourly-price.md`
- `31-capture-compute-orchestrator-ssh-probe-history-to-local-jsonl-log.md`

## Done cards with intentional follow-ups

The production stabilization milestone intentionally lists follow-ups. That is acceptable because it is a milestone card, not a claim that all related work is complete.

However, done cards should not casually contain uncaptured future work. Any older done cards with follow-up references should be checked separately if they become relevant.

## Next action

Before moving any card:

1. Start with `32` because it contains the most buried architecture/refactor work.
2. Extract missing follow-up cards for each unresolved section.
3. Update `32` with explicit links to the extracted follow-ups.
4. Only then decide whether `32` remains in-progress as an umbrella, is rewritten, or is moved to done as a completed Phase 1 with all follow-ups linked.

## Definition of done

- [ ] No card is moved based on headline alone.
- [ ] `32` is fully split or deliberately retained in progress.
- [ ] `27`, `29`, `33`, and `34` are each decided after full-card review.
- [ ] Missing follow-up cards from `32` are created.
- [ ] Cleanup card `35` is updated to reflect actual decisions, not optimistic guesses.
- [ ] Final diff is reviewed before commit.
