# Review temporary Qwen max_model_len runtime contract

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/compute_orchestrator/API.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

The compute_orchestrator API notes mention a temporary Qwen `max_model_len=16384` runtime contract until the generic image default is rebuilt.

## Acceptance criteria

- [ ] Confirm whether the temporary runtime contract is still in use.
- [ ] Decide the desired image default.
- [ ] Remove or document the override once image defaults are rebuilt.
- [ ] Verify AI listing inference after any change.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
