# Replace temporary AI inference diagnostics with durable observability if needed

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/ai_listing/STATUS_REPORT_IMAGE_INFERENCE_PIVOT.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: AI listing

## Context

The image inference pivot notes mention temporary diagnostics that should not be relied on long-term.

## Acceptance criteria

- [ ] Inventory current temporary diagnostics in AI inference paths.
- [ ] Decide whether they are still needed after production stabilization.
- [ ] Remove obsolete diagnostics.
- [ ] Replace still-useful diagnostics with structured logging/operator-visible observability.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
