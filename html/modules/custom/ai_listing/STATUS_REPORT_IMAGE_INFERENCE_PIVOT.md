## Status Report - Image Model / Inference / Upload Form Pivot

Date context: 2026-02-27
Current repo HEAD before uncommitted work: `ca6acc4` (`Add listing image entity and review-form preview`)

### What is completed (committed)

- `listing_image` content entity exists (generic image ownership model for listings)
  - fields: `listing` (ai_book_listing ref), `file` (file ref), `is_metadata_source`, `weight`
- `listing_image` rows are backfilled from legacy `ai_book_listing.images` for live listings
- Review form shows both:
  - `ListingImage entities` (new model)
  - `Legacy ai_book_listing.images` (old field)

### What is completed (uncommitted, but working / intended)

- Review form metadata-source checkboxes under `ListingImage` thumbnails
  - saves `listing_image.is_metadata_source`
- Metadata inference path has been split from condition inference:
  - metadata inference uses selected `listing_image.is_metadata_source = 1`
  - condition inference still uses all legacy images
- Metadata inference now skips processing (returns early) if no metadata images are selected
  - listing remains unchanged (e.g. stays `new`)

### What is currently broken / in-progress

- `AiBookListingUploadForm` metadata preview/tagging UI on create form is NOT working.
- The current file contains extensive experimental code/instrumentation around `managed_file` AJAX.
- Key finding from instrumentation:
  - file IDs are available in `buildForm()` after upload AJAX
  - preview render arrays are being built
  - but Drupal `managed_file` AJAX response only replaces the widget wrapper, so the metadata section outside that wrapper is not updated in the DOM
- Multiple callback timing attempts (`#after_build`, `#process`, `#pre_render`, AJAX callback override) were tried.
- This consumed time and context; the correct next move is a dedicated upload UI (bypass `managed_file` widget behavior), or a pragmatic fallback using the existing managed_file checkboxes as metadata selectors.

### Why this matters

- The backend model is now correct (`listing_image` + metadata flags).
- The current blocker is UI behavior on the create form, not the data model or inference service design.

### Loose ends we were in the middle of (easy to forget)

1. **Inference split** (partially done)
   - metadata inference should use selected `listing_image` rows only ✅
   - condition inference should keep using all images ✅
   - later: remove legacy image dependency once condition path is migrated

2. **Upload/create form**
   - create form must produce `listing_image` rows at creation time ✅ (code written, but verify after UI fix)
   - create form metadata tagging UI should work before save ❌ (current blocker)

3. **Legacy images field deprecation**
   - do NOT remove `ai_book_listing.images` yet
   - keep until upload + inference + review flows are stable on `listing_image`

4. **Review form**
   - currently shows both legacy and new images intentionally (transition mode)
   - later remove legacy image display once confidence is high

### Recommended next steps (in order)

#### Option A (fastest, unblock workflow now)

1. Remove the broken create-form metadata preview section from `AiBookListingUploadForm`
2. Use the existing managed_file checkbox list (`images[file_<fid>][selected]`) as the metadata-source selection on create
3. Persist those selections to `listing_image.is_metadata_source` on save
4. Keep refinement in review form thumbnails (already implemented)

This preserves operator workflow immediately without more time spent fighting `managed_file` AJAX internals.

#### Option B (better UX, more work now) - Dedicated upload UI

1. Build a dedicated create form upload UI (custom file inputs + custom AJAX callback / or no AJAX and staged save)
2. Show thumbnails + metadata checkboxes in the same custom component
3. On save:
   - create `ai_book_listing`
   - move files to listing UUID directory
   - create `listing_image` rows with `weight` + `is_metadata_source`
   - optionally still sync legacy `images` during transition
4. Retire `managed_file` from this form entirely

### Current uncommitted files (important)

- `html/modules/custom/ai_listing/src/Form/AiBookListingReviewForm.php`
  - contains metadata-source checkbox UI for `listing_image` (keep)
- `html/modules/custom/ai_listing/src/Form/AiBookListingUploadForm.php`
  - contains heavy experimental code + instrumentation (review before committing)
- `html/modules/custom/ai_listing/src/Service/AiBookListingDataExtractionProcessor.php`
  - metadata/condition image split logic (keep)
- `html/modules/custom/ai_listing_inference/src/Service/BookExtractionService.php`
  - accepts separate metadata image paths (keep)

### Instrumentation files used during debugging

- `/tmp/ai_listing_upload_form_trace.log`
- `/tmp/ai_listing_upload_preview_debug.log`

These are temporary diagnostics and should not be relied on long-term.

### Practical recovery guidance if context is lost

- Start by reading this file.
- Then inspect current diff for the 4 files above.
- Preserve:
  - review-form metadata checkbox changes
  - inference split changes
- Rework or replace:
  - create-form `managed_file` AJAX preview implementation

