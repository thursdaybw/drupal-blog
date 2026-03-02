## AI Listing Marketplace Publishing Plan

Purpose: preserve shipping velocity for the current book workflow while making inventory and marketplace publication state generic enough to support future marketplaces and product types.

Status note:
- This file started as a forward plan.
- Parts of it are now done.
- It should describe the current shape of the system, not just the original idea.

### Agreed Domain Boundaries

- `bb_ai_listing` is the current listing aggregate for book-specific metadata and workflow UX.
- Canonical inventory identity (SKU) is our domain concept, not an eBay concept.
- Marketplace identifiers (eBay offer ID, eBay listing ID, future Mercari IDs, own-site IDs) are integration state, not domain identity.
- eBay "offer" is an eBay adapter concept and must not become the generic core model.
- `listing_publishing` is the generic publishing application layer.
- `ebay_connector` is an outer marketplace adapter, not a hidden dependency of generic publishing.

### Current Direction (Do Now)

- Keep `bb_ai_listing` book-specific for now.
- Keep inventory SKU storage as a separate related entity.
- Keep marketplace publication storage as a separate related entity.
- Keep UI book-specific for now; backend model can be more generic than the UI.
- Keep publication rows as current-state rows, not local history rows.

### Inventory Model Decisions

- One `bb_ai_listing` currently maps to one canonical SKU (business invariant for the current book workflow).
- The SKU entity is generic to support future non-book product types without renaming/migration churn.
- Multi-SKU support is a future capability and should be modeled explicitly (additional SKU records), not via `status` flags or blank-field conventions.
- The old `published_sku` field is gone and is no longer part of the model.

### Publication State Decisions

- "Published" is not SKU state.
- "Published" is marketplace publication state and is per marketplace/channel.
- `bb_ai_listing.status` may remain a workflow/UI summary for now, but it is not the long-term canonical source of marketplace publication truth.
- Future UI badges/filters should be projections (for example `published_on_ebay`, `published_anywhere`) derived from marketplace publication records.
- Local publication rows are current-state only.
- If something is no longer published, the local publication row should be deleted.
- We are not keeping local `ended` publication history in `ai_marketplace_publication`.

### Multi-Marketplace Model (Target Shape)

- `bb_ai_listing` (book metadata + internal workflow)
- inventory SKU entity (canonical inventory identity owned by listing)
- marketplace publication entity (generic channel publication record)

The marketplace publication entity should be generic and store:

- listing reference (and ideally SKU reference)
- marketplace key (`ebay`, `mercari`, `own_site`, ...)
- marketplace-specific identifiers (offer ID, listing ID, slug/product ID, etc.)
- publication status (`draft`, `published`, `failed`, etc.)
- publication type/format where relevant (e.g. eBay `FIXED_PRICE`, auction)
- sync/error metadata (timestamps, last error)

### eBay-Specific Notes (Keep in Adapter Layer)

- SKU is used as the inventory key in eBay, but remains our canonical inventory identity.
- eBay offer IDs must be stored explicitly if we want update flows without lookup requests.
- eBay listing ID (`ebay_item_id`) is not the same thing as eBay offer ID.

### Migration / Sequencing Plan

1. SKU storage pivot is done.
2. Generic marketplace publication storage is in place.
3. Generic publishing has been split from the eBay adapter.
4. Current publication rows are now treated as current state only.
5. Current audit direction is to mirror eBay inventory and offers more directly, then compare Drupal state against that mirror.
6. Future marketplaces should plug into `listing_publishing` through their own adapters, not by bending the core around eBay.

### Clean Architecture Rules We Agreed To Apply

- Do not encode cardinality in workflow status flags (e.g. no `status = multi-sku`).
- Do not overload blank strings/nulls to signal structural meaning.
- Put branching behind explicit resolvers/services, not scattered field inspection.
- Keep generic integration state generic; keep book-specific UX/book metadata specific.
- Prefer additive pivots now over semantic rewrites later.
- Do not keep dead operational history rows unless they pay their way in current workflow value.
