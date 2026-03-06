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
- Migration provenance and run logging.
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
- We will classify legacy listings locally before touching live migration.
- Cleanup for blocked rows should happen inside the migration pipeline, not as a
  separate manual pre-pass.

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

What this mirror is for
- it is a local cache of listings visible through Trading read access
- it is not the same thing as "unmigrated listings"
- Sell-created and already-migrated listings also appear here
- migration state is worked out by comparing this table to the Sell mirror

Current classification work
- `bb_ebay_mirror` now classifies the legacy mirror into buckets:
  - legacy listing with mirrored Sell offer
  - legacy listing with no mirrored Sell offer
  - legacy listing with duplicate SKU
  - legacy listing with missing SKU
  - legacy listing ready to migrate

Why that matters
- we do not want to hit the live Trading API every time we ask migration
  readiness questions
- the mirror gives us a local, repeatable way to sort the backlog into:
  - ready now
  - blocked by duplicate SKU
  - blocked by missing SKU

Current migration rule
1. Pick small batches of legacy eBay Item IDs.
2. Call `bulkMigrateListing` with one to five listing IDs.
3. After each batch:
   - sync mirrored inventory
   - sync mirrored offers
4. Inspect the mirror and reports before moving to the next batch.

Current one-listing pipeline rule
1. Load one legacy listing from the local legacy mirror.
2. If a mirrored Sell offer already exists for that Item ID, stop as
   `already_migrated`.
3. If the legacy SKU is missing:
   - generate `legacy-ebay-<ItemID>`
   - write that SKU back to the legacy listing through the Trading API
4. If the legacy SKU is duplicated:
   - append a deterministic `-M<n>` suffix
   - write that SKU back to the legacy listing through the Trading API
5. Call `bulkMigrateListing` for that one Item ID.
6. Resync Sell inventory and offer mirrors.

What we learned after building the legacy mirror
- the next real migration step is not "migrate everything"
- the next real step is to classify the backlog and then build one pipeline that:
  - checks migration state
  - normalizes duplicate SKUs when needed
  - migrates
  - resyncs mirrors
  - records what changed

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
ddev drush bb-ebay-legacy-migration:convert-listings-to-sell-bulk "176577811710,176582430935,176604590528,176604596280,176779515895"
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

What the pilot changed
- we now know duplicate legacy SKUs are the first true migration blocker
- this is no longer a guess
- the migration pipeline must handle duplicate SKUs explicitly
- "ready to migrate" and "blocked" are now real buckets, not just ideas

## One-listing pipeline command

Normalize one legacy listing SKU if needed, then migrate it immediately:

```bash
ddev drush bb-ebay-legacy-migration:prepare-and-convert-listing-to-sell 176577811710
```

What it does
- loads one listing from `bb_ebay_legacy_listing`
- skips if that Item ID is already visible in `bb_ebay_offer`
- generates `legacy-ebay-<ItemID>` when SKU is missing
- appends a deterministic `-M<n>` suffix when SKU is duplicated
- updates the legacy listing SKU through Trading API only when needed
- migrates that one Item ID through Sell API
- resyncs the Sell mirrors immediately

## Batch pipeline command

Run the one-listing pipeline over the current "ready to migrate" bucket:

```bash
ddev drush bb-ebay-legacy-migration:prepare-and-convert-ready-batch-to-sell --limit=25
```

Dry run preview:

```bash
ddev drush bb-ebay-legacy-migration:prepare-and-convert-ready-batch-to-sell --limit=25 --dry-run
```

What this does
- reads listing IDs from the local "ready to migrate" audit set
- runs the normalize-and-migrate pipeline per listing
- prints a run summary bucketed by outcome

## Single command for full pipeline

Run convert + adopt in one command:

```bash
ddev drush bb-ebay-legacy-migration:run-ready-pipeline --limit=25
```

Dry run preview:

```bash
ddev drush bb-ebay-legacy-migration:run-ready-pipeline --limit=25 --dry-run
```

Optional flags:
- `--account-id=<id>` to target a specific eBay account instead of primary

## One command for full legacy import

Run full legacy import end-to-end:

```bash
ddev drush bb-ebay-legacy-migration:import-all --batch-size=50
```

What this command does:
- syncs legacy Trading listings into `bb_ebay_legacy_listing`
- takes the current unmigrated legacy set (missing mirrored Sell offer)
- prepares SKU when needed (missing or duplicate) and converts to Sell
- syncs mirror rows only for SKUs touched in the batch
- adopts converted listings into `bb_ai_listing`
- loops until no unmigrated legacy listings remain (or max-batches limit)

Usage intent:
- this is an onboarding/import pipeline, not a recurring background sync loop
- run until migration is complete, then stop

Control options:
- `--batch-size=<n>` default `50`
- `--max-batches=<n>` default `0` (no limit)
- `--dry-run` prints current scope only
- `--skip-sell-refresh` skips the initial full Sell mirror refresh

