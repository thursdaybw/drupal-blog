# Fix bulk intake post-stage operator clarity and table semantics

## Problem

After successful staging, the page returns to the default form view and the operator must infer outcome from multiple tables. This creates high confusion:

- no explicit post-stage batch confirmation banner
- `Recently ingested images` can be interpreted as full-batch proof when it is a subset view
- source-of-truth (`Staged intake sets`) is not presented as the primary summary

## Findings

- `Staged intake sets` is the authoritative readiness view and must be treated as such.
- Any subset table must be explicitly labeled as subset with counts (`showing X of Y`).
- Operator confirmation should not depend on scanning rows or implicit assumptions after reload.
- Recovery actions must be first-class and explicit, not inferred.

## Outcomes

- operator can confirm stage success in one glance
- subset tables cannot be mistaken for full-batch result
- post-stage and retry states are explicit and low-ambiguity

## Acceptance criteria

- add a post-stage summary banner on reload:
  - `Staged <sets> sets, <images> images, failed <n>`
- add summary counts above staged table:
  - total sets
  - total images
  - ready/failed counts
- relabel image table as explicit subset when capped:
  - e.g. `Recently ingested images (latest 25)`
  - plus `Showing 25 of 268` style count text
- provide explicit retry control text/state when failures exist:
  - `Retry failed sets (<n>)`

## Implementation notes

- keep domain logic in service layer; form should render prepared summary DTOs
- emit a batch token/run id to bind summary, staged rows, and progress telemetry
- persist latest batch summary for operator-visible reload state

## Test plan

- manual:
  - stage success path
  - partial failure + retry path
  - verify labels/counts prevent subset confusion
- harness:
  - assert post-stage summary banner text and counts
  - assert subset label semantics when capped table is present
