# Record Publish Unpublish Relist History On Marketplace Lifecycle Actions

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
