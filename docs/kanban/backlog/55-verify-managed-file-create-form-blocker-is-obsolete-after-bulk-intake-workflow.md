# Verify managed_file create-form blocker is obsolete after bulk intake workflow

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

Older notes mention a managed_file/create-form blocker and recommend a dedicated upload UI or pragmatic fallback. The newer bulk-intake workflow may have superseded this.

## Acceptance criteria

- [ ] Review the old managed_file blocker against the current bulk-intake workflow.
- [ ] Decide whether the blocker is obsolete, still relevant, or should be rejected as historical noise.
- [ ] If still relevant, open an implementation card with current reproduction steps.
- [ ] If obsolete, update or archive the stale status note.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
