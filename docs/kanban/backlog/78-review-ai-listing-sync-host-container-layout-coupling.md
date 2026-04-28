# Review AI listing sync host/container layout coupling

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/bb_ai_listing_sync/ARCHITECTURE.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: Sync/export

## Context

The AI listing sync architecture notes say current sync operations work but couple to host/container layout details.

## Acceptance criteria

- [ ] Inventory host/container layout assumptions in sync operations.
- [ ] Decide whether these assumptions block product extraction.
- [ ] Define adapter/config abstraction if needed.
- [ ] Link resulting work to AI listing fork reconciliation and standalone environment cards.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