Import behavior notes:
- blocked listings are skipped automatically (from blocked import report)
- successful migrations clear their blocked entry

## Blocked import report

When a listing fails import (for example missing required item specifics in
Trading revise), it is recorded as a blocked row.

Review blocked rows at:

- `/admin/ebay-legacy-migration/blocked`

The report includes:
- eBay Item ID
- status (`Needs manual fix` or `Retry next run`)
- mirrored title and SKU
- first/last failure timestamps
- failure count
- last error message
- direct link to open the eBay item page for manual fixes

After fixing a listing in eBay, rerun import and the block entry is cleared
automatically on successful migration.

## Current migration-readiness buckets

These buckets now exist in `bb_ebay_mirror` and on `/admin/ebay-mirror/report`.

1. Legacy listings with mirrored Sell offer
   - already visible in the Sell model

2. Legacy listings with no mirrored Sell offer
   - not yet visible in the Sell model

3. Legacy listings with duplicate SKU
   - blocked
   - not safe to bulk migrate as-is

4. Legacy listings with missing SKU
   - blocked
   - migration pipeline must generate a migration SKU first

5. Legacy listings ready to migrate
   - not yet in Sell
   - has a usable SKU
   - not in a duplicate legacy SKU group

Current live state when this README was updated
- legacy Trading mirror rows: `1963`
- legacy migrated rows: `364`
- legacy unmigrated rows: `1601`
- legacy ready-to-migrate rows: `253`
- legacy missing-SKU rows: `4`
- duplicate-SKU legacy groups: many, expected from the old SKU scheme

## First adoption command

Adopt one mirrored migrated eBay listing into `bb_ai_listing`:

```bash
ddev drush bb-ebay-legacy-migration:adopt-book 176582430935
```

Adopt one mirrored migrated non-book eBay listing into `bb_ai_listing`:

```bash
ddev drush bb-ebay-legacy-migration:adopt-generic 176577811710
```

Adopt migrated legacy listings in batch using category-based routing:

```bash
ddev drush bb-ebay-legacy-migration:adopt-ready-batch --limit=25 --dry-run
```

```bash
ddev drush bb-ebay-legacy-migration:adopt-ready-batch --limit=25
```

What this first pass does
- loads one mirrored migrated eBay listing by eBay Item ID
- creates one local `bb_ai_listing` row with bundle type `book`
- creates one local active SKU row using the mirrored SKU
- creates one local published eBay publication row
- downloads mirrored eBay image URLs into local managed files
- creates `listing_image` rows so review UI shows local images
- writes one provenance row to `bb_ebay_legacy_listing_link`

What this first pass does not do
- it does not use the old Trading API
- it does not preserve original listing start date in local adoption yet
- it does not infer bundle items

Current adoption rule (locked)
- keep the first pass conservative
- use mirrored title, description, price, condition, and aspects where present
- leave anything missing for manual review later
- for legacy imports, treat all eBay book-category listings as local `book`
- do not create local `book_bundle` rows from legacy imports
- `adopt-book` is now hard-gated by eBay category (book categories only)
- reason:
  - eBay does not reliably separate single-book vs bundle in a way we can trust
  - legacy listings are already live, so AI bundle ingestion flow is not needed
  - this keeps adoption deterministic and reduces misclassification risk

## Listing date gap

The current system does not preserve the original legacy listing date.

What is true right now
- `bulkMigrateListing` does not return an original listing date in its response
- the Sell offer mirror does not expose the original legacy listing date
- the legacy Trading mirror does store the original listing start time in
  `bb_ebay_legacy_listing.ebay_listing_started_at`
- the adoption command does not yet write that start time into
  `bb_ebay_legacy_listing_link`

What that means
- if keeping the original legacy listing date matters, the data source is now
  clear: Trading read, not Sell mirror
- we already have a place to store it: `bb_ebay_legacy_listing_link`
- the missing work is wiring that value through the adoption pipeline

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
- the first adoption command does not yet fill `ebay_listing_started_at`
- legacy provenance stays in this table instead of adding migration-only fields
  directly onto `bb_ai_listing`

## Next planned work

1. Keep the migration-readiness audits current in `bb_ebay_mirror`
2. Add migration run logging in this module
3. Expand adoption from one-off to batch:
   - adopt migrated legacy book listings as local `book`
   - keep non-book listings mirror-only for now
4. Wire original legacy `StartTime` into `bb_ebay_legacy_listing_link`
5. Add a second adoption path for non-book rows later (without forcing book schema)

## Current migration mutation rules

These rules are for the migration pipeline only.

Duplicate legacy SKU
- keep the original SKU shape as much as possible
- append a deterministic suffix to make it unique
- migrate immediately after the SKU update

Missing legacy SKU
- generate a new temporary migration SKU
- current format:
  - `legacy-ebay-<ItemID>`
- example:
  - `legacy-ebay-177300004039`
- this is intentionally obvious and temporary
- after the item is found and properly adopted, it can later be republished with
  a real operational SKU
