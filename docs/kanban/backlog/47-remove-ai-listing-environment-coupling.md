# Remove AI listing environment coupling

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

AI listing notes call out remaining environment coupling. This may become more important as AI listing moves toward a standalone product/fork.

## Capture note

Raw capture. Groom before implementation.

## Acceptance criteria

- [ ] Inventory environment-dependent assumptions in AI listing services, forms, commands, and config.
- [ ] Decide which assumptions are acceptable host integration and which block extraction.
- [ ] Define adapter/config seams for environment-specific behaviour.
- [ ] Link any resulting work to the AI listing fork reconciliation and standalone dev environment cards.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
