# Define A Small Live eBay Smoke Test Suite

Date opened: 2026-03-25
Owner: bevan

Why:
- A stub should handle routine regression coverage, but a small amount of real integration checking is still valuable.
- Live marketplace verification should be deliberate, rare, and clearly separated from normal tests.
- Without that boundary, live tests tend to sprawl and become accidental dependencies again.

Definition of done:
- [ ] Define the minimal live eBay smoke flows worth keeping.
- [ ] Document the exact commands, prerequisites, and cleanup expectations for live smoke tests.
- [ ] Keep live smoke tests clearly named and separate from the default test path.
- [ ] Ensure live smoke tests remain small enough to run intentionally rather than routinely.
- [ ] Document what confidence the live suite provides and what it intentionally does not cover.

Next action:
- Identify the smallest useful set of live eBay checks after the stub becomes the default regression path.

Links:
- Depends on: `Migrate Existing-Site Marketplace Tests To The eBay Stub`

