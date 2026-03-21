# In Progress

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

Next action:
- QA the infrastructure change on an environment with safe relist candidates, then move to `done.md`.

Scope update:
- This item is infrastructure-complete and ready for QA, not a user-facing UX change.

Blocked:
- Safe end-to-end QA path is awkward because dev is tied to live eBay data and sandbox is not currently integrated.

Evidence:
- Command: `ddev drush updb -y`
- Result: dev schema updated cleanly, including marketplace lifecycle storage.
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/listing_publishing/tests/src/Kernel/ListingPublisherTest.php'`
- Result: pass (`6 tests, 49 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/listing_publishing/tests/src/Kernel/MarketplaceUnpublishServiceTest.php'`
- Result: pass (`3 tests, 19 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/ai_listing/tests/src/Kernel/EbayStockCullReportQueryTest.php'`
- Result: pass (`4 tests, 22 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist /var/www/html/html/modules/custom/ai_listing/tests/src/Kernel/AiListingStockCullReportControllerTest.php'`
- Result: pass (`5 tests, 36 assertions`)
- Command: `ddev exec bash -lc 'cd /var/www/html && ./vendor/bin/phpunit -c /var/www/html/html/core/phpunit.xml.dist --filter testAdoptBookListingCreatesLocalListingAndLinks /var/www/html/html/modules/custom/bb_ebay_legacy_migration/tests/src/Kernel/EbayLegacyListingAdoptionServiceTest.php'`
- Result: pass (`1 test, 30 assertions`)
- Commit: `f831f30`
- Commit: `9148703`
