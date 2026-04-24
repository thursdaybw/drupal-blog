<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\ExistingSiteJavascript;

use Drupal\Core\Url;
use Drupal\user\Entity\User;
use thursdaybw\DttMultiDeviceTestBase\DeviceProfileTestBase;

/**
 * Dev helper that exercises Stage File Proxy on the review page.
 */
final class StageFileProxyReviewPageMaterializesIntakeImageTest extends DeviceProfileTestBase {

  /**
   * Returns the desktop device profile.
   */
  protected function getDeviceProfileKey(): string {
    return 'desktop';
  }

  /**
   * Verifies the review page materializes a missing intake image.
   */
  public function testReviewPageMaterializesMissingIntakeImage(): void {
    $user = User::load(1);
    $this->assertNotNull($user, 'User 1 must exist for existing-site DTT tests.');
    $this->drupalLogin($user);

    $uri = 'public://ai-intake/set_9-batch-mo8e7y3z-0uedqe/20260419_165521.jpg';
    $realPath = \Drupal::service('file_system')->realpath($uri) ?: '';
    if ($realPath !== '' && file_exists($realPath)) {
      @unlink($realPath);
      clearstatcache(TRUE, $realPath);
    }

    $path = Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => 2928])->toString();
    $this->visit($path);

    $this->assertSession()->pageTextContains('Metadata');

    $deadline = time() + 60;
    do {
      clearstatcache(TRUE, $realPath);
      if ($realPath !== '' && file_exists($realPath)) {
        $this->assertTrue(TRUE);
        return;
      }
      usleep(500000);
    } while (time() < $deadline);

    $this->fail('Review page did not materialize the missing intake image via Stage File Proxy.');
  }

}
