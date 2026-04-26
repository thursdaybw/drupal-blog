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

The staging test is skipped unless all required environment variables are set.
For local operator runs, prefer putting these in the ignored DDEV env file
`.ddev/.env` rather than passing them inline:

```dotenv
FRAMESMITH_STAGING_BASE_URL=https://bb-drupal-staging.bevansbench.com
# Preferred for manual smoke runs: generate a one-time login URL at runtime.
# FRAMESMITH_STAGING_LOGIN_URL is intentionally not stored here.
FRAMESMITH_STAGING_FIXTURE_PATH=/var/www/html/html/framesmith-browser-smoke.mp4

# Fallback credential mode, if needed:
# FRAMESMITH_STAGING_USERNAME=<user-with-framesmith-api-permission>
# FRAMESMITH_STAGING_PASSWORD=<password>
```

After changing `.ddev/.env`, restart DDEV so the web container sees the
updated environment:

```bash
ddev restart
FRAMESMITH_STAGING_LOGIN_URL="$(ddev exec-stage '../vendor/bin/drush uli --uri=https://bb-drupal-staging.bevansbench.com' | tail -1)" \
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
- Prefer one-time `drush uli` login URLs for manual staging runs so a real password is not stored.
- Keep fallback staging smoke credentials in ignored local DDEV env only if needed; revisit if this becomes CI-operated.
