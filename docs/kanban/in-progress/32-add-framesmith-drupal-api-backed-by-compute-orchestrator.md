# Add Framesmith Drupal API backed by compute_orchestrator

Date opened: 2026-04-24
Owner: bevan

## Outcome

Framesmith can run end-to-end transcription through Drupal endpoints backed by `compute_orchestrator`, without using the legacy `video_forge` Vast CLI provisioning path.

## Why

Framesmith is currently served from `html/framesmith` as a standalone frontend under the same Drupal host. The fastest reliable path is not a full service extraction yet. It is a pragmatic host-site integration:

Framesmith frontend → Drupal API → `compute_orchestrator` → pooled Vast runtime

This restores Framesmith functionality while preserving the later path to extract Framesmith, AI Listings, and `compute_orchestrator` into separate projects/services.

## Scope

- Do not modify `video_forge`.
- Do not extract services yet.
- Keep the short-term API in this Drupal host.
- Use `compute_orchestrator` for pooled `whisper` runtime acquire/release.
- Reuse SSH-based transcription execution initially if needed.
- Keep task paths isolated so reused pooled instances do not leak state between jobs.

## Definition of done

- [ ] Add Framesmith-facing Drupal routes for transcription start/upload/status/result.
- [ ] Route implementation requests a `whisper` runtime from `compute_orchestrator`.
- [ ] Route implementation records enough task/lease state to recover or release leases.
- [ ] Transcription execution uses per-task remote paths, e.g. `/tmp/framesmith/{task_id}`.
- [ ] Successful completion releases the compute lease or leaves it in an intentional reusable state.
- [ ] Failure paths record useful status and do not orphan active leases silently.
- [ ] `html/framesmith` frontend is wired away from legacy `video_forge` endpoints.
- [ ] End-to-end transcription is validated in dev.
- [ ] Production cutover plan is documented before deploy.

## Execution model decision

Framesmith transcription must not depend on cron-triggered queue processing.

The previous task model introduced avoidable latency because queued work only began when cron ran the queue worker. In practice this could delay provisioning and transcription start by up to roughly a minute, creating a poor user experience and an artificial throughput bottleneck.

For Framesmith Phase 1:

- keep task records for status, polling, recovery, and result lookup
- do not rely on cron to begin transcription work
- do not use Drupal Batch API as the primary execution model
- do not introduce a separate always-on worker service

Instead, the Drupal API should launch a one-shot detached Drupal command for each transcription task. That command is responsible for:

1. acquiring a `whisper` runtime via `compute_orchestrator`
2. preparing isolated remote task paths such as `/tmp/framesmith/{task_id}`
3. executing transcription
4. persisting status and results back to the task record
5. releasing or recovering the compute lease on completion/failure

Target request flow:

`browser upload → Drupal API request starts work immediately → acquire whisper runtime now → begin remote execution now → poll live task state`

## Implementation note

Preferred implementation shape:

- thin Drupal controller/routes
- task state service
- launcher service that spawns a detached Drush command
- Drush command performs the long-running orchestration

This preserves immediate task kickoff without requiring cron or a separate daemon.

Reference:
- Product ADR: `/home/bevan/workspace/bevans-bench-product/docs/architecture/adr/2026-04-24-framesmith-transcription-execution-model.md`

## Links

- Product execution card: `/home/bevan/workspace/bevans-bench-product/docs/kanban/backlog/15-make-framesmith-functional-using-compute-orchestrator.md`
- Product roadmap: `/home/bevan/workspace/bevans-bench-product/docs/roadmaps/compute-orchestrator-unification.md`
- Related host-site migration card: `docs/kanban/backlog/27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md`
- Pool API card: `docs/kanban/backlog/29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md`

## Next action

Inspect current Framesmith frontend calls in `html/framesmith/script.js`, then add the minimal Drupal route/controller/service layer needed to provide equivalent transcription start/upload/status/result behavior using `compute_orchestrator`.
