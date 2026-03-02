# AiBookListingLocationBatchForm Plan

This file tracks the batch form work around paging, counts, and cross-page selection in `AiBookListingLocationBatchForm`.

This is now partly a history file and partly a next-steps file.
The basic paging and selection work is done.
The next risky piece is bringing back `Show selected only` in a tested way.

## Done

- [x] Add `items per page`
- [x] Add pager
- [x] Add `Total listings`
- [x] Add `Matching current filters`
- [x] Clamp stale pager page to page `0` when filtered results shrink
- [x] Extract batch dataset logic into a dedicated service
- [x] Extract selection logic into a dedicated service
- [x] Add tests for:
  - filtered counts
  - page slicing
  - persistent selection resolution
  - selected-count calculation
  - selected-key normalization
- [x] Add `Selected` count to the UI
- [x] Add `Clear selection`

## Next

- [ ] Revisit `Show selected only` on top of the new dataset and selection seams
- [ ] Add tests for selected-only filtering before bringing that feature back
- [ ] Decide whether selected-only mode should combine with the current filters or replace them

## Likely After That

- [ ] Add Gmail-style bulk select UX
- [ ] First step: select current page
- [ ] Second step: offer `Select all matching current filters`
- [ ] Support deselecting individual rows after `select all matching`

## Deferred

- [ ] `Show selected only`
  Deferred for now. A first pass was attempted and then backed out because the feature crossed too many moving parts at once:
  - server-side filtering
  - pager state
  - hidden field synchronization
  - browser-stored selection state
  - tempstore mirroring

  We now have the test seams and some coverage.
  The next pass should start with selected-only rules and tests, then bring the UI feature back.

## UX Rules

- Selection must survive paging.
- Selection must survive filter changes.
- The UI must always show the true total selected count.
- `Clear selection` must be explicit and easy to find.
- Select-all semantics must be honest. Do not pretend current-page select means all filtered rows are selected.

## Technical Notes

- Keep filters and pager state in query parameters.
- Keep persistent selection outside plain form state.
- `sessionStorage` is part of the current working solution because pager links do not submit form state.
- `PrivateTempStore` is still used as the server-side mirror for confirmation and batch action flows.
- Current-page checkbox values should not be treated as the full truth once persistent selection exists.
- Pager/filter behavior can stay full-page for now. AJAX can be revisited after persistent selection is stable.

## Test Prerequisite

- [x] Extract batch-form dataset logic into a dedicated service
- [x] Extract batch-form selection logic into a dedicated service
- [x] Add tests for:
  - filtered counts
  - page slicing
  - persistent selection resolution
  - selected-count calculation
- [ ] Add tests for selected-only filtering before reintroducing `Show selected only`

## Open Design Questions

- Should `Show selected only` ignore current filters, or combine with them?
- When `Select all matching current filters` exists, should it store explicit IDs or a filter snapshot plus exclusions?
- Should `Clear selection` be a button, link, or both?
