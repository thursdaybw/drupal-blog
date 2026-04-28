Where You Are Still Coupled

Now let’s be honest.

You still have coupling here:

if product_type === 'book'

But:

It is isolated.

It is contained.

It is inside one private method.

It is not leaking into domain.

That is acceptable at current scale.

The Real Future Steps

Not now.
Not tomorrow.
But when pressure appears.

When You Add a Second Product Type

You will:

Extract buildAspects() into strategy classes.

Introduce EbayProductAspectMapperInterface.

Remove the conditional.

Register mappers via tagged services.

That refactor will be trivial because you isolated the conditional.

You prepared the soil correctly.

When You Add a Second Marketplace

You will:

Implement MarketplacePublisherInterface for Etsy.

Add a publisher registry in application layer.

Route by marketplace key.

Your current interface already supports that.

When You Add Marketplace Selection

You’ll modify:

ListingPublishRequest
to include:

target_marketplace

Or your application use case will select the publisher.

The interface already supports this.

Now The Most Important Question

What is your next architectural move?

Not micro.

Macro.

Given your ambition,
what is the most important structural risk remaining?

It is not polymorphism.

It is this:

Where does product categorization live?

Right now:

Category ID is hardcoded in eBay adapter.

Policies are hardcoded.

Merchant location is hardcoded.

Those are marketplace configuration concerns.

That is the next real axis of volatility.

Not product type polymorphism.

The Correct Next Large Move

Do not touch aspect polymorphism.

Instead:

Extract marketplace configuration.

Create:

EbayMarketplaceConfiguration

Containing:

Default category ID

Policy IDs

Merchant location

Currency

Marketplace ID

Inject it into the publisher.

Remove magic constants.

That will:

Remove environment coupling

Make testing easier

Prevent config fossilization

Allow per-marketplace variations later

That is a higher-value architectural move than aspect plugins.

Summary

Your architecture now:

Clean layering

Proper dependency direction

DTO boundary

Interface boundary

Product semantics in application

Marketplace mapping isolated

The smell is controlled.

The system is evolvable.

The next meaningful refactor is configuration extraction, not product-type polymorphism.

## Image Intake Architecture

Current image intake is split into two workflow stages:

- `Bulk image intake` is the intake source.
- `Add AI Book Listing` is the listing creation surface.

The intent is:

- ingest many photos in one batch
- preserve grouping as sets during intake
- create listings later from those grouped sets
- avoid waiting on per-listing uploads during the listing workflow

### Bulk Intake

`/admin/ai-listing/bulk-intake` now treats one submit as a batch of sets.

- The operator adds `Set 1`, `Set 2`, `Set 3`, and so on in the browser.
- Files are not uploaded per set as they are chosen.
- Upload happens once on final submit.
- Each submitted set is written under `public://ai-intake/<generated-set-id>/...`.
- Intake media names are temporary transport names only; they are not treated as durable product metadata.

This matters architecturally:

- grouping belongs to intake, not to the single-listing add form
- set identity is infrastructural and temporary
- listing metadata remains the responsibility of `bb_ai_listing` and later AI inference

### Listing Creation

`/admin/ai-listing/add` remains a single-listing entry point.

- Manual upload is still supported.
- Intake-backed creation now reads from available intake sets.
- Only intake images not already linked via `listing_image` are offered.
- The operator should select a set and save the listing.

This preserves the correct boundary:

- `add` creates one listing
- `bulk-intake` creates grouped intake inventory

### Assignment Rule

An intake image becomes unavailable for future selection once it is linked to a listing through `listing_image`.

That gives the current system a simple operational invariant:

- one intake image belongs to at most one listing

If reuse is required later, that should be introduced deliberately as a new capability rather than by weakening this rule accidentally.

### Current UX Gap

The target operator flow is:

- select one or more intake sets
- save the listing directly

The architecture now supports set-based intake and set-aware availability filtering, but the listing form UX is still in transition.

Known gap:

- metadata-source selection is still optimized for the staged-upload path
- when saving directly from intake selection, metadata source defaults are currently inferred in form logic rather than being made explicit in the UI

That is a UX/composition gap, not a model-boundary problem.

The next UI refinement should be:

- make intake-set selection the primary control
- remove redundant intermediate controls
- only expose per-image controls when the operator explicitly drills into a set

## Stock Cull Report

`/admin/ai-listings/reports/stock-cull` is a read-model report for culling old eBay stock.

- It reports only current `ebay` publications with `status = published`.
- It uses preserved marketplace lifecycle `first_published_at` as the primary age signal when available.
- It falls back to the current publication row `marketplace_started_at`, then `published_at`.
- It computes `cull score = age in months / price`.
- It orders highest cull score first.

This keeps stock-age analysis out of write workflows and makes the cull surface an explicit reporting concern.

## Stock Cull Picker

`/admin/ai-listings/reports/stock-cull/picker` is the operational shelf-walking companion to the stock cull report.

## Staging UI Smoke Tests

