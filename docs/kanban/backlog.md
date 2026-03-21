# Backlog

## Reconcile Unpublished Listings So Drupal Publication State Matches eBay

Date opened: 2026-03-17
Owner: bevan

Why:
- Local `ai_marketplace_publication` rows can stay `published` after listings are unpublished/replaced on eBay.
- This creates false positives in mirror audits and confusion in operations.

Definition of done:
- [ ] `ebay-connector:reconcile-listing-publication` handles both `book` and `book_bundle` listings.
- [ ] Reconcile logic updates/removes stale publication rows when eBay has no offers for the active SKU.
- [ ] Running reconcile over published listings leaves `bb-ebay-mirror:audit-missing-offers --account-id=1` at zero for resolved stale rows.
- [ ] Add or update tests that cover bundle/unpublish reconciliation behavior.

Next action:
- Trace and patch reconcile flow in `EbayOfferCommand::reconcilePublishedListingFromEbay()` so bundle listings are included.

Links:
- Command: `ddev exec-prod ../vendor/bin/drush bb-ebay-mirror:audit-missing-offers --account-id=1`
- Command: `ddev exec-prod ../vendor/bin/drush ebay-connector:reconcile-listing-publication <listing_id>`
- Context: stale rows cleaned manually for multiple listings on 2026-03-17.

## Make Stock Cull Picker Operational For Shelf Workflow

Date opened: 2026-03-19
Owner: bevan

Why:
- The current picker helps find cull candidates but still requires opening each listing, running a bottom-of-form action, then navigating back.
- The picker already has selection checkboxes, but no bulk action uses them, so the shelf-walking workflow is still too slow.
- The current flow gets the job done, but it is not efficient enough for repeated stock culls.

Definition of done:
- [ ] Add at least one picker-level bulk action that applies the stacked cull outcome to selected listings.
- [ ] Bulk action supports both `archive` and `lost` outcomes, or there is one clear operational bulk path with the other deferred explicitly.
- [ ] Bulk action preserves picker filters and returns to the picker after completion.
- [ ] Picker shows per-row/bulk result feedback for already-unpublished marketplace rows versus real failures.
- [ ] Add browser/kernel test coverage for the picker bulk action flow.

Next action:
- Design the first picker bulk action path so selected rows can be culled without opening each listing review page.

Links:
- Route: `/admin/ai-listings/reports/stock-cull/picker`
- Related commits: `e9ca5ab`, `b9369d1`, `f831f30`

## Decouple Storage Location From eBay SKU For New Listings

Date opened: 2026-03-19
Owner: bevan

Why:
- Legacy eBay SKUs encode shelf location because the old workflow relied on the eBay app to locate stock.
- `storage_location` is now the canonical location field in Drupal, so location should stop living inside SKU.
- Mutable location data inside SKU forces unnecessary end-and-relist behavior when stock is moved.

Definition of done:
- [ ] Define and implement a location-free SKU policy for new marketplace publications.
- [ ] Publishing flow no longer derives SKU content from `storage_location`.
- [ ] Existing legacy listings are not forcibly migrated until touched by stock take or relist flows.
- [ ] Add tests covering the new SKU policy and the absence of location encoding for new publishes.

Next action:
- Trace current SKU generation and publish flow so the location-derived segment can be removed for new listings only.

Links:
- Context: legacy SKUs currently carry shelf/location information for eBay operational visibility.

## Add Stock-Take Workflow For Legacy SKU Cleanup And Refresh Decisions

Date opened: 2026-03-19
Owner: bevan

Why:
- Many legacy listings have no `storage_location` field set even though the location still exists inside SKU.
- Stock take is the natural point to extract that location, correct local data, and decide whether a listing should be refreshed.
- The workflow needs to support update-location, end-and-relist, and keep operational history coherent.

Definition of done:
- [ ] Add a stock-take workflow that can extract missing location from legacy SKU into `storage_location`.
- [ ] Workflow supports deciding whether to keep live, update location only, or end-and-relist.
- [ ] Workflow records the operational action in listing history.
- [ ] Legacy listings can be progressively cleaned up during stock take instead of requiring a one-shot migration.
- [ ] Add tests for legacy SKU location extraction and the chosen stock-take transition path.

Next action:
- Define the first stock-take action surface that updates `storage_location` from legacy SKU when the field is currently empty.

Links:
- Context: stock take, cull, and refresh workflows are currently blocked by location being embedded in legacy SKU.
