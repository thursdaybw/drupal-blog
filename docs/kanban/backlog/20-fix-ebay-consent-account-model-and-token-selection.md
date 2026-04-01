# Fix eBay Consent Account Model And Token Selection

Date opened: 2026-04-01
Owner: bevan

Why:
- eBay OAuth consent currently creates a new `ebay_account` row every time, which can leave multiple rows for the same Drupal user and environment.
- Token refresh then becomes nondeterministic if code selects an older row, causing hard-to-diagnose OAuth failures.
- The current data model ties consent records to a Drupal user (`uid`) but the real authority is an eBay account granting this app access.
- Future team usage likely needs multiple Drupal users to operate with the same connected eBay account under permissions, not per-user token silos.

Definition of done:
- [ ] Make callback idempotent: update/replace the current active account record instead of always inserting a new row.
- [ ] Make account selection deterministic and explicit (never `reset()` on unordered entity lists).
- [ ] Add a one-time data migration/cleanup that collapses duplicate rows per `(uid, environment)`.
- [ ] Decide and document the target ownership model:
- [ ] Option A: token ownership is per Drupal user.
- [ ] Option B: token ownership is per site environment/account, with user permissions controlling usage.
- [ ] Implement the chosen ownership model in schema + service layer.
- [ ] Add admin diagnostics UI/reporting for current effective eBay account record and token health.
- [ ] Add regression tests that prove repeated consent does not create ambiguous token selection.

Notes:
- Immediate production symptom seen on 2026-04-01: multiple `ebay_account` rows for `uid=1, environment=production`.
- Business risk: publishing can fail even after a successful consent redirect because refresh may read a stale row.

Next action:
- Implement a short-term stabilization patch in `ebay_connector` + `ebay_infrastructure` (upsert + deterministic newest-row selection), then run cleanup in prod and verify publish flow.
