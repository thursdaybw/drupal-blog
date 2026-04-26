<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

require_once __DIR__ . '/FramesmithBrowserSmokeFlowTrait.php';

/**
 * Browser smoke test for the deployed staging Framesmith transcription flow.
 *
 * This test runs Selenium locally but treats staging as a black-box
 * public site:
 * it does not use the local Drupal API, Drupal state, or filesystem.
 *
 * Required environment variables:
 * - FRAMESMITH_STAGING_BASE_URL, for example the staging site base
 *   https://bb-drupal-staging.bevansbench.com
 * - FRAMESMITH_STAGING_LOGIN_URL, preferred one-time login URL from drush uli
 *   OR both FRAMESMITH_STAGING_USERNAME and FRAMESMITH_STAGING_PASSWORD
 * - FRAMESMITH_STAGING_FIXTURE_PATH, a local MP4 path available to the
 *   DTT/PHPUnit process; defaults to the local browser smoke MP4.
 *
 * @group compute_orchestrator
 * @group staging
 */
final class FramesmithStagingBrowserSmokeTest extends DesktopTestBase {

  use FramesmithBrowserSmokeFlowTrait;

  /**
   * Exercises the deployed staging UI against the real staging backend.
   */
  public function testStagingFramesmithTranscribesFixture(): void {
    $baseUrl = $this->requiredEnv('FRAMESMITH_STAGING_BASE_URL');
    $fixturePath = $this->fixturePath();

    $baseUrl = rtrim($baseUrl, '/');
    $this->loginToStaging($baseUrl);

    $this->runFramesmithUploadedFileTranscriptionSmokeFlow(
      $baseUrl . '/framesmith/',
      $fixturePath,
      FALSE,
      900,
    );
  }

  /**
   * Logs in using a one-time URL or username/password credentials.
   */
  private function loginToStaging(string $baseUrl): void {
    $loginUrl = getenv('FRAMESMITH_STAGING_LOGIN_URL');
    if (is_string($loginUrl) && trim($loginUrl) !== '') {
      $this->getSession()->visit(trim($loginUrl));
      $this->assertSession()->waitForText('Log out', 30000);
      return;
    }

    $username = $this->requiredEnv('FRAMESMITH_STAGING_USERNAME');
    $password = $this->requiredEnv('FRAMESMITH_STAGING_PASSWORD');
    $this->loginThroughBrowser($baseUrl, $username, $password);
  }

  /**
   * Returns the local MP4 fixture path used for browser upload.
   */
  private function fixturePath(): string {
    $path = getenv('FRAMESMITH_STAGING_FIXTURE_PATH');
    if (!is_string($path) || trim($path) === '') {
      $path = '/var/www/html/html/framesmith-browser-smoke.mp4';
    }

    return trim($path);
  }

  /**
   * Returns a required env var or skips the staging smoke test.
   */
  private function requiredEnv(string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
      $this->markTestSkipped("Set {$name} to run the staging Framesmith smoke test.");
    }

    return trim($value);
  }

}
