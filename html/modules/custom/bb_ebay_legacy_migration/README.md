# BB eBay Legacy Migration

Temporary migration tooling for old eBay listings that still live outside the
Sell Inventory model.

Why this exists
- We do not want to support the old eBay Trading API as a normal runtime path.
- We do need a narrow way to read and migrate old listings into the Sell Inventory model.
- This module is the boundary for that one job.

What this module should own
- Read-only legacy listing mirror sync from the Trading API.
- `bulkMigrateListing` calls against the Sell Inventory API.
- Small batch migration commands.
- Small adoption commands that bring selected migrated listings into
  `bb_ai_listing`.
- Pilot migration plans and notes.
- Resyncing the mirror after each migration batch.
- Legacy provenance rows that link adopted Drupal listings back to old eBay
  Item IDs.

What this module should not own
- Normal eBay publishing.
- Normal eBay mirror sync.
- Long-term support for the old Trading API.

Current decision
- We will keep Trading API use narrow and read-only for legacy listing discovery.
- We will keep Sell API as the migration path.
- We will inspect migrated results through `bb_ebay_mirror`.

## Legacy listing mirror

This module now owns a local mirror of active legacy eBay listings:

- `bb_ebay_legacy_listing`

Why this exists
- we need a local way to see which old listings still live only in the traditional API
- we need a local way to compare legacy listings against the Sell mirror
- we do not want to loop through the live traditional API every time we ask a migration question

What it stores
- eBay Item ID
- eBay account ID
- seller SKU
- title
- original listing start time
- listing status
- primary category ID
- raw XML
- last seen

Current sync command

```bash
ddev drush bb-ebay-legacy-migration:sync-legacy-listings
```

What it does
- calls the Trading API read-only
- syncs active legacy listings into `bb_ebay_legacy_listing`
- deletes rows that are no longer present in the active legacy set

Current migration rule
1. Pick small batches of legacy eBay Item IDs.
2. Call `bulkMigrateListing` with one to five listing IDs.
3. After each batch:
   - sync mirrored inventory
   - sync mirrored offers
4. Inspect the mirror and reports before moving to the next batch.

Why the mirror matters first
- It lets us see exactly what the migration created.
- It lets us spot duplicate SKU problems and stale rows.
- It keeps migration work separate from the book-centric `bb_ai_listing` model.

## Pilot batch

These are the first five legacy Item IDs chosen for the migration pilot.

1. `176577811710`
   - title: `Teenage Mutant Ninja Turtles TMNT Small Ceramic Cup Mug - Kinnerton 2012`
   - type: non-book
   - legacy SKU: `2024 September A01`

2. `176582430935`
   - title: `Official AFL NAB AusKick 20 Yr T-Shirt - 2015 Celebration - Size L - Great Cond`
   - type: non-book
   - legacy SKU: `2024 September A01`

3. `176604590528`
   - title: `3x Crime Thriller Books Bundle - Free Shipping`
   - type: book bundle on eBay
   - legacy SKU: `2024 September A02`

4. `176604596280`
   - title: `3x Psychological Thriller Mystery Books Bundle - Free Shipping`
   - type: book bundle on eBay
   - legacy SKU: `2024 September A02`

5. `176779515895`
   - title: `Sci-Fi & Action DVD Lot – Falling Skies 1-4, The 100, MIB Trilogy & More!`
   - type: non-book
   - legacy SKU: `brn-cbrd-DVDWB01 - 2025-08-01 002`

Why this pilot set was chosen
- It includes duplicate SKU pairs.
- It includes non-book listings.
- It includes bundle-style book listings.
- It includes one listing with a different SKU shape.

Questions this pilot should answer
- How does `bulkMigrateListing` behave when two old listings share the same SKU?
- Do non-book listings migrate cleanly into the Inventory model?
- Do migrated bundles appear in the mirror in a usable shape?
- Does the unusual DVD SKU survive the migration as-is?

## First command

Migrate one or more legacy eBay Item IDs into the Sell Inventory model:

```bash
ddev drush bb-ebay-legacy-migration:migrate-listings "176577811710,176582430935,176604590528,176604596280,176779515895"
```

How it works
- splits the provided Item IDs into chunks of five
- calls Sell API `bulkMigrateListing` for each chunk
- resyncs mirrored inventory after each chunk
- resyncs mirrored offers after each chunk
- prints a per-listing summary for each chunk:
  - `listingId`
  - `statusCode`
  - migrated `sku`
  - migrated `offerId`
  - first error message when present

## Pilot result

The first pilot batch was useful.

What happened
- eBay migrated one listing from each duplicate-SKU pair
- eBay rejected the second listing in each duplicate-SKU pair
- the non-book DVD listing migrated cleanly

What that means
- duplicate legacy SKUs are a real migration constraint
- eBay reports the duplicate-SKU failure as a vague `500`
- the useful clue is hidden in the error parameters, where eBay includes the
  conflicting `SKU`

What the pilot proved
- `2024 September A01`
  - success: `176582430935`
  - fail: `176577811710`
- `2024 September A02`
  - success: `176604590528`
  - fail: `176604596280`
- `brn-cbrd-DVDWB01 - 2025-08-01 002`
  - success: `176779515895`

Current migration rule
- do not bulk migrate duplicate-SKU legacy listings blindly
- migrate in small batches
- sync the mirror after each batch
- inspect the mirror before widening the migration

## First adoption command

Adopt one mirrored migrated eBay listing into `bb_ai_listing`:

```bash
ddev drush bb-ebay-legacy-migration:adopt-book 176582430935
```

What this first pass does
- loads one mirrored migrated eBay listing by eBay Item ID
- creates one local `bb_ai_listing` row with bundle type `book`
- creates one local active SKU row using the mirrored SKU
- creates one local published eBay publication row
- writes one provenance row to `bb_ebay_legacy_listing_link`

What this first pass does not do
- it does not use the old Trading API
- it does not preserve original listing start date yet
- it does not infer bundle items
- it does not support non-book adoption yet

Current adoption rule
- keep the first pass conservative
- use mirrored title, description, price, condition, and aspects where present
- leave anything missing for manual review later

## Listing date gap

The current system does not preserve the original legacy listing date.

What is true right now
- `bulkMigrateListing` does not return an original listing date in its response
- `bb_ebay_mirror` does not currently store an original legacy listing date
- `bb_ai_listing` does not currently capture the original eBay listing date for
  adopted legacy listings

What that means
- if keeping the original legacy listing date matters, we need to make that an
  explicit requirement and decide where to store it
- for now, migration is focused on getting legacy listings into the Sell
  Inventory model and into the local mirror cleanly

## Legacy provenance table

This module now owns a small side table:

- `bb_ebay_legacy_listing_link`

Why this is a table and not base fields on `bb_ai_listing`
- the data is provenance, not core listing truth
- it is specific to one ingress path
- it would muddy the main listing entity if stored as normal base fields

What it stores
- local `bb_ai_listing` ID
- eBay account ID
- origin type
- original legacy eBay Item ID
- original legacy eBay listing start time
- legacy source SKU

What it is for
- preserving migration provenance
- preserving original listing start date if we later fetch it from the
  traditional API
- linking adopted Drupal listings back to the legacy eBay record they came from

Current state
- the schema exists
- the first adoption command now writes to it
- legacy provenance stays in this table instead of adding migration-only fields
  directly onto `bb_ai_listing`
