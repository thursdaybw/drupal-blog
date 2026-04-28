# Capture backlog fish from module notes and architecture docs

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture index
Source: README/ARCHITECTURE/status notes sweep

## Context

A deeper fishing-net pass through module README/ARCHITECTURE/status notes found backlog-worthy signals that were not clearly represented as individual kanban cards.

This card is now an index/audit note. The captured fish were split into individual raw `Capture` backlog cards so they can be groomed, merged, rejected, ranked, or promoted independently.

## Generated capture cards

### AI listing

- `46-extract-ai-listing-marketplace-configuration.md`
- `47-remove-ai-listing-environment-coupling.md`
- `48-extract-ai-listing-buildaspects-strategy-classes-if-product-volatility-justifies-it.md`
- `49-split-ai-listing-review-ui-by-listing-bundle.md`
- `50-improve-ai-listing-set-based-image-intake-ux.md`
- `51-decide-linked-intake-image-reuse-policy.md`
- `52-restore-selected-only-mode-in-ai-listing-batch-form-with-tests.md`
- `53-remove-legacy-image-dependency-after-condition-path-migration.md`
- `54-replace-temporary-ai-inference-diagnostics-with-durable-observability-if-needed.md`
- `55-verify-managed-file-create-form-blocker-is-obsolete-after-bulk-intake-workflow.md`

### Marketplace / eBay

- `56-add-marketplace-read-model-and-listing-page-operator-controls.md`
- `57-add-explicit-marketplace-action-use-cases.md`
- `58-add-ebay-mirror-full-sync-drift-safety-net.md`
- `59-add-admin-ui-for-ebay-mirror-audit-reports-if-still-needed.md`
- `60-continue-legacy-ebay-listing-migration-backlog-classification.md`
- `61-handle-duplicate-skus-in-legacy-ebay-migration-pipeline.md`
- `62-decide-original-ebay-listing-start-date-preservation.md`
- `63-add-non-book-legacy-ebay-adoption-path.md`

### compute_orchestrator / Framesmith

- `64-split-compute-orchestrator-admin-ui-into-optional-ui-layer-if-needed.md`
- `65-require-semantic-readiness-progress-markers-in-compute-orchestrator.md`
- `66-add-early-gpu-sanity-check-after-ssh.md`
- `67-harden-compute-orchestrator-bootstrap-failure-handling-and-bad-host-policy.md`
- `68-review-temporary-qwen-max-model-len-runtime-contract.md`
- `69-add-stale-leased-job-recovery-with-explicit-heartbeats.md`
- `70-verify-stopped-instance-reuse-after-idle-reap.md`
- `71-add-detached-runner-task-log-visibility.md`
- `72-define-drush-launcher-as-swappable-worker-adapter.md`
- `73-define-compute-provider-boundary-beyond-vast-ai.md`
- `74-review-compute-task-crud-and-storage-ownership-boundary.md`
- `../in-progress/75-review-operational-semantics-across-compute-ui-commands-state-and-code.md`
- `76-decide-framesmith-fake-runtime-and-fake-lease-strategy.md`
- `77-finish-framesmith-fake-browser-automation-final-state-assertions-if-still-needed.md`

### Sync/export

- `78-review-ai-listing-sync-host-container-layout-coupling.md`

## Source files swept

- `html/modules/custom/ai_listing/README.md`
- `html/modules/custom/ai_listing/ARCHITECTURE_PLAN_MARKETPLACE_PUBLISHING.md`
- `html/modules/custom/ai_listing/BATCH_FORM_SELECTION_AND_PAGING_PLAN.md`
- `html/modules/custom/ai_listing/STATUS_REPORT_IMAGE_INFERENCE_PIVOT.md`
- `html/modules/custom/compute_orchestrator/ARCHITECTURE.md`
- `html/modules/custom/compute_orchestrator/README.md`
- `html/modules/custom/compute_orchestrator/API.md`
- `html/modules/custom/compute_orchestrator/USAGE.md`
- `html/modules/custom/bb_ai_listing_sync/ARCHITECTURE.md`
- `html/modules/custom/bb_ebay_mirror/README.md`
- `html/modules/custom/bb_ebay_legacy_migration/README.md`

## Grooming rule

These cards are raw capture, not ordered commitment. Grooming may merge, reject, defer, or promote them.
