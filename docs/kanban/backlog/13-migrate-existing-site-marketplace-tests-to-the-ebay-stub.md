# Migrate Existing-Site Marketplace Tests To The eBay Stub

Date opened: 2026-03-25
Owner: bevan

Why:
- Existing-site and workflow-style tests are valuable, but they are currently too coupled to live eBay behavior.
- Once the stub exists, normal QA should move onto that deterministic path.
- This reduces cost, fragility, and the risk of accidental live marketplace churn during routine testing.

Definition of done:
- [ ] Update existing marketplace-backed tests to run against the stub by default.
- [ ] Existing-site or DTT scaffolding can bootstrap the stubbed marketplace path consistently.
- [ ] Normal regression commands no longer require live eBay credentials or live eBay side effects.
- [ ] Update test docs and commands to make the stubbed path the standard path.
- [ ] Add or update coverage for at least one full publish -> unpublish or relist workflow against the stub.

Next action:
- Inventory the current tests that still depend on live eBay and classify which should be migrated first.

Links:
- Depends on: `Build An eBay Marketplace Stub For Routine Regression Tests`
- Existing live-style scaffold: `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/StockCullReportLifecycleDesktopTest.php`

