# Restore selected-only mode in AI listing batch form with tests

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/ai_listing/BATCH_FORM_SELECTION_AND_PAGING_PLAN.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: AI listing

## Context

The batch form notes say `Show selected only` was deferred after an earlier attempt crossed too many moving parts.

## Acceptance criteria

- [ ] Define selected-only rules before reintroducing UI.
- [ ] Decide whether selected-only combines with current filters or replaces them.
- [ ] Ensure selection survives paging.
- [ ] Ensure selection survives filter changes.
- [ ] Show the true total selected count.
- [ ] Make `Clear selection` explicit and easy to find.
- [ ] Add test coverage before or with the UI return.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
