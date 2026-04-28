# Review operational semantics across compute UI, commands, state, and code

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Groomed
Grooming priority: Now
Source: docs/kanban/in-progress/32-add-framesmith-drupal-api-backed-by-compute-orchestrator.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

Framesmith notes say operational semantics must be the source of truth: there must be no gap between what an operator means, what a command says, what state claims, and what implementation does.

## Grooming decision - 2026-04-28

Promote to `Groomed / Now` as a concrete child workstream under card `42`.

Reason:

- Operator semantics are part of freedom and sovereignty: the system should say what it means and do what it says.
- Recent pool/reap work exposed the cost of duplicated or stale state labels.
- This should stay separate from the broad architecture review because it can produce focused fixes to commands, UI labels, state fields, and mutation paths.

Related cards:

- Umbrella architecture review: `42-review-compute-orchestrator-architecture-and-drupal-coupling.md`
- Pool state normalization: `normalize-vllm-pool-record-state-fields.md`


## Implementation progress - 2026-04-28

First operator-semantics slice:

- aligned command output around explicit `lease_status` and `runtime_state` wording;
- clarified release/reap/remove/destroy semantics in pool command output;
- clarified the pool admin glossary and table labels;
- added a table formatter for transitional `last_phase` / `last_action` as a single last-operation display;
- documented the operator vocabulary in compute_orchestrator usage/API docs.

This is not the full card yet. Remaining work includes reviewing all command names, state labels, and implementation paths for deeper mismatches after the first wording cleanup.

## Acceptance criteria

- [x] Review command names and behaviour for semantic mismatch.
  - First pass completed for vLLM pool commands; command output now uses explicit lease/runtime wording.
- [x] Review UI labels/help text for semantic mismatch.
  - First pass completed for pool admin glossary/table labels and release/reap/remove/destroy wording.
- [x] Review state labels and lifecycle fields for semantic mismatch.
  - First pass documented `lease_status`, `runtime_state`, `last_phase`, and `last_action` vocabulary; deeper normalization remains in the pool state card.
- [ ] Review code paths for hidden divergence.
- [ ] Create focused implementation cards for any mismatches discovered.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
