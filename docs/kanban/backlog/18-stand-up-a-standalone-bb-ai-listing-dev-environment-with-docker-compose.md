# Stand Up A Standalone BB AI Listing Dev Environment With Docker Compose

Date opened: 2026-03-25
Owner: bevan

Why:
- The BB AI Listing product is ready to start separating from the current mixed site.
- A dedicated workspace and dev environment will make extraction work, architectural review, and UI development materially cleaner.
- For this product, direct `docker compose` control is preferred over DDEV so the environment stays explicit and easier to reason about.

Definition of done:
- [ ] Create a new standalone workspace under `/home/bevan/workspace` for the BB AI Listing product.
- [ ] Stand up a Drupal environment from the `minimal` profile using plain `docker compose`.
- [ ] Ensure the environment includes the required app, database, and command-line tooling needed for normal development.
- [ ] Document the bootstrap and day-to-day dev commands for the new environment.
- [ ] Prove the new environment can boot Drupal cleanly before product-module extraction begins.

Next action:
- Define the initial environment shape: services, Drupal install path, profile, volumes, ports, and bootstrap commands.

Links:
- Depends on: `Define A Separate Dev Environment For The BB AI Listing Product`
- Context: the goal is to treat Drupal as an application framework for the extracted product rather than carry blog-site baggage forward.
