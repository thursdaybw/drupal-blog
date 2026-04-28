# Normalize VLLM pool record state fields

## Status

Backlog.

## Context

While testing staging Framesmith smoke and Drupal cron reaping, cron successfully stopped an idle warm Vast instance, but the Drupal pool record still displayed the previous readiness-probe phase/action:

```text
last_phase=workload_ready_probe
last_action=probe /v1/models (skip start; mode already matched)
```

The shared reaper service had persisted `runtime_state=stopped` and `last_stopped_at`, but had not updated the operator-facing `last_phase` / `last_action` fields. This was patched in the shared reap path, but the incident exposed a broader state-model smell.

## Problem

Pool records currently carry multiple overlapping state/status fields:

- `lease_status`
- `runtime_state`
- `last_phase`
- `last_action`
- `last_error`
- `last_seen_at`
- `last_used_at`
- `last_stopped_at`
- `last_reap_at`
- lease metadata such as `lease_expires_at`
- workload metadata such as `current_workload_mode` and `current_model`

Some of these are canonical state; others are operator-display or last-operation metadata. Because they are written independently, mutation paths can update one set and leave another stale.

## Desired direction

Define the pool record state model explicitly:

### Canonical fields

Fields that determine behavior and eligibility:

- `lease_status`
- `runtime_state`
- `current_workload_mode`
- `current_model`
- `last_used_at`
- `lease_expires_at`
- `last_error`

### Last operation snapshot

Replace or clearly define `last_phase` / `last_action` as a coherent operation snapshot, for example:

- `last_operation`
- `last_operation_status`
- `last_operation_at`
- `last_operation_message`

### Optional history/debug

If richer auditability is useful, keep a bounded `history[]` or `events[]` list rather than relying on stale last-action strings.

## Implementation notes

- Add small helper methods in `VllmPoolManager` or a record helper/service:
  - `markLeaseReleased()`
  - `markRuntimeReady()`
  - `markReapedStopped()`
  - `markReapFailed()`
  - `markUnavailable()`
  - `markDestroyed()`
- Route all pool record mutations through those helpers.
- Make the admin UI derive operator-facing status from canonical state and last-operation snapshot, not from ad-hoc fields.
- Keep backwards compatibility for existing records during the transition.
- Add tests that assert persisted state and admin/operator summary stay consistent for each mutation path.


## Implementation progress - 2026-04-28

First implementation slice:

- added explicit `VllmPoolManager` helper methods for common state transitions:
  - lease release;
  - expired lease reclaim;
  - lease renewal;
  - lease acquired after runtime readiness;
  - retryable acquire pending;
  - acquire failure;
  - transient provider observation;
  - idle reap already inactive;
  - idle reap stopped;
  - idle reap failed;
- routed release, renew, acquire pending/failure, transient provider observation, leased-ready, and idle-reap paths through those helpers;
- added unit coverage that release and renew persist coherent lease operation metadata.

This is not the full card yet. Remaining work includes documenting final field semantics, deciding the long-term replacement/meaning of `last_phase` and `last_action`, and continuing to reduce direct state mutation in reconcile/fresh-failure/wake-fallback paths.

## Acceptance criteria

- Documented pool record field semantics.
- No mutation path updates only canonical state while leaving display state stale.
- Cron reaper and admin reaper use the same service path and produce identical persisted outcome fields.
- Existing records with legacy `last_phase` / `last_action` still render safely.
- Focused tests cover:
  - successful reap stop;
  - already inactive reap;
  - reap failure;
  - release after transcription;
  - workload ready/probe update;
  - destroyed/externally missing instance.
