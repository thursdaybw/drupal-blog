# Decide original eBay listing start-date preservation

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

Legacy migration notes say the adoption command does not yet fill `ebay_listing_started_at`, and original listing start-date preservation may matter later.

## Acceptance criteria

- [ ] Decide whether preserving original eBay listing start date is required.
- [ ] Identify source API/data for start date.
- [ ] Decide whether to backfill existing adopted rows.
- [ ] Add migration support if accepted.
- [ ] Record rejection/defer reason if not worth doing.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
