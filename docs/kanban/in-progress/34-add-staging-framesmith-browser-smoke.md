# Add staging Framesmith browser smoke test

## Status

Implemented; staging run is skipped unless explicit credentials and fixture URL are provided.

## Context

Staging now runs a prod-like deployment of the Framesmith/Vast pool branch. We want a browser smoke test that can be run from the local DTT/Selenium environment against the public staging site without requiring Selenium/Chrome on staging.

This is intentionally a black-box browser test:

- local DTT/Selenium drives the browser;
- the browser visits the public staging URL;
- staging handles Drupal, Framesmith, and Vast-backed transcription;
- the test does not use Drupal state, local Drupal APIs, or staging filesystem access.

## Implementation

- Extracted shared Framesmith UI flow helpers into `FramesmithBrowserSmokeFlowTrait`.
- Kept `FramesmithFakeModeBrowserSmokeTest` as the local fake/real opt-in DTT smoke test that can still use Drupal state to select executor mode.
- Added `FramesmithStagingBrowserSmokeTest` for public staging.

## Running the local fake smoke

```bash
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml \
  html/modules/custom/compute_orchestrator/tests/src/ExistingSiteJavascript/FramesmithFakeModeBrowserSmokeTest.php
```

Expected result from implementation run:

```text
OK (1 test, 8 assertions)
```

## Running the staging smoke

The staging test is skipped unless all required environment variables are set:

```bash
FRAMESMITH_STAGING_BASE_URL='https://bb-drupal-staging.bevansbench.com' \
FRAMESMITH_STAGING_USERNAME='<user-with-framesmith-api-permission>' \
FRAMESMITH_STAGING_PASSWORD='<password>' \
FRAMESMITH_STAGING_FIXTURE_PATH='/var/www/html/html/framesmith-browser-smoke.mp4' \
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml \
  html/modules/custom/compute_orchestrator/tests/src/ExistingSiteJavascript/FramesmithStagingBrowserSmokeTest.php
```

`FRAMESMITH_STAGING_FIXTURE_PATH` is a local MP4 path available to the DTT/PHPUnit process. WebDriver uploads this local file through the real Framesmith `<input type="file">`, so the file does not need to exist on staging first. If omitted, the test defaults to `/var/www/html/html/framesmith-browser-smoke.mp4`.

## Verification

Passed:

```text
./scripts/phpcs.sh <Framesmith ExistingSiteJavascript smoke files>
ddev exec vendor/bin/phpunit -c phpunit.dtt.xml \
  html/modules/custom/compute_orchestrator/tests/src/ExistingSiteJavascript/FramesmithFakeModeBrowserSmokeTest.php
```

The staging test skip path passed:

```text
OK, but incomplete, skipped, or risky tests!
Tests: 1, Assertions: 0, Skipped: 1.
```

## Follow-up

- Decide whether to generate the local MP4 fixture automatically for staging runs or keep it as an explicit operator-provided file.
- Decide whether to keep credentials as local env vars only or add a more formal staging smoke-test account/secret flow.
