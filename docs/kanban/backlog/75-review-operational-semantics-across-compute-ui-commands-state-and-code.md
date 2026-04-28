# Review operational semantics across compute UI, commands, state, and code

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

Framesmith notes say operational semantics must be the source of truth: there must be no gap between what an operator means, what a command says, what state claims, and what implementation does.

## Acceptance criteria

- [ ] Review command names and behaviour for semantic mismatch.
- [ ] Review UI labels/help text for semantic mismatch.
- [ ] Review state labels and lifecycle fields for semantic mismatch.
- [ ] Review code paths for hidden divergence.
- [ ] Create focused implementation cards for any mismatches discovered.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
