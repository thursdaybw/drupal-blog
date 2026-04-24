# Migrate Framesmith provisioning to compute_orchestrator pool control

## Problem

Framesmith (`video_forge`) still provisions Vast instances directly via legacy CLI flow, while BB AI Listing uses `compute_orchestrator` pool semantics. This creates two orchestration generations in one host app. The target pool contract has also shifted: we are no longer aiming for per-model Vast instance configs, but for generic pooled GPU nodes with persistent model cache and runtime model switching.

## Outcomes

- Framesmith uses `compute_orchestrator` for start/stop/reservation selection instead of bespoke Vast provisioning flow.
- host app carries one orchestration contract for both listing inference and transcription workloads.
- both workloads share the same generic GPU-node contract, with runtime model/process switching and persistent cache reuse
- easier extraction of Framesmith runtime from monolith once orchestration is unified.
- migration can now proceed as a pragmatic host-site Phase 1 because the pooled runtime path has been live-validated enough for Framesmith integration.

## Acceptance criteria

- add a Framesmith adapter path that requests compute from `compute_orchestrator` pool APIs/services
- remove direct Vast offer-search/create-instance coupling from active Framesmith path
- ensure task lifecycle states map cleanly to compute-orchestrator events (requested, allocated, running, released, failed)
- update operational docs/runbooks for pool-first orchestration
- verify end-to-end transcription flow in dev and prod with pool-backed instance control
- ensure Framesmith can request Whisper runtime on a pooled node without requiring a dedicated Whisper-only Vast instance

## Implementation notes

- do not modify `video_forge`; build the active Framesmith path through new Drupal endpoints backed by `compute_orchestrator`
- avoid introducing new production-only shell behavior in queue workers
- Phase 1 should use the existing Drupal host as the integration layer rather than waiting for full service extraction
- the pool lease policy is validated enough to unblock Framesmith Phase 1; remaining pool hardening should continue in parallel

## Status update - 2026-04-24

- Active Framesmith cutover is now unblocked for a pragmatic Phase 1.
- `compute_orchestrator` pool acquire/release and runtime reuse are live enough in this host repo to support a Framesmith integration path.
- Qwen inference has been validated through the production AI Listings path.
- Whisper should be requested through the same pooled runtime contract rather than the legacy `video_forge` Vast CLI path.
- Full extraction remains a later phase; the immediate host-site task is a Drupal API shim for Framesmith.

## Next action

- Execute in-progress card [`32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md`](../in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md): add Framesmith-facing Drupal endpoints and wire them to `compute_orchestrator` `whisper` acquire/release.
