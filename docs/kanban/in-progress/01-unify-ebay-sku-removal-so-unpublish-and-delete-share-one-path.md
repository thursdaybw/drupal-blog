# Unify eBay SKU Removal So Unpublish And Delete Share One Path

Date opened: 2026-03-22
Owner: bevan

Why:
- eBay SKU removal is currently implemented twice with drift between `EbayMarketplacePublisher::deleteSku()` and `EbayMarketplaceUnpublisher`.
- The cull and unpublish flow removes remote offers and inventory but leaves stale local mirror rows behind.
- Mirror rows are current-state read models and should be deleted immediately when eBay removal succeeds or is already absent.

Definition of done:
- [ ] Extract one shared eBay SKU removal service with a single reason to change.
- [ ] `EbayMarketplacePublisher::deleteSku()` delegates to that shared service.
- [ ] `EbayMarketplaceUnpublisher` delegates to that shared service.
- [ ] Shared removal deletes local eBay mirror rows for the SKU immediately.
- [ ] `already unpublished` or missing remote SKU handling still classifies correctly and removes local mirror rows.
- [ ] Add or update tests covering both direct SKU deletion and cull or unpublish paths.

Next action:
- Introduce a dedicated eBay SKU removal service and wire both publisher and unpublisher to it.