These smoke gates exercise production-style AI listing operator workflows against staging through the browser. They are deliberately different from kernel/unit tests: the point is to prove that the real UI, Drupal batch pages, staging data, and pooled AI runtime path work together.

Run them from the host with DDEV:

```bash
ddev test-bulk-image-intake-staging-smoke
ddev test-ai-listing-inference-staging-smoke
```

### Bulk image intake staging smoke

Command:

```bash
ddev test-bulk-image-intake-staging-smoke
```

What it proves:

- generates disposable local upload fixtures;
- logs into staging with a one-time `drush uli` URL;
- uploads images through the real browser file input;
- clicks the bulk-intake UI controls to stage and process uploaded sets;
- verifies that staging created a book listing with `listing_image` rows;
- removes the created listing/images/files afterward.

The fixture images are intentionally tiny upload fixtures. They prove intake plumbing, not AI inference quality. Real-photo inference is covered by the separate inference smoke.

Useful environment overrides:

```bash
AI_LISTING_STAGING_BASE_URL=https://bb-drupal-staging.bevansbench.com
BULK_INTAKE_STAGING_FILES_PER_SET=3
```

### AI listing inference staging smoke

Command:

```bash
ddev test-ai-listing-inference-staging-smoke
```

What it proves:

- chooses existing staging book/book-bundle listings with real listing photos;
- backs up and resets selected inferred fields to `ready_for_inference`;
- logs into staging with a one-time `drush uli` URL;
- drives the real Workbench UI and Drupal batch flow in the browser;
- waits for inference completion through the UI path;
- verifies inferred metadata/condition fields were populated;
- preserves a backup payload so staging data can be restored or inspected if needed.

The wrapper uses Drush only for staging setup, one-time login URL generation, verification, and cleanup/backup mechanics. The workflow under test is the browser Workbench batch UI, not a Drush shortcut.

Useful environment overrides:

```bash
AI_LISTING_STAGING_BASE_URL=https://bb-drupal-staging.bevansbench.com
AI_INFERENCE_STAGING_LIMIT=1
AI_INFERENCE_STAGING_LISTING_IDS=2318,2317
AI_INFERENCE_STAGING_ALLOW_EXISTING_READY=1
```

### Safety notes

- The browser tests skip when their required environment variables are absent.
- The DDEV wrappers generate one-time login URLs and keep them in temporary ignored files; passwords are not stored in the tests.
- These are staging/operator assurance tests, not routine fast tests.
- The inference smoke uses real staging listings and can spend Vast runtime while exercising pooled AI inference.

## Existing-Site DTT Coverage

For existing-site Selenium coverage against your real DDEV database, use `phpunit.dtt.xml`.

Example:

```bash
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/StockCullReportLifecycleDesktopTest.php
```

`StockCullReportLifecycleDesktopTest` creates a disposable local listing plus marketplace publication/lifecycle rows, then verifies the stock-cull report shows the preserved original listing date after a simulated relist.

Deliberate live marketplace smoke test:

```bash
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/LiveMarketplaceLifecycleDesktopTest.php
```

`LiveMarketplaceLifecycleDesktopTest` is a real publish -> unpublish -> republish test against the current dev marketplace wiring. It creates a disposable listing, verifies lifecycle date preservation and review-form history visibility, then cleans the live listing back down.

- It reuses the same candidate filters as the stock cull report.
- It regroups candidates by `storage_location`.
- It shows listing images through the existing lightbox behavior for quick spine/cover inspection.
- It keeps cull marking local to Drupal through `StockCullSelectionStore`.
- It does not unpublish from eBay or archive listings yet.

This keeps execution concerns separate from the analytical report while still sharing the same candidate query.

## Listing History And Cull Actions

Listing review now has a local append-only history layer backed by `bb_ai_listing_history`.

- History records operational facts such as marketplace takedowns, archive/lost actions, and cull notes.
- Normal marketplace lifecycle actions now also record history:
  - `marketplace_published`
  - `marketplace_unpublished`
  - `marketplace_already_unpublished`
  - `marketplace_republished`
- The stacked cull actions on the review form:
  - unpublish all current marketplace publication rows for the listing
  - set the listing status to either `archived` or `lost`
  - record history entries for the marketplace takedowns, the status transition, and the cull summary
- If a marketplace adapter reports that the remote resource is already gone,
  the cull flow treats that as already unpublished, removes the local
  publication record, and records the abnormal condition in history instead of
  aborting the whole action.
- History is intentionally lighter-weight than a full workflow engine or chatter system.

This gives immediate audit value now without prematurely formalizing a larger status model.

## TODO

- Split review UI by listing bundle.
  - Current state: `generic` listings render through the book review form and
    show book-centric inputs (title/author/isbn/publisher).
  - Data risk: low, because save logic guards writes with `setFieldIfExists`.
  - UX debt: high enough to track, because the form is noisy and confusing for
    non-book listings.
  - Target state: bundle-specific review forms (or display-driven assembly) so
    each bundle only shows relevant fields.
