# Finish compute_orchestrator pooled instance lease and switch API

## Problem

The generic vLLM runtime is now proven on Vast.ai for both Qwen and Whisper, but `compute_orchestrator` still does not expose the pool-control contract needed for real workload sharing.

Today we can provision a generic instance and start a workload on it, but we do not yet have the leasing policy needed for a small pooled fleet:

- prefer already-known pooled instances before creating fresh ones
- try sleeping pooled instances first
- if a sleeping instance is currently rented by someone else, stop trying to wake it and move to the next pooled candidate
- only create a fresh Vast instance when the configured pool has no usable member
- track which workload/model is active on a pooled instance and switch it deterministically when needed

This API is now validated enough to support a pragmatic Framesmith Phase 1 while remaining hardening continues.

## Outcomes

- `compute_orchestrator` exposes explicit acquire/switch/release pool operations
- pooled instances are preferred over fresh instance creation
- rented-away sleeping instances are skipped deterministically instead of blocking the lease path
- fresh Vast creation only happens as the last resort when the whole pool is unavailable
- runtime switching and current workload state are persisted in Drupal so both apps can reason about the pool

## Acceptance criteria

- define Drupal-side pool inventory/state model for reserved instances
- add acquire semantics for a requested workload/model:
  - prefer already-running matching runtime
  - else try sleeping pooled instances
  - if wake fails because the instance is currently rented by someone else, mark that result and continue to the next candidate
  - only if all pooled candidates are unavailable, create a fresh Vast instance
- add switch semantics for stopping one active runtime and starting another on the same pooled instance
- add release semantics so a caller can return the instance to pool control without destroying it
- record current workload/model, availability, health, and last-used time in Drupal state/storage
- document the lease policy and fallback order clearly
- keep this API separate from Framesmith-specific queue behavior

## Notes

- This card is the prerequisite for host-site card [`27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md`](/home/bevan/workspace/bevansbench.com/docs/kanban/backlog/27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md)
- Related product card: [`14-finish-the-shared-gpu-pool-lease-policy-for-vast-runtime-reuse.md`](/home/bevan/workspace/bevans-bench-product/docs/kanban/backlog/14-finish-the-shared-gpu-pool-lease-policy-for-vast-runtime-reuse.md)

## Status update - 2026-04-10

- Implementation is currently being built first in `bb-ai-listing`, where PHPCS/PHPStan/hooks are stronger.
- Added there:
  - state-backed pool inventory
  - explicit pool register/list/acquire/release/remove/clear commands
  - acquire decision tree
  - runtime switching path through the generic vLLM runtime manager
  - unit coverage for register, empty-pool refusal, reuse, rented-elsewhere fallback, workload switching, and inventory cleanup
- Live validation against Vast instance `34414828` found and fixed a real wake-classification bug: Vast can return `resources_unavailable` immediately for an asleep leased instance; this must be treated as `rented_elsewhere`.

## Status update - 2026-04-24

- The verified pool API has been ported into this host-site repo far enough to support real production AI Listing inference.
- Production deploy succeeded with the committed `requestStack` fix and the current `compute_orchestrator` path.
- A pooled instance was registered on prod and successfully acquired for AI inference.
- The previous blocker language for Framesmith is superseded: Framesmith can now begin a pragmatic Phase 1 integration against `compute_orchestrator` while remaining pool hardening continues.

## Next action

- Keep hardening pool operations in parallel, especially fresh fallback persistence and idle reap verification.
- Support in-progress card [`32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md`](../in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md) by exposing the minimal host Drupal API needed for Framesmith to request a `whisper` runtime.
