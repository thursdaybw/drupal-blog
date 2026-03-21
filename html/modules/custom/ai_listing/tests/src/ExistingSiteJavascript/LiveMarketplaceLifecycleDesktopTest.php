<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\ExistingSiteJavascript;

use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\ListingImage;
use Drupal\file\Entity\File;
use Drupal\listing_publishing\Service\ListingPublisher;
use Drupal\listing_publishing\Service\MarketplaceUnpublishService;
use Drupal\user\Entity\User;
use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

/**
 * @group live_ebay
 */
final class LiveMarketplaceLifecycleDesktopTest extends DesktopTestBase {

  private ?int $listingId = NULL;

  private ?int $listingImageId = NULL;

  private ?int $fileId = NULL;

  private ?int $originalAutomatedCronInterval = NULL;

  protected function setUp(): void {
    parent::setUp();

    $config = \Drupal::configFactory()->getEditable('automated_cron.settings');
    $interval = $config->get('interval');
    $this->originalAutomatedCronInterval = is_numeric($interval) ? (int) $interval : NULL;
    $config->set('interval', 0)->save();

    $user = User::load(1);
    $this->assertNotNull($user, 'User 1 must exist for existing-site DTT tests.');
    $this->drupalLogin($user);
  }

  protected function tearDown(): void {
    try {
      $this->cleanupLivePublication();
      $this->cleanupLocalArtifacts();
    }
    finally {
      if ($this->originalAutomatedCronInterval !== NULL) {
        \Drupal::configFactory()
          ->getEditable('automated_cron.settings')
          ->set('interval', $this->originalAutomatedCronInterval)
          ->save();
      }
    }

    parent::tearDown();
  }

  public function testPublishUnpublishRelistPreservesLifecycleAndShowsHistory(): void {
    $listing = $this->createDisposableListing();
    $publisher = \Drupal::service('drupal.listing_publishing.publisher');
    assert($publisher instanceof ListingPublisher);

    $firstPublish = $publisher->publish($listing);
    $this->assertTrue($firstPublish->isSuccess(), 'First live publish should succeed.');

    $firstPublication = $this->loadPublishedPublication((int) $listing->id());
    $this->assertNotNull($firstPublication, 'First live publication row should exist.');

    $firstLifecycle = $this->loadLifecycleRow((int) $listing->id());
    $this->assertNotNull($firstLifecycle, 'Lifecycle row should exist after first publish.');
    $firstPublishedAt = (int) $firstLifecycle->first_published_at;
    $this->assertGreaterThan(0, $firstPublishedAt);

    $unpublishService = \Drupal::service(MarketplaceUnpublishService::class);
    assert($unpublishService instanceof MarketplaceUnpublishService);
    $unpublishResult = $unpublishService->unpublishPublication((int) $firstPublication->id());
    $this->assertSame('ebay', $unpublishResult->marketplaceKey);
    $this->assertNull($this->loadPublishedPublication((int) $listing->id()));

    $afterUnpublishLifecycle = $this->loadLifecycleRow((int) $listing->id());
    $this->assertNotNull($afterUnpublishLifecycle);
    $this->assertGreaterThan(0, (int) $afterUnpublishLifecycle->last_unpublished_at);

    sleep(2);

    $relist = $publisher->publish($listing);
    $this->assertTrue($relist->isSuccess(), 'Republish should succeed.');

    $secondPublication = $this->loadPublishedPublication((int) $listing->id());
    $this->assertNotNull($secondPublication, 'Published publication row should exist after relist.');

    $secondLifecycle = $this->loadLifecycleRow((int) $listing->id());
    $this->assertNotNull($secondLifecycle);
    $this->assertSame($firstPublishedAt, (int) $secondLifecycle->first_published_at);
    $this->assertSame(1, (int) $secondLifecycle->relist_count);
    $this->assertGreaterThanOrEqual($firstPublishedAt, (int) $secondLifecycle->last_published_at);

    $this->visit('/admin/ai-listings/' . (int) $listing->id());
    $this->getSession()->executeScript("document.getElementById('edit-history')?.setAttribute('open', 'open');");
    $this->assertSession()->elementTextContains('css', '#edit-history', 'Marketplace published');
    $this->assertSession()->elementTextContains('css', '#edit-history', 'Marketplace unpublished');
    $this->assertSession()->elementTextContains('css', '#edit-history', 'Marketplace republished');
  }

