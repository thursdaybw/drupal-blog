# Extract AI listing marketplace configuration

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

AI listing notes identify marketplace configuration extraction as the next meaningful structural refactor. The current shape still has marketplace and environment assumptions close to application behaviour.

## Capture note

This is a raw backlog capture item. It needs grooming before implementation.

## Acceptance criteria

- [ ] Inventory marketplace-specific configuration currently embedded in AI listing code/config.
- [ ] Identify which values are environment-specific versus marketplace-specific versus product semantics.
- [ ] Define a configuration boundary that supports current eBay use without bending the core around eBay.
- [ ] Decide whether this belongs in host config, product config, or marketplace adapter config.
- [ ] Create implementation follow-ups if the refactor is accepted during grooming.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
