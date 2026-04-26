# Review Framesmith pool live smoke follow-ups

## Status

Review follow-ups resolved; ready for final diff/commit selection.

## Context

The Framesmith UI path passed a real live smoke test against the Vast-backed Whisper pool. Before committing the current branch, review findings need to be tracked and resolved or explicitly deferred.

This card was created from review of the current working tree and branch delta against `main` on `feature/framesmith-immediate-transcription-kickoff`.

## Checklist

- [x] Fix `VllmPoolManager::tryAcquireExistingInstance()` provider readback failure path so `showInstance()` exceptions do not reference uninitialized `$phase` / `$action`.
- [x] Add/adjust unit coverage for provider readback failure during existing pool candidate acquire.
- [x] Ensure `FramesmithTranscriptionRunner` marks tasks failed when runtime acquisition fails before transcription starts.
- [x] Add/adjust unit coverage for runtime acquisition failure marking the task failed.
- [x] Reset `FramesmithFakeModeBrowserSmokeTest` default executor mode to `fake`, leaving comments documenting opt-in `real` stress mode.
- [x] Replace or remove direct `error_log()` debug calls from `FramesmithWhisperHttpTranscriptionExecutor`.
- [x] Decide route permissions for Framesmith transcription start/upload/status/result; do not leave paid-compute launch behind overly broad `access content` without an explicit decision.
- [x] Document the current Drupal-state task store as smoke/dev storage and create a follow-up if durable task persistence is needed.
- [x] Keep untracked probe/fixture/video artifacts out of the commit unless explicitly selected.

## Notes

The live smoke evidence is valuable, but normal automated DTT/browser runs should default to fake mode so routine test runs do not spend real Vast compute.

For the access-control item, likely options are:

- introduce a narrower custom permission for Framesmith transcription API operations;
- split read/status permissions from compute-start/upload permissions;
- or explicitly document that these endpoints are private/dev-only for this branch.

## Resolution notes

- Provider readback failure now records a transient observation with phase `provider_readback` and action `show Vast instance` instead of referencing uninitialized variables.
- Added unit coverage for provider readback failure on an existing pool candidate.
- Runtime acquisition now happens inside the runner failure-handling block, so acquisition errors mark the task failed.
- Added unit coverage for runtime acquisition failure.
- The browser smoke default executor mode is back to `fake`; setting the class property to `real` remains the explicit opt-in live Vast stress path.
- Direct `error_log()` debug calls were removed from the real Whisper HTTP executor.
- Framesmith transcription API routes now use a dedicated `use framesmith transcription api` permission instead of broad `access content`.
- The DTT browser smoke logs in as user 1 before visiting the Framesmith UI, matching existing DTT patterns and allowing access to the narrowed API permission.
- The Drupal-state task store now documents that it is lightweight smoke/dev storage, not the long-term durable repository.

## Verification

Passed:

```text
git diff --check
./scripts/phpcs.sh <focused changed files>
ddev exec ./vendor/bin/phpunit   html/modules/custom/compute_orchestrator/tests/src/Unit/FramesmithTranscriptionRunnerTest.php   html/modules/custom/compute_orchestrator/tests/src/Unit/VllmPoolManagerTest.php
```

Focused unit result:

```text
OK (6 tests, 55 assertions)
```

Direct browser-smoke invocation without the DTT config failed before executing the test due test bootstrap/tooling:

```text
Trait "Drupal\Tests\XdebugRequestTrait" not found in vendor/weitzman/drupal-test-traits/src/BrowserKitTrait.php
```

The correct DTT invocation was then run and passed:

```text
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml \
  html/modules/custom/compute_orchestrator/tests/src/ExistingSiteJavascript/FramesmithFakeModeBrowserSmokeTest.php

OK (1 test, 8 assertions)
```

Additional focused tests passed:

```text
ddev exec vendor/bin/phpunit \
  html/modules/custom/compute_orchestrator/tests/src/Unit/VllmPoolStateMachineTest.php \
  html/modules/custom/compute_orchestrator/tests/src/Unit/FramesmithWhisperHttpTranscriptionExecutorTest.php

OK (7 tests, 66 assertions)

SYMFONY_DEPRECATIONS_HELPER=disabled ./vendor/bin/phpunit \
  -c /var/www/html/html/core/phpunit.xml.dist \
  /var/www/html/html/modules/custom/compute_orchestrator/tests/src/Kernel/FramesmithTranscriptionApiKernelTest.php

OK (4 tests, 37 assertions)
```
