# Define A Separate Dev Environment For The BB AI Listing Product

Date opened: 2026-03-25
Owner: bevan

Why:
- The AI listing suite is increasingly behaving like a separate product inside the current site.
- A dedicated dev environment will make product-boundary review, UI work, testing, and eventual extraction materially easier.
- The split should be intentional: identify which modules, config, data, and infrastructure belong to the product before creating another environment around it.

Definition of done:
- [ ] Define the minimum module set that constitutes the BB AI Listing product.
- [ ] Define which supporting config and content are required for that product environment to boot and operate.
- [ ] Identify dependencies that should remain shared platform concerns versus product-local concerns.
- [ ] Decide how the separate dev environment will be provisioned: same repo with alternate site profile or config, or a new project boundary.
- [ ] Document the first practical path to stand up the separate environment without breaking the current site.

Next action:
- Write the first cut of the product boundary: required modules, required config, required entity types, and excluded blog or site concerns.

Links:
- Context: listing-suite modules are now grouped under the `BB AI Listing` package in Drupal admin.
