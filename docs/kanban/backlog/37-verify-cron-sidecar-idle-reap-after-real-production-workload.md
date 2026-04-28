# Verify cron sidecar idle reap after real production workload

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

Production now has a shared Docker Compose `cron` sidecar that runs Drupal cron every 60 seconds from the same release image/env/mounts as `appserver`.

Manual prod `drush cron` already proved the reaper path works: a released, idle Whisper runtime moved from `runtime_state=running` to `runtime_state=stopped` with `last_phase=idle_reap` and `last_action=stopped`.

## Problem

The manual cron proof showed that reaping works when cron is invoked. The remaining operational proof is that the sidecar invokes Drupal cron automatically after a real production workload and reaps the instance without manual intervention.

## Acceptance criteria

- [ ] Run or observe a real production Framesmith or AI inference workload.
- [ ] Confirm the workload releases its pool lease.
- [ ] Wait for the configured grace period plus at least one cron-sidecar interval.
- [ ] Confirm the relevant pool record becomes:
  - `lease_status=available`
  - `runtime_state=stopped`
  - `last_phase=idle_reap`
  - `last_action=stopped`
  - `last_error=""`
- [ ] Confirm the Vast console agrees that the instance stopped.
- [ ] Record the observed timing and pool record evidence.
- [ ] Add a note to the operator runbook or milestone card if the proof is successful.

## Links

- Milestone: `docs/kanban/done/2026-04-28-stabilize-framesmith-and-ai-listing-on-compute-orchestrator.md`
- Pool state follow-up: `docs/kanban/in-progress/normalize-vllm-pool-record-state-fields.md`
