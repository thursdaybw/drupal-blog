# Build Listing Workflow Scenario Fixtures For Browser And Existing-Site Tests

Date opened: 2026-03-25
Owner: bevan

Why:
- The marketplace stub alone is not enough to make browser and existing-site tests productive.
- UI and workflow tests need reusable ways to create realistic listings in known lifecycle states without rebuilding those setups in every test.
- The project needs a fixture or scenario layer that can create coherent local listing state together with matching marketplace-stub state.

Definition of done:
- [ ] Add a reusable fixture builder or scenario factory for common listing workflow states.
- [ ] Fixtures can create local listings in states needed for UI and workflow tests, including post-inference, post-review, publish-ready, archived, and stock-take scenarios.
- [ ] Fixtures can also seed matching marketplace-stub state where required.
- [ ] At least one browser or existing-site test uses the new fixture path instead of ad hoc setup.
- [ ] Document the supported initial scenarios well enough for future test authors to extend them coherently.

Next action:
- Define the first small set of reusable listing scenarios needed for publish, unpublish, review, stock-take, and archive flows.

Links:
- Depends on: `Build An eBay Marketplace Stub For Routine Regression Tests`

