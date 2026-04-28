# Decide Framesmith fake runtime and fake lease strategy

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: Framesmith

## Context

Framesmith notes call for fake runtime executor support for local/frontend testing, with possible future fake lease/Vast layers.

## Acceptance criteria

- [ ] Verify what fake runtime support already exists.
- [ ] Decide whether fake lease manager or deeper fake provider layer is needed.
- [ ] Ensure fake mode never acquires real pooled compute.
- [ ] Ensure fake mode uses the same API contract as real mode.
- [ ] Document operator/dev usage.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
