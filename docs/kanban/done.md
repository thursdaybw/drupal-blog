# Done

## Preserve Marketplace Listing Age Across End And Relist

Date opened: 2026-03-19
Owner: bevan

Why:
- End-and-relist is operationally acceptable for refreshing listings and changing SKU policy.
- Current listing-age truth is at risk if relist overwrites the only marketplace start date.
- Stock cull and stock-take decisions need both the original first-listed date and the current live listing start date.

Definition of done:
- [x] Add a durable model for marketplace lifecycle dates that preserves original first-listed truth across relists.
- [x] Distinguish between original marketplace start date and current live publication start date.
- [x] Relist flow records history/events without destroying the original listing-age truth.
- [x] Stock-cull reporting can continue to use the intended age metric explicitly after the model change.
- [x] Add tests for publish -> unpublish -> relist lifecycle behavior.

Evidence:
- Command: `ddev drush updb -y`
- Result: dev schema updated cleanly, including marketplace lifecycle storage.
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/listing_publishing/tests/src/Kernel/ListingPublisherTest.php'`
- Result: pass (`7 tests, 64 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/listing_publishing/tests/src/Kernel/MarketplaceUnpublishServiceTest.php'`
- Result: pass (`3 tests, 21 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/ai_listing/tests/src/Kernel/EbayStockCullReportQueryTest.php'`
- Result: pass (`4 tests, 22 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/ai_listing/tests/src/Kernel/AiListingStockCullReportControllerTest.php'`
- Result: pass (`5 tests, 36 assertions`)
- Commit: `1de76eb`

## Record Publish Unpublish Relist History On Marketplace Lifecycle Actions

Date opened: 2026-03-19
Owner: bevan

Why:
- Marketplace lifecycle truth now preserves original listing age across relists, but the listing history UI does not show normal publish, unpublish, and relist events.
- That leaves an audit gap on the review form for one of the most important operational workflows.
- The missing behavior was exposed while defining real QA for end-to-end lifecycle testing.

Definition of done:
- [x] Publishing records a `marketplace_published` history event on the listing.
- [x] Unpublishing records a `marketplace_unpublished` or `marketplace_already_unpublished` history event on the listing.
- [x] Republishing after a prior unpublish records a distinct relist-style history event, or otherwise makes the republish sequence explicit in history.
- [x] Listing review history renders those lifecycle events in a readable way.
- [x] Add or update tests covering the new lifecycle history recording behavior.

Evidence:
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/listing_publishing/tests/src/Kernel/ListingPublisherTest.php'`
- Result: pass (`7 tests, 64 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/listing_publishing/tests/src/Kernel/MarketplaceUnpublishServiceTest.php'`
- Result: pass (`3 tests, 21 assertions`)
- Command: `ddev drush cr`
- Result: pass

## Add Live DTT Existing-Site Lifecycle Regression Test

Date opened: 2026-03-19
Owner: bevan

Why:
- Existing kernel coverage proves the data model, but not the real existing-site UI workflow against the live eBay-backed dev environment.
- A disposable live publish -> unpublish -> relist regression test gives a practical QA path for this project's current stage.
- Test design already exposed a real product gap: publish/unpublish/relist history is not yet recorded.

Definition of done:
- [x] Add a DTT existing-site Selenium test that creates a disposable listing and publishes it to eBay.
- [x] The test unpublishes the listing, republishes it, and verifies lifecycle truth preserves the original first-listed date.
- [x] The test verifies marketplace history is visible on the review form after the behavior exists.
- [x] The test cleans up by ending the live listing and removing disposable local data.
- [x] The test is documented with the exact `phpunit.dtt.xml` invocation needed to run it deliberately.

Evidence:
- Command: `ddev exec vendor/bin/phpunit -c phpunit.dtt.xml html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/StockCullReportLifecycleDesktopTest.php`
- Result: pass (`1 test, 7 assertions`)
- Command: `ddev exec vendor/bin/phpunit -c phpunit.dtt.xml html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/LiveMarketplaceLifecycleDesktopTest.php`
- Result: pass (`1 test, 20 assertions`)
- Note: live DTT test required generating a 600x600 image to satisfy eBay picture policy.
