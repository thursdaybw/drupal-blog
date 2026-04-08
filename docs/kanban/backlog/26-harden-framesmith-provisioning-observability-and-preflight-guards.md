# Harden Framesmith provisioning observability and preflight guards

## Problem

Framesmith provisioning failed in production with low-signal errors (`Could not extract instance ID`) and a secondary worker crash path (`setField()` missing in deployed module revision). This made diagnosis slower than necessary and obscured whether failures were infrastructure, credentials, or parser shape issues.

## Findings

- Provisioning path relies on parsing `vastai` create-instance JSON and currently fails with weak diagnostics when shape changes or is incomplete.
- Worker error handling assumed `TaskStateService::setField()` exists across deployed module revisions; this caused a second failure during exception handling.
- `VAST_API_KEY` readiness is not hard-failed up front for the provisioning flow.

## Outcomes

- Provisioning failures are explicit and actionable (raw command output, parsed keys, and failure phase).
- Worker catch path is revision-safe and does not throw a second exception while handling first-failure conditions.
- Missing credentials/runtime preconditions fail early with clear operator guidance.

## Acceptance criteria

- add preflight check that validates required provisioning credentials before enqueue/worker execution
- improve create-instance parser diagnostics (include command stdout/stderr context safely)
- ensure worker failure writes use stable task-state APIs present in deployed module baseline
- capture and expose phase-tagged failure reason in task status UI and logs
- add regression coverage for malformed/partial create-instance responses

## Implementation notes

- keep the temporary monolith runtime support while card 25 (stack extraction) remains open
- align error payload shape with downstream UI expectations so failures stay readable in Framesmith
- avoid introducing production-only code paths; dev/prod behavior should match
