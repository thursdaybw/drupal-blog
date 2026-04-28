# Handle duplicate SKUs in legacy eBay migration pipeline

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/bb_ebay_legacy_migration/README.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: Marketplace

## Context

Legacy migration notes say duplicate SKUs must be handled explicitly by the migration pipeline.

## Acceptance criteria

- [ ] Define duplicate SKU detection.
- [ ] Define migration SKU generation rules.
- [ ] Ensure temporary migration SKUs are obvious and traceable.
- [ ] Add manual review/retry state for blocked rows.
- [ ] Test duplicate SKU migration cases before broad use.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
