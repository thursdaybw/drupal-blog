# Define backlog fishing-net capture and grooming order model

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

The backlog should act like a fishing net:

- capture big fish and little fish;
- expect some items to slip through;
- expect some captured items to die, merge, or be rejected later;
- do not over-filter during capture;
- refine, rank, merge, reject, or promote items during backlog grooming.

This matters because the current kanban is a blob of Markdown files. File names and folders express lane/status, but they do not easily express backlog order, grooming state, confidence, or priority. That makes it too easy for architectural refactors, cleanups, and follow-ups to disappear when headline product work succeeds.

## Problem

Several backlog-worthy items were found in source notes and module architecture docs rather than kanban cards. Some were buried inside large in-progress cards. Others live in README/ARCHITECTURE files as “future”, “next”, “deferred”, or “TODO” notes.

The current process does not clearly distinguish:

- raw capture;
- backlog candidate;
- groomed backlog item;
- in-progress commitment;
- completed milestone;
- rejected/obsolete item.

## Outcome

Create an explicit backlog intake and grooming model that preserves raw findings without implying priority or commitment.

## Proposed model

### Capture state

Use backlog cards even for rough findings, but mark them clearly:

- `Capture` — raw fish; not yet validated, ranked, or deduplicated.
- `Candidate` — seems real, but not yet ordered or scoped.
- `Groomed` — accepted, scoped, and ready to rank or pull.
- `In progress` — actively being worked.
- `Done` — completed or intentionally superseded with follow-ups captured.
- `Rejected` / `Obsolete` — deliberately not doing it, with reason.

### Metadata to add to new backlog cards where useful

```text
Capture state: Capture | Candidate | Groomed | In progress | Done | Rejected | Obsolete
Source: chat | code TODO | README | architecture doc | incident | smoke test | operator pain
Confidence: low | medium | high
Size guess: tiny | small | medium | large | unknown
Urgency: now | soon | later | someday | unknown
Product area: compute_orchestrator | Framesmith | AI listing | marketplace | ops | deploy
```

### Ordering problem

Markdown filenames alone do not express order well. The current numeric prefixes help with capture chronology but not grooming priority.

Possible remedies:

- maintain a lightweight `docs/kanban/backlog/ORDER.md` with the current groomed order;
- add front-matter-like metadata to cards and generate an order view later;
- keep capture cards numerically ordered but use a separate grooming list for “top of backlog”.

## Acceptance criteria

- [ ] Decide whether to add `Capture state` metadata to new cards.
- [ ] Decide whether to add `docs/kanban/backlog/ORDER.md`.
- [ ] Decide whether raw fishing-net captures should live in normal backlog or a `capture/` subfolder.
- [ ] Define rules for when a capture card becomes groomed.
- [ ] Define rules for rejecting or merging a backlog item without losing why it existed.
- [ ] Update `docs/kanban/README.md` with the agreed model.
- [ ] Apply the model to the current cleanup pass.

## Notes

This card is deliberately about process and information preservation. It should prevent future cleanup passes from accidentally discarding refactors, architectural seams, operator pain, or “small but important” fixes.
