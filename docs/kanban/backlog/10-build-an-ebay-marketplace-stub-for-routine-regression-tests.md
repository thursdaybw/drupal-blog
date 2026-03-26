# Build An eBay Marketplace Stub For Routine Regression Tests

Date opened: 2026-03-25
Owner: bevan

Why:
- Normal regression coverage should not depend on live eBay.
- The chosen testing strategy is to use an internal marketplace stub for routine tests and keep live eBay for deliberate smoke tests only.
- The stub needs to model the contract your application actually uses, not a vague full-platform imitation.

Definition of done:
- [ ] Define the eBay operations the stub must support first: publish, unpublish, relist, reconcile, and any required inventory or offer reads.
- [ ] Implement a deterministic stub adapter behind the existing marketplace boundary used by application code.
- [ ] Stub responses are controllable enough to simulate success, already-unpublished, missing offer, and similar operational cases.
- [ ] The stub can be used from automated tests without external network access.
- [ ] Add tests for the stubbed adapter behavior itself where appropriate.

Next action:
- Trace the current marketplace ports used by publish, unpublish, relist, and reconcile flows and define the minimum stub surface.

Links:
- Decision record: `docs/kanban/done/2026-03-19-decide-existing-site-marketplace-test-strategy-and-isolate-dtt-from-live-ebay.md`

