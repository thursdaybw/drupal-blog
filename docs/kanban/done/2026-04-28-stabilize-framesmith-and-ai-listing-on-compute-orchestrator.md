# Stabilize Framesmith and AI listing on compute_orchestrator

Date completed: 2026-04-28
Owner: bevan
Status: Done

## Outcome

Framesmith and AI listing inference are now both operating through the shared `compute_orchestrator` / `vllm_pool` runtime path on the production-style stack.

This is the current transitional architecture:

```text
Framesmith UI/API -> Drupal host -> compute_orchestrator/vllm_pool -> Vast Whisper runtime
AI listing Workbench -> Drupal batch UI -> compute_orchestrator/vllm_pool -> Vast Qwen runtime
```

The full future split into separate `compute_orchestrator`, Framesmith, and AI listing projects remains valid, but it is no longer a blocker for using the current production system.

## Confirmed production/staging facts

- Framesmith works on production.
- Framesmith uses `compute_orchestrator` / `vllm_pool` for Whisper runtime control.
- The active Framesmith path no longer depends on legacy `video_forge` Vast provisioning.
- AI listing image inference works through the real Workbench UI batch path.
- `compute_orchestrator` / `vllm_pool` supports both Whisper and Qwen workloads.
- Staging and production share the same Docker Compose cron sidecar.
- Drupal cron runs from the shared release image every 60 seconds.
- Idle released Vast runtimes are reaped correctly when Drupal cron runs.

## Validation evidence

### Framesmith

- Production manual Framesmith run succeeded.
- Production Framesmith relinquished its lease after the job completed.
- Manual production `drush cron` verified that an idle released Whisper runtime was reaped:
  - `lease_status=available`
  - `runtime_state=stopped`
  - `last_phase=idle_reap`
  - `last_action=stopped`
  - `last_error=""`
- Staging Framesmith browser smoke gate had already validated the public staging UI path, Vast/Whisper transcription, lease release, and cron reap flow.

### AI listing inference

- Staging single-listing AI inference UI smoke passed through the real Workbench UI and Drupal batch page.
- Staging 10-listing Workbench UI batch passed.
- Staging 60-listing Workbench UI stress run passed:
  - processed listings `2318` down to `2259`;
  - `failed_count=0`;
  - all verified listings reached `ready_for_review` with inferred metadata/condition fields populated;
  - qwen runtime was reaped afterward.

### Bulk image intake

- Staging bulk image intake browser/UI smoke passed:
  - browser uploaded fixture images through the real file input;
  - browser clicked `Stage uploaded sets`;
  - browser clicked `Process staged sets`;
  - the wrapper verified a listing was created with image rows and cleaned it up.

### Deployment/runtime

- Production deployment succeeded with active image `bb-platform-drupal:git-be5c3d7f1072`.
- Production services verified after deploy:
  - `bb-platform-prod-appserver-1` running;
  - `bb-platform-prod-cron-1` running;
  - `bb-platform-prod-db-1` healthy.
- Staging services were also verified with the cron sidecar running from the same shared Compose definition.

## Follow-up work intentionally left open

- Clean up kanban state after this milestone.
- Review and decide whether to commit the untracked true UI staging smoke tests for bulk intake and AI listing inference.
- Normalize `vllm_pool` record state fields and operator-facing status semantics.
- Add or update an operator runbook for Vast pool incidents and manual recovery.
- Reconcile the `bb-ai-listing` fork against the host `ai_listing` module.
- Define the long-term project boundaries for `compute_orchestrator`, Framesmith, and AI listing.
- Eventually extract Framesmith runtime concerns out of the bevansbench.com monolith.
