# BB eBay Legacy Migration

Temporary migration tooling for old eBay listings that still live outside the
Sell Inventory model.

Why this exists
- We do not want to support the old eBay Trading API as a normal runtime path.
- We do need a narrow way to migrate old listings into the Sell Inventory model.
- This module is the migration boundary for that one job.

What this module should own
- `bulkMigrateListing` calls against the Sell Inventory API.
- Small batch migration commands.
- Pilot migration plans and notes.
- Resyncing the mirror after each migration batch.

What this module should not own
- Normal eBay publishing.
- Normal eBay mirror sync.
- Importing migrated listings into `bb_ai_listing`.
- Long-term support for the old Trading API.

Current decision
- We will not add old Trading API support just to audit legacy listings.
- We will migrate using Sell API `bulkMigrateListing` only.
- We will inspect migrated results through `bb_ebay_mirror`.

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

Next implementation step
- Add a command that accepts one or more eBay Item IDs and calls `bulkMigrateListing` in chunks of five.
- After each chunk, resync `bb_ebay_mirror` inventory and offers.
- Report successes and failures clearly.
