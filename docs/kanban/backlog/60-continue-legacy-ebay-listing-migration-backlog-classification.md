# Continue legacy eBay listing migration backlog classification

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

Legacy migration notes say the next real step is not “migrate everything”; it is to classify the backlog and build a safe pipeline.

## Acceptance criteria

- [ ] Classify old listings before broad migration.
- [ ] Separate clean migrations, duplicate SKU cases, missing metadata, and manual review rows.
- [ ] Decide the next pilot batch.
- [ ] Keep migration pipeline deterministic and narrow.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
