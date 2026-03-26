# Add Live DTT Existing-Site Lifecycle Regression Test

Date opened: 2026-03-19
Owner: bevan

Why:
- Existing kernel coverage proves the data model, but not the real existing-site UI workflow against the live eBay-backed dev environment.
- A disposable live publish, unpublish, and relist regression test gives a practical QA path for this project's current stage.
- Test design already exposed a real product gap: publish, unpublish, and relist history is not yet recorded.

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
