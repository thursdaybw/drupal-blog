# AI Listing Test Plan

This file tracks the first useful tests around the listing system.

That now includes three connected parts:
- `ai_listing`
- `listing_publishing`
- `ebay_connector`

The goal is simple:
- make the code safer to change
- make the rules easier to understand
- rebuild confidence in the moving parts
- keep the marketplace boundary clean

## Current State

- [x] PHPUnit smoke test path works
- [x] `ddev test-ai-listing-smoke` runs successfully
- [x] First real behavioral coverage is in place
- [x] `ai_listing` has real kernel tests
- [x] `listing_publishing` now has real kernel tests
- [x] `listing_publishing` no longer needs eBay just to boot

## Approach

1. Start with small fast tests.
2. Pull logic out of busy form classes before testing it.
3. Test the risky, fast-changing parts first.
4. Leave browser tests until later.
5. Keep generic publishing tests separate from eBay adapter tests.

## Phase 1: Testing Foundation

- [x] Add a smoke test under `ai_listing/tests/src/Unit`
- [x] Add a repo script for the smoke test
- [x] Add a thin DDEV wrapper command
- [x] Add a kernel suite command for `ai_listing`
- [ ] Add a dedicated non-browser PHPUnit config if needed
- [ ] Document the standard test commands for this part of the system

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
- [x] Remove hidden eBay requirement from `listing_publishing`
- [x] Give `listing_publishing` safe null adapters
- [x] Make `ebay_connector` an outer adapter instead of a hidden prerequisite
- [x] Prove `listing_publishing` kernel tests run without eBay modules

## Phase 4: eBay Title and Generic Publishing Logic

- [x] Add unit test for `BundleEbayTitleBuilder`
- [x] Test same-author bundle title generation
- [x] Test mixed-author bundle title generation
- [x] Test title truncation to 80 characters
- [x] Add test for `BookListingAssembler` using `ebay_title`
- [x] Add test for `BookListingAssembler` rejecting missing `condition_note`
- [x] Add test for plain book title mapping to the `Book Title` aspect
- [x] Add test proving `listing_publishing` boots with null adapters

## Phase 5: Generic Publishing Rules

- [x] Add tests for `ListingPublisher`
- [x] Test first publish writes the current SKU
- [x] Test first publish records a marketplace publication
- [x] Test publish/update reuses the saved SKU when updating a published listing
- [x] Test SKU change deletes the old SKU through the marketplace boundary
- [x] Test missing marketplace publication ID fails clearly on update

## Phase 6: eBay Adapter Payload Safety

- [ ] Add tests around shared payload building in `EbayMarketplacePublisher`
- [ ] Prove publish and update use the same inventory payload builder
- [ ] Prove publish and update use the same offer payload builder
- [ ] Prove `listingDescription` is included in offer payloads
- [ ] Prove `conditionDescription` is included in payloads
- [ ] Prove plain book title goes to the `Book Title` aspect
- [ ] Prove `ebay_title` goes to the listing title
- [ ] Prove no fake condition note is generated
- [ ] Prove existing-offer update and first-offer create follow the right path

## Phase 7: Selection Follow-Up

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
- `listing_publishing` now boots and tests cleanly without pulling in eBay modules.
- Generic publishing tests should stay separate from eBay adapter tests.
- That split matters because more marketplaces are planned.
- `ddev test-listing-kernel` is now the main command for the listing stack.
- Publication rows now represent current state only. We are not keeping local
  `ended` publication history in this table.
- Testing also exposed a real config schema gap in `ai_listing` for `bb_ai_listing_type.*`, which is now fixed.
