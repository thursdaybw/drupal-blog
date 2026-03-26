# Decide Existing-Site Marketplace Test Strategy And Isolate DTT From Live eBay

Date opened: 2026-03-19
Owner: bevan

Why:
- Existing-site DTT coverage is now valuable for operational workflow QA, but the current dev environment still talks to live eBay.
- That makes lifecycle and marketplace tests more expensive, riskier, and harder to run routinely.
- The project needs a deliberate strategy: real eBay sandbox, an internal marketplace boundary stub, or a documented split between safe stubbed tests and deliberate live smoke tests.

Definition of done:
- [ ] Decide and document the testing strategy for marketplace-backed existing-site tests: eBay sandbox, local stub, or an explicit hybrid model.
- [ ] Implement the chosen strategy enough that DTT marketplace tests no longer require live eBay for normal regression coverage.
- [ ] Update existing DTT test scaffolding and config to use the chosen strategy consistently.
- [ ] Keep a clearly named path for rare deliberate live eBay smoke tests if they remain useful.
- [ ] Add or update docs so test commands and environment expectations are explicit.

Next action:
- Compare the real cost and fidelity of eBay sandbox versus an internal marketplace boundary stub, then choose one intentional path instead of continuing with accidental live eBay testing.

Links:
- Config: `phpunit.dtt.xml`
- Existing DTT base: `dtt_multi_device_test_base/`
- Existing live-style scaffold: `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/StockCullReportLifecycleDesktopTest.php`
