# Extract AI listing buildAspects strategy classes if product volatility justifies it

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

Notes mention extracting `buildAspects()` into strategy classes, but also suggest configuration extraction is the more meaningful near-term refactor.

## Capture note

This may be deferred or rejected during grooming if product-type volatility does not justify it yet.

## Acceptance criteria

- [ ] Identify current `buildAspects()` conditionals and product/marketplace axes.
- [ ] Decide whether strategy classes are needed now or premature abstraction.
- [ ] If accepted, define strategy boundaries and tests.
- [ ] If rejected/deferred, record why and what signal would revive it.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