  private function createDisposableListing(): BbAiListing {
    $suffix = substr(hash('sha256', (string) microtime(TRUE)), 0, 8);
    $title = 'DTT LIVE RELIST ' . $suffix;

    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'ready_for_review',
      'ebay_title' => $title,
      'listing_code' => 'DTT' . strtoupper(substr($suffix, 0, 4)),
      'price' => '29.95',
      'storage_location' => 'DTT',
      'condition_grade' => 'good',
      'condition_note' => 'DTT disposable lifecycle test listing.',
      'description' => [
        'value' => '<p>DTT disposable lifecycle test listing. Do not buy.</p>',
        'format' => 'basic_html',
      ],
      'field_title' => $title,
      'field_full_title' => $title,
      'field_author' => 'DTT Harness',
      'field_language' => 'English',
      'field_format' => 'Paperback',
      'field_genre' => 'Testing',
      'field_narrative_type' => 'Testing',
      'field_country_printed' => 'Australia',
    ]);
    $listing->save();
    $this->listingId = (int) $listing->id();

    $targetUri = 'public://dtt-live-lifecycle-' . $suffix . '.png';
    $directory = dirname($targetUri);
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    $this->generateCompliantTestImage($targetUri, $title);

    $file = File::create([
      'uri' => $targetUri,
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();
    $this->fileId = (int) $file->id();

    $listingImage = ListingImage::create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => (int) $listing->id(),
      ],
      'file' => (int) $file->id(),
      'is_metadata_source' => TRUE,
      'weight' => 0,
    ]);
    $listingImage->save();
    $this->listingImageId = (int) $listingImage->id();

    return $listing;
  }

  private function generateCompliantTestImage(string $targetUri, string $title): void {
    $realPath = \Drupal::service('file_system')->realpath($targetUri);
    if ($realPath === FALSE || $realPath === '') {
      throw new \RuntimeException('Unable to resolve target image path.');
    }

    if (!function_exists('imagecreatetruecolor')) {
      throw new \RuntimeException('GD extension is required for live marketplace lifecycle test image generation.');
    }

    $image = imagecreatetruecolor(600, 600);
    if ($image === FALSE) {
      throw new \RuntimeException('Unable to allocate test image canvas.');
    }

    $white = imagecolorallocate($image, 255, 255, 255);
    $black = imagecolorallocate($image, 0, 0, 0);
    $blue = imagecolorallocate($image, 27, 78, 168);
    imagefilledrectangle($image, 0, 0, 599, 599, $white);
    imagefilledrectangle($image, 30, 30, 570, 570, $blue);
    imagefilledrectangle($image, 60, 60, 540, 540, $white);
    imagestring($image, 5, 120, 120, 'DTT LIVE TEST', $black);
    imagestring($image, 4, 120, 180, substr($title, 0, 32), $black);
    imagestring($image, 4, 120, 220, 'DO NOT BUY', $black);

    imagepng($image, $realPath);
    imagedestroy($image);
  }

  private function loadPublishedPublication(int $listingId): ?AiMarketplacePublication {
    $ids = \Drupal::entityTypeManager()->getStorage('ai_marketplace_publication')->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingId)
      ->condition('marketplace_key', 'ebay')
      ->condition('status', 'published')
      ->sort('id', 'DESC')
      ->range(0, 1)
      ->execute();

    if ($ids === []) {
      return NULL;
    }

    $publication = \Drupal::entityTypeManager()->getStorage('ai_marketplace_publication')->load((int) reset($ids));
    return $publication instanceof AiMarketplacePublication ? $publication : NULL;
  }

  private function loadLifecycleRow(int $listingId): ?object {
    $row = \Drupal::database()->select('bb_ai_listing_marketplace_lifecycle', 'l')
      ->fields('l')
      ->condition('listing_id', $listingId)
      ->condition('marketplace_key', 'ebay')
      ->execute()
      ->fetchObject();

    return $row !== FALSE ? $row : NULL;
  }

  private function cleanupLivePublication(): void {
    if ($this->listingId === NULL) {
      return;
    }

    $publication = $this->loadPublishedPublication($this->listingId);
    if ($publication === NULL) {
      return;
    }

    try {
      $service = \Drupal::service(MarketplaceUnpublishService::class);
      assert($service instanceof MarketplaceUnpublishService);
      $service->unpublishPublication((int) $publication->id());
    }
    catch (\Throwable $exception) {
      fwrite(STDERR, "Cleanup unpublish failed for listing {$this->listingId}: {$exception->getMessage()}\n");
    }
  }

  private function cleanupLocalArtifacts(): void {
    if ($this->listingImageId !== NULL) {
      $listingImage = ListingImage::load($this->listingImageId);
      if ($listingImage !== NULL) {
        $listingImage->delete();
      }
    }

    if ($this->fileId !== NULL) {
      $file = File::load($this->fileId);
      if ($file !== NULL) {
        $file->delete();
      }
    }

    if ($this->listingId !== NULL) {
      \Drupal::database()->delete('bb_ai_listing_history')
        ->condition('listing_id', $this->listingId)
        ->execute();
      \Drupal::database()->delete('bb_ai_listing_marketplace_lifecycle')
        ->condition('listing_id', $this->listingId)
        ->condition('marketplace_key', 'ebay')
        ->execute();

      $listing = BbAiListing::load($this->listingId);
      if ($listing !== NULL) {
        $listing->delete();
      }
    }
  }

}
