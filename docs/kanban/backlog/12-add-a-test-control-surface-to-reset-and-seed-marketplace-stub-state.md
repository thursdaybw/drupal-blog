# Add A Test Control Surface To Reset And Seed Marketplace Stub State

Date opened: 2026-03-25
Owner: bevan

Why:
- Browser and existing-site tests need a reliable way to reset and seed stub state across requests.
- A stub that only works inside one PHP process will not be enough for realistic UI tests.
- The test suite needs a deliberate way to clear state, load scenarios, and inspect critical stub state when debugging failures.

Definition of done:
- [ ] Add a test-only control surface for resetting marketplace-stub state.
- [ ] Add a test-only control surface for loading named scenarios or fixture sets.
- [ ] Control surface is safe to keep out of production behavior by environment or test-only wiring.
- [ ] Existing-site or browser tests can use the control surface deterministically across requests.
- [ ] Document the reset or seed mechanism used by the affected tests.

Next action:
- Decide whether the first control surface should be test-only Drush commands, a Drupal test module, or another explicit test harness.

Links:
- Depends on: `Build Listing Workflow Scenario Fixtures For Browser And Existing-Site Tests`
