# Write Vast pool operator runbook and manual recovery guide

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

The shared `compute_orchestrator` / `vllm_pool` path now supports production Framesmith and AI listing inference. It is operationally important enough to need a concise operator runbook.

## Problem

The knowledge for diagnosing and recovering pool issues currently lives in chat history and ad-hoc commands. That makes future incidents slower and increases the chance of unsafe manual intervention.

## Acceptance criteria

- [ ] Document how to inspect staging and production pool records.
- [ ] Document how to inspect Docker Compose appserver/cron/db service state.
- [ ] Document how to run Drupal cron manually in prod/staging.
- [ ] Document expected pool states for:
  - active lease;
  - released warm runtime during grace period;
  - successful idle reap;
  - failed reap;
  - provider/rented/unavailable states.
- [ ] Document safe manual recovery steps for a released-but-running instance.
- [ ] Document what not to do without explicit confirmation, especially destructive Vast or Docker cleanup actions.
- [ ] Include references to useful DDEV host commands and SSH aliases.
- [ ] Include a short checklist for post-workload verification.

## Links

- Milestone: `docs/kanban/done/2026-04-28-stabilize-framesmith-and-ai-listing-on-compute-orchestrator.md`
- Cron verification: `docs/kanban/backlog/37-verify-cron-sidecar-idle-reap-after-real-production-workload.md`
- Pool state follow-up: `docs/kanban/backlog/normalize-vllm-pool-record-state-fields.md`
