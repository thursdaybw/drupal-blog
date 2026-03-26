# Reconcile Unpublished Listings So Drupal Publication State Matches eBay

Date opened: 2026-03-17
Owner: bevan

Why:
- Local `ai_marketplace_publication` rows can stay `published` after listings are unpublished or replaced on eBay.
- This creates false positives in mirror audits and confusion in operations.

Definition of done:
- [ ] `ebay-connector:reconcile-listing-publication` handles both `book` and `book_bundle` listings.
- [ ] Reconcile logic updates or removes stale publication rows when eBay has no offers for the active SKU.
- [ ] Running reconcile over published listings leaves `bb-ebay-mirror:audit-missing-offers --account-id=1` at zero for resolved stale rows.
- [ ] Add or update tests that cover bundle and unpublish reconciliation behavior.

Next action:
- Trace and patch reconcile flow in `EbayOfferCommand::reconcilePublishedListingFromEbay()` so bundle listings are included.

Links:
- Command: `ddev exec-prod ../vendor/bin/drush bb-ebay-mirror:audit-missing-offers --account-id=1`
- Command: `ddev exec-prod ../vendor/bin/drush ebay-connector:reconcile-listing-publication <listing_id>`
- Context: stale rows cleaned manually for multiple listings on 2026-03-17.
