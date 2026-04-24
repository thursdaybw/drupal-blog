<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\ExistingSiteJavascript;

use Drupal\user\Entity\User;
use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

/**
 * Dev helper that stress-runs ready inference from the workbench UI.
 */
final class WorkbenchRunReadyInferenceDesktopTest extends DesktopTestBase {

  /**
   * Runs the ready-for-inference workbench action and waits for completion.
   */
  public function testRunReadyInferenceFromWorkbench(): void {
    $user = User::load(1);
    $this->assertNotNull($user, 'User 1 must exist for existing-site DTT tests.');
    $this->drupalLogin($user);

    $this->visit('/admin/ai-listings/workbench?status_filter=ready_for_inference');
    $this->assertSession()->pageTextContains('Listing workbench');
    $this->assertSession()->buttonExists('Run AI inference (ready)');

    $this->getSession()->getPage()->pressButton('Run AI inference (ready)');

    $deadline = microtime(TRUE) + 20.0;
    $sawBatchInit = FALSE;
    while (microtime(TRUE) < $deadline) {
      $text = $this->getSession()->getPage()->getText();
      $url = $this->getSession()->getCurrentUrl();
      if (str_contains($text, 'Starting AI inference batch') || str_contains($url, '/batch')) {
        $sawBatchInit = TRUE;
        break;
      }
      usleep(250000);
    }

    if (!$sawBatchInit) {
      $this->fail("Batch page did not appear after click. URL=" . $this->getSession()->getCurrentUrl() . " TEXT=" . substr($this->getSession()->getPage()->getText(), 0, 1200));
    }

    // Let the Drupal batch progress page run naturally. Do not force reloads.
    $deadline = time() + 2400;
    while (time() < $deadline) {
      $text = $this->getSession()->getPage()->getText();
      if (
        str_contains($text, 'Processed inference for one listing.') ||
        str_contains($text, 'Processed inference for ') ||
        str_contains($text, 'One listing failed inference.') ||
        str_contains($text, ' listings failed inference.') ||
        str_contains($text, 'The AI inference batch did not complete successfully.')
      ) {
        $this->assertTrue(TRUE);
        return;
      }
      usleep(1000000);
    }

    $this->fail('Timed out waiting for the workbench AI inference batch to finish.');
  }

}
