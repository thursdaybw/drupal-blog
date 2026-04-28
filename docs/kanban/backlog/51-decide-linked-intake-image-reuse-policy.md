# Decide linked intake image reuse policy

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/ai_listing/README.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: AI listing

## Context

Current notes say an intake image becomes unavailable once linked to a listing. If reuse is needed later, it should be deliberate rather than accidental.

## Acceptance criteria

- [ ] Confirm current non-reuse rule still matches operator workflow.
- [ ] Decide whether any legitimate reuse scenarios exist.
- [ ] If reuse is accepted, define explicit reuse semantics and UI warnings.
- [ ] If non-reuse remains correct, add or verify tests guarding that invariant.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
