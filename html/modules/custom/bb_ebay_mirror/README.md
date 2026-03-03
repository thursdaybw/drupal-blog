# BB eBay Mirror

Local mirror of eBay Inventory/Offer state for auditing and reconciliation.

What it stores
- `bb_ebay_inventory_item`: copy of Inventory API inventory items keyed by SKU (title, description, condition, aspects JSON, images JSON, quantity, raw JSON, last seen).
- `bb_ebay_offer`: copy of Inventory API offers keyed by offer ID (price, policies, listing IDs/status, status, raw JSON, last seen).

What it does not do (yet)
- No sync jobs are included here yet.
- No UI is included yet.

Why it is separate
- `ebay_connector` owns the marketplace publish/update adapter.
- `bb_ebay_mirror` owns the read-side mirror and audits.

Current steps
1. Enable the mirror module, run `updb`, confirm the tables exist.
2. Add sync commands that iterate `sell/inventory` and `sell/inventory/offer` to upsert `bb_ebay_inventory_item` and `bb_ebay_offer`.
3. Build audit reports (local listings missing mirror rows, mirror rows with no local listings, SKU/ID mismatches).
4. Wire a future command for `/bulk_migrate_listing` that resyncs the mirror after migration batches so legacy listings stay visible.
