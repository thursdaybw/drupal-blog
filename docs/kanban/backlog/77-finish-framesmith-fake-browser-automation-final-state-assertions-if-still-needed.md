# Finish Framesmith fake browser automation final-state assertions if still needed

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: Framesmith

## Context

Framesmith notes say fake-mode browser automation reached the real UI flow, with uncertainty around final-state assertions.

## Acceptance criteria

- [ ] Verify whether current Framesmith browser smoke already covers this.
- [ ] If not, drive real served `/framesmith/` UI through DTT/WebDriver/Selenium in fake mode.
- [ ] Assert user-visible states and deterministic fake transcript result.
- [ ] If obsolete due to later staging/prod smoke, mark rejected/obsolete with reason.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
