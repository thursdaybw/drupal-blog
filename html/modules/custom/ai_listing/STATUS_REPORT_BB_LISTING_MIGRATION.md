# BB AI Listing Migration Status (2026-02-27)

## Completed

- Legacy content tables removed:
  - `ai_book_listing`
  - `ai_book_bundle_listing`
  - legacy field tables tied to those entities
- Legacy entity classes/forms/list builders/controllers removed.
- `listing_image` ownership backfilled to `bb_ai_listing`.
- `bb_ai_listing_legacy_map` dropped.
- `ai_listing.install` reduced to an intentionally empty install file.

## Naming cleanup completed

The inventory/publication relationship field machine name has been normalized from `ai_book_listing` to `listing`.

### Entities updated

- `ai_listing_inventory_sku`: `listing` (entity reference to `bb_ai_listing`)
- `ai_marketplace_publication`: `listing` (entity reference to `bb_ai_listing`)

### Service/Form runtime updates

Updated all resolver/query/write usage to `listing` in:

- `AiListingInventorySkuResolver`
- `MarketplacePublicationResolver`
- `MarketplacePublicationRecorder`
- `AiBookListingLocationBatchForm`
- `ai_listing_entity_predelete()` cleanup in `ai_listing.module`

### Database/schema alignment

- DB columns renamed to match field machine names:
  - `ai_listing_inventory_sku.listing`
  - `ai_marketplace_publication.listing`
- Ran entity schema updates (`devel-entity-updates`) until clean.
- Current status: `No entity schema updates required`.

## Smoke status

Validated after cleanup:

- Upload -> infer -> review flow on `bb_ai_listing` (book bundle type = `book`)
- Batch location page loads and status/type rendering is correct
- Publish/delete path works via UI with eBay inventory delete and local delete

## Next immediate focus

- Continue book-bundle workflow implementation on `bb_ai_listing` (`book_bundle` bundle type)
- Keep workflow polish tasks for later pass (non-blocking)
