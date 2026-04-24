<?php

declare(strict_types=1);

namespace Drupal\Tests\compute_orchestrator\ExistingSiteJavascript;

use Drupal\user\Entity\User;
use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

/**
 * Dev helper that exercises Qwen acquisition from the pool admin UI.
 */
final class VllmPoolAcquireQwenSmokeTest extends DesktopTestBase {

  /**
   * Acquires a Qwen workload from the admin pool page.
   */
  public function testAcquireQwenFromAdminPoolPage(): void {
    $user = User::load(1);
    $this->assertNotNull($user, 'User 1 must exist for existing-site DTT tests.');
    $this->drupalLogin($user);

    $this->visit('/admin/compute-orchestrator/vllm-pool');
    $this->assertSession()->pageTextContains('Pool inventory');
    $this->assertSession()->buttonExists('Acquire Qwen (qwen-vl)');

    $this->getSession()->getPage()->pressButton('Acquire Qwen (qwen-vl)');

    // Batch UI should appear quickly.
    $this->assertSession()->waitForText(
      'Acquiring pooled runtime for Qwen',
      10000,
    );

    // Wait for either success or explicit failure from the
    // batch finish callback.
    $deadline = time() + 900;
    while (time() < $deadline) {
      $text = $this->getSession()->getPage()->getText();
      if (str_contains($text, 'Acquired ') && str_contains($text, 'workload=qwen-vl')) {
        $this->assertTrue(TRUE);
        return;
      }
      if (
        str_contains($text, 'Acquire failed for qwen-vl:')
        || str_contains($text, 'Acquire failed for requested workload:')
      ) {
        $this->fail($text);
      }
      usleep(500000);
      $this->getSession()->reload();
    }

    $this->fail('Timed out waiting for Qwen acquire batch to finish.');
  }

}
