# AiBookListingLocationBatchForm Plan

This file tracks the remaining work for paging, counts, and persistent selection in `AiBookListingLocationBatchForm`.

## Done

- [x] Add `items per page`
- [x] Add pager
- [x] Add `Total listings`
- [x] Add `Matching current filters`
- [x] Clamp stale pager page to page `0` when filtered results shrink

## Next

- [ ] Extract batch-form dataset and selection logic into a dedicated service or other testable seam
- [ ] Add tests for:
  - filtered counts
  - page slicing
  - persistent selection resolution
  - selected-count calculation
- [ ] Revisit `Show selected only` after the extraction and tests are in place

## Likely After That

- [ ] Add Gmail-style bulk select UX
- [ ] First step: select current page
- [ ] Second step: offer `Select all matching current filters`
- [ ] Support deselecting individual rows after `select all matching`

## Deferred

- [ ] `Show selected only`
  Deferred for now. A first pass was attempted and then backed out because the feature crossed too many moving parts without test coverage:
  - server-side filtering
  - pager state
  - hidden field synchronization
  - browser-stored selection state
  - tempstore mirroring

  Revisit this only after extracting the batch-form dataset/selection logic into a testable seam and covering it with tests.

## UX Rules

- Selection must survive paging.
- Selection must survive filter changes.
- The UI must always show the true total selected count.
- `Clear selection` must be explicit and easy to find.
- Select-all semantics must be honest. Do not pretend current-page select means all filtered rows are selected.

## Technical Notes

- Keep filters and pager state in query parameters.
- Keep persistent selection outside plain form state.
- `PrivateTempStore` is the current intended backing store.
- Current-page checkbox values should not be treated as the full truth once persistent selection exists.
- Pager/filter behavior can stay full-page for now. AJAX can be revisited after persistent selection is stable.
- Browser-side selection persistence is currently part of the working solution because pager links do not submit form state.

## Test Prerequisite

- [ ] Extract batch-form dataset and selection logic into a dedicated service or other testable seam
- [ ] Add tests for:
  - filtered counts
  - page slicing
  - persistent selection resolution
  - selected-count calculation
  - future selected-only filtering

## Open Design Questions

- Should `Show selected only` ignore current filters, or combine with them?
- When `Select all matching current filters` exists, should it store explicit IDs or a filter snapshot plus exclusions?
- Should `Clear selection` be a button, link, or both?
