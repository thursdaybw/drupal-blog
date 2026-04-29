<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\MobileTestBase;

require_once __DIR__ . '/FramesmithBrowserSmokeFlowTrait.php';

/**
 * Mobile browser smoke test for deployed Framesmith transcription flows.
 *
 * This test runs Selenium locally but treats staging/production as a
 * black-box public site:
 * it does not use the local Drupal API, Drupal state, or filesystem.
 *
 * Required environment variables:
 * - FRAMESMITH_SMOKE_BASE_URL, for example staging or production base URL.
 * - FRAMESMITH_SMOKE_LOGIN_URL, preferred one-time login URL from drush uli
 *   OR both FRAMESMITH_SMOKE_USERNAME and FRAMESMITH_SMOKE_PASSWORD.
 * - FRAMESMITH_SMOKE_FIXTURE_PATH, a local MP4 path available to the
 *   DTT/PHPUnit process; defaults to the real phone-like browser smoke MP4.
 *
 * @group compute_orchestrator
 * @group deployed
 * @group mobile
 */
final class FramesmithStagingBrowserSmokeTest extends MobileTestBase {

  use FramesmithBrowserSmokeFlowTrait;

  /**
   * Exercises the deployed UI against the selected real backend.
   */
  public function testDeployedFramesmithTranscribesFixtureOnMobile(): void {
    $baseUrl = $this->requiredEnv('FRAMESMITH_SMOKE_BASE_URL');
    $fixturePath = $this->fixturePath();

    $baseUrl = rtrim($baseUrl, '/');
    $this->loginToDeployedSite($baseUrl);

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
  private function loginToDeployedSite(string $baseUrl): void {
    $loginUrl = getenv('FRAMESMITH_SMOKE_LOGIN_URL');
    if (is_string($loginUrl) && trim($loginUrl) !== '') {
      $this->getSession()->visit(trim($loginUrl));
      $this->assertSession()->waitForText('Log out', 30000);
      return;
    }

    $username = $this->requiredEnv('FRAMESMITH_SMOKE_USERNAME');
    $password = $this->requiredEnv('FRAMESMITH_SMOKE_PASSWORD');
    $this->loginThroughBrowser($baseUrl, $username, $password);
  }

  /**
   * Returns the local MP4 fixture path used for browser upload.
   */
  private function fixturePath(): string {
    $path = getenv('FRAMESMITH_SMOKE_FIXTURE_PATH');
    if (!is_string($path) || trim($path) === '') {
      $path = '/var/www/html/html/framesmith-browser-smoke-real.mp4';
    }

    return trim($path);
  }

  /**
   * Returns a required env var or skips the deployed smoke test.
   */
  private function requiredEnv(string $name): string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
      $this->markTestSkipped("Set {$name} to run the staging Framesmith smoke test.");
    }

    return trim($value);
  }

}
