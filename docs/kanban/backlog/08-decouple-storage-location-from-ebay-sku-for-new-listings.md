# Decouple Storage Location From eBay SKU For New Listings

Date opened: 2026-03-19
Owner: bevan

Why:
- Legacy eBay SKUs encode shelf location because the old workflow relied on the eBay app to locate stock.
- `storage_location` is now the canonical location field in Drupal, so location should stop living inside SKU.
- Mutable location data inside SKU forces unnecessary end-and-relist behavior when stock is moved.
- The immediate operational pain is that setting location is currently coupled to publish and update, and publish is slow because it uploads images to eBay.
- That slows down shelving flow. Location entry needs to become a fast local action, with publish deferred to a separate later bulk step.

Definition of done:
- [ ] Define and implement a location-free SKU policy for new marketplace publications.
- [ ] Publishing flow no longer derives SKU content from `storage_location`.
- [ ] Publishing no longer requires `storage_location` to be set first.
- [ ] `/admin/ai-listings/workbench/location/confirm` updates location only and no longer performs publish or update as part of the same action.
- [ ] Location updates remain local inventory metadata changes and do not trigger eBay image upload or marketplace churn.
- [ ] Existing legacy listings are not forcibly migrated until touched by stock take or relist flows.
- [ ] Add tests covering the new SKU policy and the absence of location encoding for new publishes.

Next action:
- Trace current SKU generation plus `/admin/ai-listings/workbench/location/confirm` so location-setting can be split cleanly from publish or update.

Links:
- Context: legacy SKUs currently carry shelf or location information for eBay operational visibility.
