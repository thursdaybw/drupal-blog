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
- The immediate operational pain is that setting location is currently coupled to publish/update, and publish is slow because it uploads images to eBay.
- That slows down shelving flow. Location entry needs to become a fast local action, with publish deferred to a separate later bulk step.

Definition of done:
- [ ] Define and implement a location-free SKU policy for new marketplace publications.
- [ ] Publishing flow no longer derives SKU content from `storage_location`.
- [ ] Publishing no longer requires `storage_location` to be set first.
- [ ] `/admin/ai-listings/workbench/location/confirm` updates location only and no longer performs publish/update as part of the same action.
- [ ] Location updates remain local inventory metadata changes and do not trigger eBay image upload or marketplace churn.
- [ ] Existing legacy listings are not forcibly migrated until touched by stock take or relist flows.
- [ ] Add tests covering the new SKU policy and the absence of location encoding for new publishes.

Next action:
- Trace current SKU generation plus `/admin/ai-listings/workbench/location/confirm` so location-setting can be split cleanly from publish/update.

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

## Decide Existing-Site Marketplace Test Strategy And Isolate DTT From Live eBay

Date opened: 2026-03-19
Owner: bevan

Why:
- Existing-site DTT coverage is now valuable for operational workflow QA, but the current dev environment still talks to live eBay.
- That makes lifecycle and marketplace tests more expensive, riskier, and harder to run routinely.
- The project needs a deliberate strategy: real eBay sandbox, an internal marketplace boundary stub, or a documented split between safe stubbed tests and deliberate live smoke tests.

Definition of done:
- [ ] Decide and document the testing strategy for marketplace-backed existing-site tests: eBay sandbox, local stub, or an explicit hybrid model.
- [ ] Implement the chosen strategy enough that DTT marketplace tests no longer require live eBay for normal regression coverage.
- [ ] Update existing DTT test scaffolding/config to use the chosen strategy consistently.
- [ ] Keep a clearly named path for rare deliberate live eBay smoke tests if they remain useful.
- [ ] Add or update docs so test commands and environment expectations are explicit.

Next action:
- Compare the real cost and fidelity of eBay sandbox versus an internal marketplace boundary stub, then choose one intentional path instead of continuing with accidental live eBay testing.

Links:
- Config: `phpunit.dtt.xml`
- Existing DTT base: `dtt_multi_device_test_base/`
- Existing live-style scaffold: `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/StockCullReportLifecycleDesktopTest.php`

## Make Deployment Diagnostics Less Opaque

Date opened: 2026-03-22
Owner: bevan

Why:
- Critical deploy failures are being summarized away behind Ansible task abstraction.
- The current playbook can skip important Drupal actions like `updb`, `cim`, and `cr` without surfacing the real failure clearly enough.
- Deployment tooling does not need to be shell-transparent, but it must expose the real reason when something important fails.

Definition of done:
- [ ] Review current deploy/activate tasks and identify where critical failure detail is hidden or downgraded.
- [ ] Ensure bootstrap, database update, config import, cache rebuild, and health-check failures surface actionable stdout/stderr clearly.
- [ ] Remove misleading fallback messaging that masks real deploy problems.
- [ ] Decide which deploy steps should remain in Ansible tasks and which should move into explicit scripts for clearer behavior.
- [ ] Document the chosen deploy transparency standard so future changes do not reintroduce opaque failure handling.

Next action:
- Audit `ops/ansible/deploy_image.yml` for critical steps that currently hide or soften real failures, starting with Drupal bootstrap and post-activate verification.
