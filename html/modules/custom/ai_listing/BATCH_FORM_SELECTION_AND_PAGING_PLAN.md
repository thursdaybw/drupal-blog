# AiBookListingLocationBatchForm Plan

This file tracks the remaining work for paging, counts, and persistent selection in `AiBookListingLocationBatchForm`.

## Done

- [x] Add `items per page`
- [x] Add pager
- [x] Add `Total listings`
- [x] Add `Matching current filters`
- [x] Clamp stale pager page to page `0` when filtered results shrink

## Next

- [ ] Persist row selection across page changes
- [ ] Persist row selection across filter changes
- [ ] Store selection in `PrivateTempStore`
- [ ] Pre-check rows already selected in tempstore when rendering a page
- [ ] Merge current-page checkbox state back into tempstore on submit
- [ ] Show `Selected: X` in the summary area
- [ ] Add `Clear selection`

## Likely After That

- [ ] Add `Show selected only`
- [ ] Add Gmail-style bulk select UX
- [ ] First step: select current page
- [ ] Second step: offer `Select all matching current filters`
- [ ] Support deselecting individual rows after `select all matching`

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

## Open Design Questions

- Should `Show selected only` ignore current filters, or combine with them?
- When `Select all matching current filters` exists, should it store explicit IDs or a filter snapshot plus exclusions?
- Should `Clear selection` be a button, link, or both?
