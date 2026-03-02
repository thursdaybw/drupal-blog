# AI Listing Test Plan

This file tracks the first useful tests for `ai_listing`.

The goal is simple:
- make the code safer to change
- make the rules easier to understand
- rebuild confidence in the moving parts

## Current State

- [x] PHPUnit smoke test path works
- [x] `ddev test-ai-listing-smoke` runs successfully
- [x] First real behavioral coverage is in place

## Approach

1. Start with small fast tests.
2. Pull logic out of busy form classes before testing it.
3. Test the risky, fast-changing parts first.
4. Leave browser tests until later.

## Phase 1: Testing Foundation

- [x] Add a smoke test under `ai_listing/tests/src/Unit`
- [x] Add a repo script for the smoke test
- [x] Add a thin DDEV wrapper command
- [ ] Add a dedicated non-browser PHPUnit config if needed
- [ ] Document the standard test commands for this module

## Phase 2: Batch Form Seam

- [x] Pull the batch listing data logic out of `AiBookListingLocationBatchForm`
- [x] Move filtering into a dedicated service
- [x] Move count logic into the same service
- [x] Move page slicing into the same service
- [x] Leave the form mostly focused on wiring and rendering

## Phase 3: Batch Form Tests

- [x] Add kernel test for unfiltered listing count
- [x] Add kernel test for status filtering
- [x] Add kernel test for storage location filtering
- [x] Add kernel test for free-text search matching
- [x] Add kernel test for filtered count vs rows shown on the current page
- [x] Add kernel test for page slicing by items-per-page
- [x] Add kernel test for page clamping
  Page clamping means this:
  if the user is on a later page, then changes filters, and that later page no
  longer exists, the code should fall back to page 1 instead of showing an
  empty table by mistake.

## Architecture Cleanup

- [x] Remove hard module dependency from `ai_listing` to `ai_listing_inference`
- [x] Define the inference boundary inside `ai_listing`
- [x] Make `ai_listing_inference` implement the boundary
- [x] Move image-processing orchestration out of `ai_listing`
- [x] Prove `ai_listing` kernel tests run without `ai_listing_inference`

## Phase 4: Publishing and Title Logic

- [ ] Add unit test for `BundleEbayTitleBuilder`
- [ ] Test same-author bundle title generation
- [ ] Test mixed-author bundle title generation
- [ ] Test title truncation to 80 characters
- [ ] Add test for `BookListingAssembler` using `ebay_title`
- [ ] Add test for `BookListingAssembler` rejecting missing `condition_note`
- [ ] Add test for plain book title mapping to the `Book Title` aspect

## Phase 5: eBay Payload Safety

- [ ] Add tests around shared payload building in `EbayMarketplacePublisher`
- [ ] Prove publish and update use the same inventory payload builder
- [ ] Prove publish and update use the same offer payload builder
- [ ] Prove `listingDescription` is included in offer payloads
- [ ] Prove `conditionDescription` is included in payloads

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
- The first serious target is the batch form because it already carries filtering, paging, counts, and selection logic.
- Keep testable logic out of large form classes wherever possible.
- `ai_listing` now boots and tests cleanly without pulling in inference or compute infrastructure.
