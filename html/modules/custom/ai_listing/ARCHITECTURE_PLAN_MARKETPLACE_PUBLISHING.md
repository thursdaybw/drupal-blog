## AI Listing Marketplace Publishing Plan

Purpose: preserve shipping velocity for the current book workflow while making inventory and marketplace publication state generic enough to support future marketplaces and product types.

### Agreed Domain Boundaries

- `ai_book_listing` remains the current aggregate for book-specific metadata and workflow UX.
- Canonical inventory identity (SKU) is our domain concept, not an eBay concept.
- Marketplace identifiers (eBay offer ID, eBay listing ID, future Mercari IDs, own-site IDs) are integration state, not domain identity.
- eBay "offer" is an eBay adapter concept and must not become the generic core model.

### Current Direction (Do Now)

- Keep `ai_book_listing` book-specific for now.
- Introduce generic inventory SKU storage as a separate related entity.
- Introduce generic marketplace publication storage as a separate related entity (next step).
- Keep UI book-specific for now; backend model can be more generic than the UI.

### Inventory Model Decisions

- One `ai_book_listing` currently maps to one canonical SKU (business invariant for the current book workflow).
- The SKU entity is generic to support future non-book product types without renaming/migration churn.
- Multi-SKU support is a future capability and should be modeled explicitly (additional SKU records), not via `status` flags or blank-field conventions.
- `published_sku` on `ai_book_listing` is now a compatibility field during the pivot and should not remain the long-term source of truth.

### Publication State Decisions

- "Published" is not SKU state.
- "Published" is marketplace publication state and is per marketplace/channel.
- `ai_book_listing.status` may remain a workflow/UI summary for now, but it is not the long-term canonical source of marketplace publication truth.
- Future UI badges/filters should be projections (for example `published_on_ebay`, `published_anywhere`) derived from marketplace publication records.

### Multi-Marketplace Model (Target Shape)

- `ai_book_listing` (book metadata + internal workflow)
- inventory SKU entity (canonical inventory identity owned by listing)
- marketplace publication entity (generic channel publication record)

The marketplace publication entity should be generic and store:

- listing reference (and ideally SKU reference)
- marketplace key (`ebay`, `mercari`, `own_site`, ...)
- marketplace-specific identifiers (offer ID, listing ID, slug/product ID, etc.)
- publication status (`draft`, `published`, `failed`, `ended`, etc.)
- publication type/format where relevant (e.g. eBay `FIXED_PRICE`, auction)
- sync/error metadata (timestamps, last error)

### eBay-Specific Notes (Keep in Adapter Layer)

- SKU is used as the inventory key in eBay, but remains our canonical inventory identity.
- eBay offer IDs must be stored explicitly if we want update flows without lookup requests.
- eBay listing ID (`ebay_item_id`) is not the same thing as eBay offer ID.

### Migration / Sequencing Plan

1. Pivot SKU storage to the generic inventory SKU entity (in progress).
2. Backfill legacy `published_sku` values into the inventory SKU entity via update hooks.
3. Add a generic marketplace publication entity and start storing eBay offer IDs there.
4. Update publishing flow to write publication records on success.
5. Implement `Publish/Update` UI action using:
   - Drupal workflow state for user intent/routing (short term)
   - stored marketplace publication records for concrete update targeting
   - no ad hoc eBay lookups for offer IDs
6. Later: reduce/deprecate `ai_book_listing.published_sku` and move `published` semantics out of `ai_book_listing.status`.
7. Future audit roadmap: build a scheduled reconciliation that compares Drupal publication records against each marketplace, flags missing/ended offers, and feeds a workbench report for remediation before adding new marketplaces.

### Clean Architecture Rules We Agreed To Apply

- Do not encode cardinality in workflow status flags (e.g. no `status = multi-sku`).
- Do not overload blank strings/nulls to signal structural meaning.
- Put branching behind explicit resolvers/services, not scattered field inspection.
- Keep generic integration state generic; keep book-specific UX/book metadata specific.
- Prefer additive pivots now over semantic rewrites later.
