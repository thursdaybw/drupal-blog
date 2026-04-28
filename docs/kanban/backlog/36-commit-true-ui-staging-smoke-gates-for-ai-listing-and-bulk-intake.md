# Commit true UI staging smoke gates for AI listing and bulk intake

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

During the production stabilization pass, true browser/UI smoke tests were created and used successfully against staging for:

- bulk image intake;
- AI listing inference through the Workbench UI batch path.

These tests proved real value but are currently untracked working-tree files and should not be lost or silently drift.

## Problem

The production stabilization milestone depends on evidence from these true UI paths, but the test implementation has not yet been reviewed, cleaned up, and committed as a maintainable operator gate.

Untracked files to review:

- `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/AiListingInferenceStagingBrowserSmokeTest.php`
- `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/AiListingStagingBrowserSmokeLoginTrait.php`
- `html/modules/custom/ai_listing/tests/src/ExistingSiteJavascript/BulkImageIntakeStagingBrowserSmokeTest.php`

Related host commands/wrappers should also be reviewed if present or recreated from the working tree:

- `ddev test-bulk-image-intake-staging-smoke`
- `ddev test-ai-listing-inference-staging-smoke`

## Acceptance criteria

- [x] Review the untracked DTT test classes for maintainability and secret safety.
  - PHP syntax lint passed for all three test files; tests require env-provided login URLs and contain no hardcoded password.
- [x] Ensure login URLs and passwords are not stored or printed.
  - Wrappers generate one-time `drush uli` URLs into temporary ignored files and pass them via environment; tests do not store passwords.
- [x] Ensure the tests are skipped unless required staging env/input is present.
  - Shared trait marks tests skipped when required env vars are absent.
- [x] Ensure the tests drive the UI path, not Drush shortcuts, for the workflow under test.
  - Browser tests drive bulk-intake UI and Workbench batch UI; Drush is limited to setup/login/verification wrapper work.
- [x] Keep staging Drush usage limited to setup, verification, cleanup, and one-time login URL generation.
- [x] Add or clean up DDEV host wrappers for operator runs.
  - Selected wrappers: `.ddev/commands/host/test-bulk-image-intake-staging-smoke` and `.ddev/commands/host/test-ai-listing-inference-staging-smoke`.
- [x] Document how to run each smoke gate.
  - Added `Staging UI Smoke Tests` to `html/modules/custom/ai_listing/README.md`.
- [ ] Re-run the tests after cleanup.
- [x] Commit the selected test/wrapper files.
- [x] Remove or ignore temporary artifacts that should not be committed.
  - Removed `.tmp-drush-probes/` and `.tmp-fixtures/`; left `html/framesmith-browser-smoke.mp4` and `docs/dev/HANDOVER.md` uncommitted for separate decision.

## Validation evidence to preserve

- Bulk image intake UI smoke passed by uploading browser files, staging sets, processing sets, verifying listing/image rows, and cleaning up.
- AI listing inference UI smoke passed for a single listing.
- AI listing inference UI batch passed for 10 listings.
- AI listing inference UI stress passed for 60 listings with `failed_count=0`.

## Links

- Milestone: `docs/kanban/done/2026-04-28-stabilize-framesmith-and-ai-listing-on-compute-orchestrator.md`
- Cleanup: `docs/kanban/backlog/35-clean-up-kanban-after-framesmith-ai-runtime-stabilization.md`
