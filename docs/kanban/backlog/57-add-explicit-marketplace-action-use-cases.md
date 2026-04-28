# Add explicit marketplace action use-cases

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

Marketplace notes say local listing deletion must not be the primary way to take down a marketplace listing. Controllers/forms should call explicit application use-cases.

## Acceptance criteria

- [ ] Define application use-cases for publish/unpublish/takedown.
- [ ] Add future slots for republish, refresh status, and reconcile.
- [ ] Keep marketplace adapters behind interfaces.
- [ ] Ensure local deletion and marketplace takedown are separate operator concepts.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
