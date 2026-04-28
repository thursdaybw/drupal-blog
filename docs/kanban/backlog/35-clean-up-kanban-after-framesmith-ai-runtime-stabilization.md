# Clean up kanban after Framesmith and AI runtime stabilization

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

The project state changed materially after the latest staging and production validation pass.

Current confirmed production/staging reality:

- Framesmith works on production.
- Framesmith now uses `compute_orchestrator` / `vllm_pool` for Whisper runtime control.
- Framesmith no longer depends on the legacy `video_forge` Vast provisioning path for the active flow.
- AI listing image inference works through the real Workbench UI batch path.
- A 60-listing staging Workbench UI inference stress run passed.
- Bulk image intake has a true browser/UI staging smoke that passed.
- Staging and production now share the same Docker Compose cron sidecar.
- Drupal cron runs from the shared release image every 60 seconds.
- Manual prod cron verified that idle/released Vast runtimes are reaped correctly.

The kanban board predates this milestone. Several cards still describe Framesmith migration and pool integration as future work, while the remaining real work is now board hygiene, operator hardening, smoke-test commit selection, and longer-term project extraction planning.

## Why

Without a cleanup pass, the board will mislead future work:

- completed migration work will remain in `in-progress`;
- stale backlog cards will continue to imply untrue blockers;
- real follow-up work will stay buried inside old cards;
- the long-term split into `compute_orchestrator`, Framesmith, and AI listing projects will be harder to plan;
- the uncommitted true UI smoke tests may be forgotten even though they proved valuable.

## Outcome

The kanban accurately reflects the current state:

- production stabilization is recorded as a milestone;
- completed Framesmith/compute-orchestrator migration cards are moved or rewritten;
- remaining hardening work is split into clear follow-up cards;
- AI listing, bulk intake, Framesmith, and compute pool concerns are grouped into sensible lanes;
- uncommitted smoke-test assets are reviewed and either committed or explicitly discarded.

## Cleanup checklist

### 1. Record the stabilization milestone

Milestone card added: `docs/kanban/done/2026-04-28-stabilize-framesmith-and-ai-listing-on-compute-orchestrator.md`.

- [x] Add a done/milestone card summarizing the production stabilization:
  - Framesmith prod path works.
  - AI listing inference works.
  - `compute_orchestrator` / `vllm_pool` supports both Whisper and Qwen workloads.
  - shared Compose cron sidecar is deployed to staging and prod.
  - idle Vast reap works when Drupal cron runs.
- [x] Include the key validation evidence:
  - Framesmith prod manual run succeeded.
  - staging Framesmith smoke passed.
  - staging bulk image intake UI smoke passed.
  - staging single-listing, 10-listing, and 60-listing AI inference UI batch runs passed.

### 2. Move or rewrite stale in-progress cards

Review these cards and move them to `done` or rewrite as narrower follow-ups:

- [ ] `docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md`
  - Likely action: move to done as "pragmatic Phase 1 completed".
  - Split leftover items into follow-up cards if still important.
- [ ] `docs/kanban/in-progress/33-review-framesmith-pool-live-smoke-followups.md`
  - Likely action: move to done.
- [ ] `docs/kanban/in-progress/34-add-staging-framesmith-browser-smoke.md`
  - Likely action: move to done or update with prod/staging cron sidecar note.
- [ ] `docs/kanban/backlog/27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md`
  - Likely action: move to done; active Framesmith path has migrated.
- [ ] `docs/kanban/backlog/29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md`
  - Likely action: mark Phase 1 done and split remaining hardening into separate cards.

### 3. Preserve still-valid strategic cards

Keep these, but update their context if needed:

- [ ] `docs/kanban/backlog/25-extract-framesmith-runtime-out-of-bevansbench-monolith.md`
  - Still valid as the long-term extraction target.
- [ ] `docs/kanban/backlog/17-define-a-separate-dev-environment-for-the-bb-ai-listing-product.md`
  - Still valid for product boundary work.
