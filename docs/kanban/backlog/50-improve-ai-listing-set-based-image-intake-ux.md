# Improve AI listing set-based image intake UX

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

Image intake notes say the model boundary supports set-based intake, but the listing form UX is still in transition.

## Acceptance criteria

- [ ] Present intake sets as selectable cards/groups.
- [ ] Allow whole-set selection as the primary action.
- [ ] Expose per-image controls only when the operator drills into a set.
- [ ] Preserve the boundary that intake set identity is temporary infrastructure, not durable product metadata.
- [ ] Add browser-level coverage for the chosen operator flow.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
