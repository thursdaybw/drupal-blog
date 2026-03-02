# AI Listing Test Plan

This file tracks the first pass of meaningful test coverage for `ai_listing` and its immediate publishing boundary.

## Current State

- [x] PHPUnit smoke test path works
- [x] `ddev test-ai-listing-smoke` runs successfully
- [ ] Real behavioral coverage is in place

## Approach

1. Start with fast tests.
2. Extract testable seams before adding complex assertions.
3. Cover churn-heavy logic before lower-risk code.
4. Keep browser tests thin and late.

## Phase 1: Testing Foundation

- [x] Add a smoke test under `ai_listing/tests/src/Unit`
- [x] Add a repo script for the smoke test
- [x] Add a thin DDEV wrapper command
- [ ] Add a dedicated non-browser PHPUnit config if needed
- [ ] Document the standard test commands for this module

## Phase 2: Batch Form Seam

- [ ] Extract batch listing dataset logic from `AiBookListingLocationBatchForm`
- [ ] Move filtering into a dedicated service
- [ ] Move count calculation into the same seam
- [ ] Move page slicing into the same seam
- [ ] Keep form code focused on wiring and rendering

## Phase 3: Batch Form Tests

- [ ] Add kernel test for unfiltered listing count
- [ ] Add kernel test for status filtering
- [ ] Add kernel test for storage location filtering
- [ ] Add kernel test for free-text search matching
- [ ] Add kernel test for filtered count vs paged rows
- [ ] Add kernel test for page slicing by items-per-page

## Phase 4: Publishing and Title Logic

- [ ] Add unit test for `BundleEbayTitleBuilder`
- [ ] Cover same-author bundle title generation
- [ ] Cover mixed-author bundle title generation
- [ ] Cover title truncation to 80 characters
- [ ] Add test for `BookListingAssembler` using `ebay_title`
- [ ] Add test for `BookListingAssembler` rejecting missing `condition_note`
- [ ] Add test for plain book title mapping to the `Book Title` aspect

## Phase 5: eBay Payload Safety

- [ ] Add tests around shared payload construction in `EbayMarketplacePublisher`
- [ ] Assert publish and update use the same inventory payload builder
- [ ] Assert publish and update use the same offer payload builder
- [ ] Assert `listingDescription` is included in offer payloads
- [ ] Assert `conditionDescription` is included in payloads

## Phase 6: Selection Follow-Up

- [ ] Extract persistent selection logic into a testable seam
- [ ] Add tests for selected-count calculation
- [ ] Add tests for selected-key normalization
- [ ] Revisit `Show selected only` only after the above tests exist

## Later

- [ ] Add one thin browser smoke test for the batch form if still needed
- [ ] Add one thin browser smoke test for upload widget behavior if still needed

## Notes

- Browser tests are not the first line of defense here.
- The first serious target is the batch form because it is already carrying filtering, paging, counts, and selection complexity.
- Keep testable logic out of large form classes wherever possible.
