# BB eBay Mirror

Local mirror of eBay Inventory/Offer state for auditing and reconciliation.

Important rule
- Mirror sync is now full reconcile sync.
- That means a sync does not just insert and update rows it sees.
- It also deletes mirror rows for the account that eBay did not return in the current sync run.
- The mirror should reflect current remote state, not "anything we have ever seen".
- Successful publish, update, and delete operations in `ebay_connector` also refresh the affected mirror rows immediately.
- Successful unpublish/takedown operations must also delete the affected mirror rows immediately.
- Cron/full sync is still needed later as the safety net for drift and manual eBay changes.

What it stores
- `bb_ebay_inventory_item`: copy of Inventory API inventory items keyed by SKU (title, description, condition, aspects JSON, images JSON, quantity, raw JSON, last seen).
- `bb_ebay_offer`: copy of Inventory API offers keyed by offer ID (price, policies, listing IDs/status, status, raw JSON, last seen).

What it does not do (yet)
- `bb-ebay-mirror:sync-inventory` is in place.
- `bb-ebay-mirror:sync-offers` is in place.
- A first admin report page is in place.
- Legacy listing migration is being split into `bb_ebay_legacy_migration`.

Why it is separate
- `ebay_connector` owns the marketplace publish/update adapter.
- `bb_ebay_mirror` owns the read-side mirror and audits.

Current steps
1. Keep the mirror tables account-aware.
2. Build the core audit set:
   - local published listing with no mirrored inventory
   - local published listing with no mirrored offer
   - mirrored inventory with no local listing
   - mirrored offer with no local listing
   - mirror row whose SKU suffix does not line up with the local listing link
   - local listing that resolves from multiple mirrored inventory SKUs
   - local listing that resolves from multiple mirrored offers
   - legacy listing with no mirrored Sell offer
   - legacy listing with mirrored Sell offer
3. Use those audits to understand stale eBay state before touching migration.
4. Hand migration work off to `bb_ebay_legacy_migration`.

Current state
- Inventory sync works and has been run against the live account.
- Offer sync works and has been run against the live account.
- Inventory sync now does full reconcile for one account.
- Offer sync now does full reconcile for one account.
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
- Sixth audit report exists:
  - `bb-ebay-mirror:audit-multiple-inventory`
- Seventh audit report exists:
  - `bb-ebay-mirror:audit-multiple-offers`
- Eighth audit report exists:
  - `bb-ebay-mirror:audit-legacy-unmigrated`
- Ninth audit report exists:
  - `bb-ebay-mirror:audit-legacy-migrated`
- Tenth audit report exists:
  - `bb-ebay-mirror:audit-legacy-duplicate-sku`
- Eleventh audit report exists:
  - `bb-ebay-mirror:audit-legacy-missing-sku`
- Twelfth audit report exists:
  - `bb-ebay-mirror:audit-legacy-ready-to-migrate`
- First admin report page exists:
  - `/admin/ebay-mirror/report`
- That first audit currently reports no local published eBay listings missing mirrored inventory for the primary account.
- The fifth audit now checks the identifier hidden inside the SKU.
- It resolves new SKUs by `listing_code`.
- It falls back to legacy entity ID for older SKUs.
- The sixth and seventh audits answer the multiplicity question:
  - does one local listing now resolve from more than one mirrored SKU or offer?
- The eighth and ninth audits classify the legacy mirror:
  - legacy listing with no mirrored Sell offer = not yet visible in Sell
  - legacy listing with mirrored Sell offer = already visible in Sell
- The tenth audit identifies duplicate legacy SKU groups:
  - these are blocked migration candidates because Sell inventory is keyed by SKU
- The eleventh audit identifies legacy listings with no usable SKU:
  - these are also blocked migration candidates
- The twelfth audit identifies the first clean migration queue:
  - unmigrated
  - non-empty SKU
  - not in a duplicate legacy SKU group
- After deleting four stale old-SKU rows on eBay and rerunning sync:
  - orphaned inventory is clean
  - orphaned offers are clean
  - multiple mirrored inventory per listing is clean
  - multiple mirrored offers per listing is clean

Next planned work
- Keep this module focused on mirror sync, audits, and report UI.
- Put `bulkMigrateListing` work in `bb_ebay_legacy_migration`.
- Legacy adoption rule lives in `bb_ebay_legacy_migration`:
  - adopt legacy eBay book listings as local `book` (not `book_bundle`).

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

Audit local listings that resolve from multiple mirrored inventory SKUs:

```bash
ddev drush bb-ebay-mirror:audit-multiple-inventory
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-multiple-inventory 1
```

Audit local listings that resolve from multiple mirrored offers:

```bash
ddev drush bb-ebay-mirror:audit-multiple-offers
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-multiple-offers 1
```

Audit legacy listings that do not yet have a mirrored Sell offer:

```bash
ddev drush bb-ebay-mirror:audit-legacy-unmigrated
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-legacy-unmigrated 1
```

Audit legacy listings that already have a mirrored Sell offer:

```bash
ddev drush bb-ebay-mirror:audit-legacy-migrated
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-legacy-migrated 1
```

Audit legacy listings that still share duplicate SKUs:

```bash
ddev drush bb-ebay-mirror:audit-legacy-duplicate-sku
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-legacy-duplicate-sku 1
```

Audit legacy listings that have no usable SKU:

```bash
ddev drush bb-ebay-mirror:audit-legacy-missing-sku
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-legacy-missing-sku 1
```

Audit legacy listings that are ready to migrate:

```bash
ddev drush bb-ebay-mirror:audit-legacy-ready-to-migrate
```

Or audit one specific eBay account:

```bash
ddev drush bb-ebay-mirror:audit-legacy-ready-to-migrate 1
```
