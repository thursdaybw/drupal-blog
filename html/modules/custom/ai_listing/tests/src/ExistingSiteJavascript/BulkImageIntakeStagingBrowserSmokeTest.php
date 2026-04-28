<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

require_once __DIR__ . '/AiListingStagingBrowserSmokeLoginTrait.php';

/**
 * Browser smoke for staging bulk image intake through the public UI.
 *
 * @group ai_listing
 * @group staging
 */
final class BulkImageIntakeStagingBrowserSmokeTest extends DesktopTestBase {

  use AiListingStagingBrowserSmokeLoginTrait;

  /**
   * Uploads a fixture set through the browser and processes it via the UI.
   */
  public function testBulkImageIntakeUiStagesAndProcessesFixtureSet(): void {
    $baseUrl = $this->requiredEnv('AI_LISTING_STAGING_BASE_URL');
    $loginUrl = $this->requiredEnv('AI_LISTING_STAGING_LOGIN_URL');
    $fixtureList = $this->requiredEnv('BULK_IMAGE_INTAKE_STAGING_FIXTURES');
    $fixtures = array_values(array_filter(array_map('trim', explode(',', $fixtureList))));
    $this->assertNotEmpty($fixtures, 'Expected at least one bulk intake fixture image.');
    foreach ($fixtures as $fixture) {
      $this->assertFileExists($fixture, 'Expected bulk intake fixture image to exist: ' . $fixture);
    }

    $this->loginToStaging($baseUrl, $loginUrl);
    $this->visitStagingPath($baseUrl, '/admin/ai-listing/bulk-intake');

    $this->assertSession()->waitForElement('css', '#ai-bulk-intake-sets-root input[type="file"]', 30000);
    $this->assertSession()->buttonExists('Stage uploaded sets');
    $this->assertSession()->buttonExists('Process staged sets');

    $input = $this->getSession()->getPage()->find('css', '#ai-bulk-intake-sets-root input[type="file"]');
    $this->assertNotNull($input, 'Expected bulk intake file input.');
    $input->attachFile(implode("\n", $fixtures));

    $this->getSession()->getPage()->pressButton('Stage uploaded sets');
    $this->waitForAnyText([
      'Staged one image',
      'Staged ' . count($fixtures) . ' images',
      'Ready',
    ], 300);

    $this->getSession()->getPage()->pressButton('Process staged sets');
    $this->waitForAnyText([
      'Processed one staged set into a listing',
      'Processed 1 staged sets into listings',
    ], 300);
  }

  /**
   * Waits until any candidate text appears on the page.
   *
   * @param string[] $needles
   *   Candidate strings; the first one found completes the wait.
   * @param int $timeoutSeconds
   *   Maximum wait time in seconds.
   */
  private function waitForAnyText(array $needles, int $timeoutSeconds): void {
    $deadline = time() + $timeoutSeconds;
    while (time() < $deadline) {
      $text = $this->getSession()->getPage()->getText();
      foreach ($needles as $needle) {
        if ($needle !== '' && str_contains($text, $needle)) {
          $this->assertTrue(TRUE);
          return;
        }
      }
      usleep(500000);
    }

    $this->fail('Timed out waiting for one of: ' . implode(' | ', $needles));
  }

}
