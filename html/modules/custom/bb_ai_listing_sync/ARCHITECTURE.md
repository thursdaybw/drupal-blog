# bb_ai_listing_sync Architecture

## Export Graph Boundary

`ListingSyncGraphBuilderInterface` is the single application boundary for
building a listing export graph.

- Input: one `bb_ai_listing` root (or root listing ID).
- Output: immutable `ListingSyncGraph`.

`ListingSyncGraph` contains:

- Root listing identity (ID + UUID).
- Related entities grouped by type.
- Deterministic UUID set (sorted).
- Entity counts and totals.

## Why this boundary exists

The graph model is shared infrastructure for multiple use cases:

1. Export orchestration (`bb-ai-listing-sync:export`).
2. Deterministic fingerprint generation.
3. Delta reporting between environments.

Keeping graph derivation out of commands prevents policy drift and keeps one
source of truth for relationship traversal.

## Current traversal policy

Starting at root `bb_ai_listing`:

1. `ai_book_bundle_item` by `bundle_listing`.
2. `ai_listing_inventory_sku` by `listing`.
3. `ai_marketplace_publication` by `listing`.
4. `listing_image` by dynamic owner:
   - owner `bb_ai_listing` = root listing ID
   - owner `ai_book_bundle_item` = each bundle item ID
5. `file` by `listing_image.file` references.

This policy is explicit and deterministic. Any expansion should happen in this
builder, not in command code.

## Deterministic Fingerprint

`ListingSyncGraphFingerprintService` computes a SHA-256 fingerprint from a
canonicalized graph payload:

1. Graph entities (sorted by type, then UUID).
2. Canonicalized field values.
3. Entity references converted to UUID-based references.
4. Legacy sidecar rows (`bb_ebay_legacy_listing_link`, `bb_ebay_legacy_listing`)
   from `LegacyTableSyncService`.

This makes delta detection independent from environment-local numeric IDs.

## Command Layers

- `bb-ai-listing-sync:export`
  - Uses graph builder boundary.
  - Delegates export execution to native `content-sync:export`.

- `bb-ai-listing-sync:fingerprint-map`
  - Uses graph builder + fingerprint service.
  - Outputs stable per-listing fingerprints for cross-environment delta reports.

## Delivery Transport Direction

Current delivery from dev to staging/prod uses host orchestration:

1. Build delta/fingerprint maps.
2. Export listing payload to `content/sync`.
3. Upload payload via SSH/rsync to remote workspace.
4. Run remote import via docker-compose + drush command execution.

This works, but it couples sync operations to host/container layout details:

- SSH access and remote shell tooling.
- Container names and compose paths.
- Drush binary path conventions.
- Host-side bash control flow.

Target direction is application-level delivery via Drupal API:

1. Dev computes delta and export package.
2. Dev sends package to staging/prod API endpoint (authenticated).
3. Target app persists package in temp storage and starts an import job.
4. Dev polls/imports job status via API.

This shifts orchestration into application boundaries, not host shell boundaries.
Benefits:

- Less infrastructure-path fragility.
- Better auditability and idempotency.
- Cleaner retries and error handling.
- Stronger automated test surface at application level.

Until that API transport is implemented, SSH/rsync flow remains the active path.
