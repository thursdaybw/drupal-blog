# Add marketplace read model and listing-page operator controls

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/ai_listing/ARCHITECTURE_PLAN_MARKETPLACE_PUBLISHING.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: Marketplace

## Context

Marketplace publishing notes call for a listing-page marketplace read model and operator controls.

## Acceptance criteria

- [ ] Define generic marketplace publication read model.
- [ ] Keep eBay offer/listing IDs as adapter state, not core identity.
- [ ] Add listing-page marketplace state display.
- [ ] Add explicit operator actions for marketplace publication lifecycle.
- [ ] Ensure the implementation supports future marketplaces without bending the core around eBay.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
