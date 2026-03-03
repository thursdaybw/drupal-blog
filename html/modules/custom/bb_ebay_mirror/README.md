# BB eBay Mirror

Local mirror of eBay Inventory/Offer state for auditing and reconciliation.

What it stores
- `bb_ebay_inventory_item`: copy of Inventory API inventory items keyed by SKU (title, description, condition, aspects JSON, images JSON, quantity, raw JSON, last seen).
- `bb_ebay_offer`: copy of Inventory API offers keyed by offer ID (price, policies, listing IDs/status, status, raw JSON, last seen).

What it does not do (yet)
- `bb-ebay-mirror:sync-inventory` is in place.
- `bb-ebay-mirror:sync-offers` is in place.
- A first admin report page is in place.

Why it is separate
- `ebay_connector` owns the marketplace publish/update adapter.
- `bb_ebay_mirror` owns the read-side mirror and audits.

Current steps
1. Keep the mirror tables account-aware.
2. Build audit reports:
   - local published listing with no mirrored inventory
   - local published listing with no mirrored offer
   - mirrored inventory with no local listing
   - mirrored offer with no local listing
   - mirror row whose SKU suffix does not line up with the local listing link
3. Only then add `/bulk_migrate_listing` support and resync after each migration batch.

Current state
- Inventory sync works and has been run against the live account.
- Offer sync works and has been run against the live account.
- First audit report exists:
  - `bb-ebay-mirror:audit-missing-inventory`
- Second audit report exists:
  - `bb-ebay-mirror:audit-missing-offers`
- Third audit report exists:
  - `bb-ebay-mirror:audit-orphaned-inventory`
- Fourth audit report exists:
  - `bb-ebay-mirror:audit-orphaned-offers`
- Fifth audit report exists:
  - `bb-ebay-mirror:audit-sku-link-mismatch`
- First admin report page exists:
  - `/admin/ebay-mirror/report`
- That first audit currently reports no local published eBay listings missing mirrored inventory for the primary account.
- The second audit still needs to be run against the live account.
- The third audit still needs to be run against the live account.
- The fourth audit still needs to be run against the live account.
- The fifth audit now checks the identifier hidden inside the SKU.
- It resolves new SKUs by `listing_code`.
- It falls back to legacy entity ID for older SKUs.

Next planned audit
- Compare the identifier encoded in the mirrored SKU suffix with the local listing/publication linkage.
- This is more opinionated than the first four audits because it depends on the SKU convention.
- It helps surface stale old-SKU artefacts and publication drift more clearly.

Why Drush first
- These reports will likely want an admin UI later.
- For now, Drush is enough to prove the mirror logic and the audit rules.
- Once the audit set settles down, a GUI can sit on top of the same services.

Current admin UI
- `/admin/ebay-mirror/report`
- Shows summary counts and the current audit buckets in one place.
- This is the first read-side page for the mirror.

## Current command

Sync inventory into the local mirror table:

```bash
ddev drush bb-ebay-mirror:sync-inventory
```

Or sync one specific eBay account:

```bash
ddev drush bb-ebay-mirror:sync-inventory 1
```

Sync offers for the mirrored inventory SKUs:

```bash
ddev drush bb-ebay-mirror:sync-offers
```

Or sync one specific eBay account:

```bash
ddev drush bb-ebay-mirror:sync-offers 1
```

Audit local published eBay listings that are missing mirrored inventory:

```bash
ddev drush bb-ebay-mirror:audit-missing-inventory
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-missing-inventory 1
```

Audit local published eBay listings that are missing mirrored offers:

```bash
ddev drush bb-ebay-mirror:audit-missing-offers
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-missing-offers 1
```

Audit mirrored inventory rows that have no local published listing:

```bash
ddev drush bb-ebay-mirror:audit-orphaned-inventory
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-orphaned-inventory 1
```

Audit mirrored offers that have no local published listing:

```bash
ddev drush bb-ebay-mirror:audit-orphaned-offers
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-orphaned-offers 1
```

Audit mirrored SKUs whose embedded identifier disagrees with Drupal's local
listing/publication linkage:

```bash
ddev drush bb-ebay-mirror:audit-sku-link-mismatch
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-sku-link-mismatch 1
```
