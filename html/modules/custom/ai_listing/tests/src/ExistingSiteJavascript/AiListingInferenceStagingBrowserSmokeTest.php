<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\ExistingSiteJavascript;

use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

require_once __DIR__ . '/AiListingStagingBrowserSmokeLoginTrait.php';

/**
 * Browser smoke for staging AI listing inference through the Workbench UI.
 *
 * The surrounding DDEV host command prepares/restores one real-photo listing.
 * This test only drives the public staging UI and Drupal batch page.
 *
 * @group ai_listing
 * @group staging
 */
final class AiListingInferenceStagingBrowserSmokeTest extends DesktopTestBase {

  use AiListingStagingBrowserSmokeLoginTrait;

  /**
   * Clicks the Workbench UI action and waits for the batch result.
   */
  public function testRunReadyInferenceFromStagingWorkbenchUi(): void {
    $baseUrl = $this->requiredEnv('AI_LISTING_STAGING_BASE_URL');
    $loginUrl = $this->requiredEnv('AI_LISTING_STAGING_LOGIN_URL');
    $this->requiredEnv('AI_LISTING_STAGING_INFERENCE_LISTING_ID');

    $this->loginToStaging($baseUrl, $loginUrl);
    $this->visitStagingPath($baseUrl, '/admin/ai-listings/workbench?status_filter=ready_for_inference');

    $this->assertSession()->waitForText('Listing workbench', 30000);
    $pageText = $this->getSession()->getPage()->getText();
    $this->assertStringNotContainsString(
      'No listings found',
      $pageText,
      'The ready_for_inference Workbench filter should contain the prepared smoke listing.',
    );
    $this->assertSession()->buttonExists('Run AI inference (ready)');

    $this->getSession()->getPage()->pressButton('Run AI inference (ready)');

    $this->waitForBatchStart();
    $this->waitForInferenceBatchResult(2400);
  }

  /**
   * Waits until Drupal's batch UI is visible after the form submit.
   */
  private function waitForBatchStart(): void {
    $deadline = microtime(TRUE) + 45.0;
    while (microtime(TRUE) < $deadline) {
      $text = $this->getSession()->getPage()->getText();
      $url = $this->getSession()->getCurrentUrl();
      if (str_contains($text, 'Starting AI inference batch') || str_contains($url, '/batch')) {
        $this->assertTrue(TRUE);
        return;
      }
      usleep(250000);
    }

    $this->fail('Batch page did not appear after clicking Run AI inference (ready). URL=' . $this->getSession()->getCurrentUrl());
  }

  /**
   * Waits for a success/failure result rendered by the UI batch flow.
   */
  private function waitForInferenceBatchResult(int $timeoutSeconds): void {
    $deadline = time() + $timeoutSeconds;
    while (time() < $deadline) {
      $text = $this->getSession()->getPage()->getText();
      if (
        str_contains($text, 'Processed inference for one listing.') ||
        preg_match('/Processed inference for \d+ listings\./', $text) === 1
      ) {
        $this->assertStringNotContainsString('One listing failed inference.', $text);
        $this->assertStringNotContainsString(' listings failed inference.', $text);
        $this->assertStringNotContainsString('The AI inference batch did not complete successfully.', $text);
        return;
      }
      if (
        str_contains($text, 'One listing failed inference.') ||
        str_contains($text, ' listings failed inference.') ||
        str_contains($text, 'The AI inference batch did not complete successfully.')
      ) {
        $this->fail('Inference batch completed with a UI failure message. TEXT=' . substr($text, 0, 1600));
      }
      usleep(1000000);
    }

    $this->fail('Timed out waiting for the Workbench AI inference batch to finish.');
  }

}
