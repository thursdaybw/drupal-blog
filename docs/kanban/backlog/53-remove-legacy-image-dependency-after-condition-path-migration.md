# Remove legacy image dependency after condition path migration

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

The image inference pivot notes say metadata and condition inference have been split, but legacy image dependency/display cleanup remains for later.

## Acceptance criteria

- [ ] Verify the condition inference path no longer needs legacy image fields.
- [ ] Remove legacy image dependency when safe.
- [ ] Remove legacy image display once confidence is high.
- [ ] Add regression coverage for the new listing_image-based path.
- [ ] Confirm no operator workflow still relies on the legacy display.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
