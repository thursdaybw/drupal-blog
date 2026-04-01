# Fix stale location term membership after bulk storage location updates

## Outcome

When listings are bulk-updated to a new storage location, the old location term no longer shows those listings and the new location term shows them correctly.

## Problem observed

On the live `bevansbench.com` site, listings were bulk-updated from one storage location to another.

What happened:
- the listing entities now show the correct storage location field value
- the old location term page still shows those listings
- the new location term page is empty

That implies stale term/listing membership or stale indexing around the storage location taxonomy view rather than a simple field-value failure.

## Why this matters

This makes the live storage-location view untrustworthy after bulk maintenance, which is dangerous operationally even if the underlying field values are correct.

## Scope note

Do not chase this immediately in `bb-ai-listing`.
This is a live-site `bevansbench.com` bug and should be handled there or intentionally left for migration if the standalone product fully replaces this workflow first.

## Definition of done

- root cause is identified
- term pages reflect current storage-location assignments correctly after bulk updates
- any required reindexing or relationship rebuild is documented
- migration impact on `bb-ai-listing` is noted

## Next action

When the live site is safe to touch again, inspect how the storage location term listing is derived and whether bulk updates are bypassing the mechanism that keeps those term pages in sync.
