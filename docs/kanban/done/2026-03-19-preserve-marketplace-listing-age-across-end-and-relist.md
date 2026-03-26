# Preserve Marketplace Listing Age Across End And Relist

Date opened: 2026-03-19
Owner: bevan

Why:
- End-and-relist is operationally acceptable for refreshing listings and changing SKU policy.
- Current listing-age truth is at risk if relist overwrites the only marketplace start date.
- Stock cull and stock-take decisions need both the original first-listed date and the current live listing start date.

Definition of done:
- [x] Add a durable model for marketplace lifecycle dates that preserves original first-listed truth across relists.
- [x] Distinguish between original marketplace start date and current live publication start date.
- [x] Relist flow records history and events without destroying the original listing-age truth.
- [x] Stock-cull reporting can continue to use the intended age metric explicitly after the model change.
- [x] Add tests for publish, unpublish, and relist lifecycle behavior.

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
