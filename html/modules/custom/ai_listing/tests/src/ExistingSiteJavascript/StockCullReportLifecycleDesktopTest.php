<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\ExistingSiteJavascript;

use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\user\Entity\User;
use thursdaybw\DttMultiDeviceTestBase\DesktopTestBase;

final class StockCullReportLifecycleDesktopTest extends DesktopTestBase {

  private ?int $listingId = NULL;

  private ?int $publicationId = NULL;

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
    $database = \Drupal::database();

    if ($this->originalAutomatedCronInterval !== NULL) {
      \Drupal::configFactory()
        ->getEditable('automated_cron.settings')
        ->set('interval', $this->originalAutomatedCronInterval)
        ->save();
    }

    if ($this->publicationId !== NULL) {
      $publication = AiMarketplacePublication::load($this->publicationId);
      if ($publication !== NULL) {
        $publication->delete();
      }
    }

    if ($this->listingId !== NULL) {
      $database->delete('bb_ai_listing_marketplace_lifecycle')
        ->condition('listing_id', $this->listingId)
        ->condition('marketplace_key', 'ebay')
        ->execute();

      $listing = BbAiListing::load($this->listingId);
      if ($listing !== NULL) {
        $listing->delete();
      }
    }

    parent::tearDown();
  }

  public function testStockCullReportUsesOriginalLifecycleDateAfterRelist(): void {
    $time = \Drupal::time()->getRequestTime();
    $firstPublishedAt = $time - (400 * 86400);
    $relistedAt = $time - (3 * 86400);
    $title = 'DTT lifecycle relist smoke ' . substr(hash('sha256', (string) microtime(TRUE)), 0, 8);

    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => $title,
      'price' => '0.13',
      'storage_location' => 'DTT-QA',
      'condition_grade' => 'good',
      'bargain_bin' => FALSE,
    ]);
    $listing->save();
    $this->listingId = (int) $listing->id();

    $publication = AiMarketplacePublication::create([
      'listing' => $this->listingId,
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_publication_id' => 'dtt-pub-' . $this->listingId,
      'marketplace_listing_id' => 'dtt-listing-relisted-' . $this->listingId,
      'inventory_sku_value' => 'dtt-sku-' . $this->listingId,
      'published_at' => $relistedAt,
      'marketplace_started_at' => $relistedAt,
      'source' => 'local_publish',
    ]);
    $publication->save();
    $this->publicationId = (int) $publication->id();

    \Drupal::database()->insert('bb_ai_listing_marketplace_lifecycle')
      ->fields([
        'listing_id' => $this->listingId,
        'marketplace_key' => 'ebay',
        'first_published_at' => $firstPublishedAt,
        'last_published_at' => $relistedAt,
        'last_unpublished_at' => $relistedAt - 60,
        'last_marketplace_listing_id' => 'dtt-listing-relisted-' . $this->listingId,
        'relist_count' => 1,
        'created_at' => $time,
        'changed_at' => $time,
      ])
      ->execute();

    $expectedFirstPublished = \Drupal::service('date.formatter')->format($firstPublishedAt, 'custom', 'Y-m-d H:i');
    $unexpectedRelistedAt = \Drupal::service('date.formatter')->format($relistedAt, 'custom', 'Y-m-d H:i');

    $this->visit('/admin/ai-listings/reports/stock-cull?listing_type=book&max_price=0.13&listed_before=' . date('Y-m-d', $time));
    $this->assertSession()->pageTextContains($title);

    $row = $this->getSession()->getPage()->find('xpath', sprintf('//tr[td//a[normalize-space()="%s"]]', $title));
    $this->assertNotNull($row, 'Expected stock cull row was rendered.');

    $rowText = $row->getText();
    $this->assertStringContainsString($expectedFirstPublished, $rowText);
    $this->assertStringNotContainsString($unexpectedRelistedAt, $rowText);
    $this->assertStringContainsString('400', $rowText);
  }

}
