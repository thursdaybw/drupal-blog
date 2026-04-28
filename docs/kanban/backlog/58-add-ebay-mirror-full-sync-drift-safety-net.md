# Add eBay mirror full-sync drift safety net

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/bb_ebay_mirror/README.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: Marketplace

## Context

The eBay mirror notes say cron/full sync is still needed as a safety net for drift and manual eBay changes.

## Acceptance criteria

- [ ] Define the expected full-sync behaviour.
- [ ] Decide schedule and operator trigger.
- [ ] Ensure successful unpublish/takedown deletes affected mirror rows immediately.
- [ ] Add reporting for drift/manual changes.
- [ ] Add tests or a safe dry-run mode if feasible.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