- [ ] `docs/kanban/backlog/18-stand-up-a-standalone-bb-ai-listing-dev-environment-with-docker-compose.md`
  - Still valid, especially because the AI listing fork may already be diverging.
- [ ] `docs/kanban/backlog/21-adopt-standalone-bulk-image-intake-architecture-in-host-site-until-retirement.md`
  - Still valid, but update with the new browser/UI smoke evidence.
- [ ] `docs/kanban/backlog/22-harden-bulk-intake-chunk-transport-for-50-set-internet-latency-runs.md`
  - Still valid for high-volume internet-latency reliability.
- [ ] `docs/kanban/backlog/23-add-operator-grade-bulk-intake-telemetry-and-recovery-controls.md`
  - Still valid.
- [ ] `docs/kanban/backlog/24-fix-bulk-intake-post-stage-operator-clarity-and-table-semantics.md`
  - Still valid.
- [ ] `docs/kanban/backlog/normalize-vllm-pool-record-state-fields.md`
  - Still valid and likely important after the reap/display-state duplication smell.

### 4. Review and decide on untracked smoke-test assets

Untracked files from the stabilization work need an explicit decision:

- [ ] Review `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/AiListingInferenceStagingBrowserSmokeTest.php`.
- [ ] Review `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/AiListingStagingBrowserSmokeLoginTrait.php`.
- [ ] Review `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/BulkImageIntakeStagingBrowserSmokeTest.php`.
- [ ] Decide whether to commit the true UI smoke tests now.
- [ ] Remove or ignore temporary artifacts that should not be committed:
  - `.tmp-drush-probes/`
  - `.tmp-fixtures/`
  - `html/framesmith-browser-smoke.mp4`
- [ ] Decide whether `docs/dev/HANDOVER.md` should be committed, rewritten, or removed.

### 5. Add or update follow-up cards

Create or update cards for the remaining real work:

- [ ] Add a card: `Record production stabilization milestone for Framesmith and AI listing compute runtime`.
- [ ] Add a card: `Commit true UI staging smoke gates for bulk intake and AI listing inference`.
- [ ] Add a card: `Verify cron sidecar idle-reap behavior automatically after real production workload`.
- [ ] Add a card: `Reconcile bb-ai-listing fork divergence against host ai_listing module`.
- [ ] Add a card: `Define long-term split boundaries for compute_orchestrator, Framesmith, and AI listing`.
- [ ] Add a card or update existing: `Normalize vllm_pool operator/canonical state model`.
- [ ] Add a card: `Write Vast pool operator runbook and manual recovery guide`.

### 6. Suggested board lanes after cleanup

Organize active work into these practical lanes:

#### Stabilization / ops

- [ ] cron sidecar verification
- [ ] pool state normalization
- [ ] Vast price cap
- [ ] SSH/probe/operator logs
- [ ] deployment diagnostics
- [ ] operator runbook

#### Product assurance

- [ ] true UI smokes
- [ ] bulk intake high-volume hardening
- [ ] AI inference batch stress gates
- [ ] Framesmith smoke/runbook

#### Strategic split

- [ ] compute_orchestrator boundary
- [ ] Framesmith extraction
- [ ] AI listing fork reconciliation
- [ ] standalone AI listing dev environment

## Notes from review

- `32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md` contains a lot of useful history, but it now mixes completed production facts with old follow-up notes. Prefer preserving it as done and opening focused follow-ups.
- `27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md` is now fulfilled by the active production path.
- `29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md` should probably become "Phase 1 done" plus separate hardening cards.
- The board should avoid treating full extraction as an immediate blocker. The current monolith-hosted path is now a working transitional architecture.

## Definition of done

- [ ] All stale in-progress cards are moved to done or rewritten.
- [ ] All completed backlog cards are moved to done or superseded with notes.
- [ ] New follow-up cards exist for remaining hardening and extraction work.
- [ ] Untracked smoke-test files and artifacts have explicit commit/remove decisions.
- [ ] `docs/kanban/README.md` or equivalent index still reflects the board organization.
- [ ] Final git diff is reviewed before commit.
