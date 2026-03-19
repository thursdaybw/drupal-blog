<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_listing\Report\EbayStockCullReportQuery;

/**
 * Verifies the eBay stock cull report query.
 */
final class EbayStockCullReportQueryTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_marketplace_publication');
  }

  public function testFetchRowsUsesMarketplaceStartedAtFallback(): void {
    $oldest = $this->createListing('Oldest migrated listing', 'book', '9.99');
    $fallback = $this->createListing('Fallback published listing', 'book', '4.99');
    $newest = $this->createListing('Newest listing', 'generic', '1.99');
    $ignored = $this->createListing('Ignored draft listing', 'book', '0.99');

    $this->createPublication((int) $oldest->id(), [
      'status' => 'published',
      'source' => 'legacy_adopted',
      'marketplace_started_at' => 1700000000,
      'published_at' => 1800000000,
      'marketplace_listing_id' => '111',
    ]);
    $this->createPublication((int) $fallback->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'published_at' => 1710000000,
      'marketplace_listing_id' => '222',
    ]);
    $this->createPublication((int) $newest->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'marketplace_started_at' => 1720000000,
      'published_at' => 1725000000,
      'marketplace_listing_id' => '333',
    ]);
    $this->createPublication((int) $ignored->id(), [
      'status' => 'draft',
      'source' => 'local_publish',
      'published_at' => 1600000000,
      'marketplace_listing_id' => '444',
    ]);

    /** @var \Drupal\ai_listing\Report\EbayStockCullReportQuery $query */
    $query = $this->container->get('ai_listing.ebay_stock_cull_report_query');
    $rows = $query->fetchRows();

    $this->assertCount(3, $rows);
    $byId = [];
    foreach ($rows as $row) {
      $byId[$row->listingId] = $row;
    }

    $this->assertSame(1700000000, $byId[(int) $oldest->id()]->effectiveListedAt());
    $this->assertSame(1710000000, $byId[(int) $fallback->id()]->effectiveListedAt());
    $this->assertSame(1720000000, $byId[(int) $newest->id()]->effectiveListedAt());
    $this->assertSame('SKU-' . (int) $oldest->id(), $byId[(int) $oldest->id()]->inventorySku);
  }

  public function testFetchRowsCanFilterByListingType(): void {
    $book = $this->createListing('Book listing', 'book', '9.99');
    $generic = $this->createListing('Generic listing', 'generic', '4.99');

    $this->createPublication((int) $book->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'published_at' => 1710000000,
      'marketplace_listing_id' => '555',
    ]);
    $this->createPublication((int) $generic->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'published_at' => 1711000000,
      'marketplace_listing_id' => '666',
    ]);

    /** @var \Drupal\ai_listing\Report\EbayStockCullReportQuery $query */
    $query = $this->container->get('ai_listing.ebay_stock_cull_report_query');

    $bookRows = $query->fetchRows(250, 'book');
    $this->assertCount(1, $bookRows);
    $this->assertSame((int) $book->id(), $bookRows[0]->listingId);

    $genericRows = $query->fetchRows(250, 'generic');
    $this->assertCount(1, $genericRows);
    $this->assertSame((int) $generic->id(), $genericRows[0]->listingId);
  }

  public function testFetchRowsCanFilterByMaxPrice(): void {
    $cheap = $this->createListing('Cheap listing', 'book', '9.99');
    $expensive = $this->createListing('Expensive listing', 'book', '25.00');

    $this->createPublication((int) $cheap->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'published_at' => 1710000000,
      'marketplace_listing_id' => '777',
    ]);
    $this->createPublication((int) $expensive->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'published_at' => 1711000000,
      'marketplace_listing_id' => '888',
    ]);

    /** @var \Drupal\ai_listing\Report\EbayStockCullReportQuery $query */
    $query = $this->container->get('ai_listing.ebay_stock_cull_report_query');

    $rows = $query->fetchRows(250, NULL, 20.00);
    $this->assertCount(1, $rows);
    $this->assertSame((int) $cheap->id(), $rows[0]->listingId);
  }

  public function testCountRowsAndCutoffDateFilter(): void {
    $older = $this->createListing('Older listing', 'book', '9.99');
    $newer = $this->createListing('Newer listing', 'book', '9.99');

    $this->createPublication((int) $older->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'published_at' => strtotime('2025-01-10 12:00:00'),
      'marketplace_listing_id' => '901',
    ]);
    $this->createPublication((int) $newer->id(), [
      'status' => 'published',
      'source' => 'local_publish',
      'published_at' => strtotime('2025-03-10 12:00:00'),
      'marketplace_listing_id' => '902',
    ]);

    /** @var \Drupal\ai_listing\Report\EbayStockCullReportQuery $query */
    $query = $this->container->get('ai_listing.ebay_stock_cull_report_query');

    $count = $query->countRows('book', 20.00, strtotime('2025-02-01 23:59:59'));
    $this->assertSame(1, $count);

    $rows = $query->fetchRows(250, 'book', 20.00, strtotime('2025-02-01 23:59:59'));
    $this->assertCount(1, $rows);
    $this->assertSame((int) $older->id(), $rows[0]->listingId);
  }

  private function createListing(string $title, string $type, string $price) {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');
    $listing = $storage->create([
      'listing_type' => $type,
      'status' => 'shelved',
      'ebay_title' => $title,
      'price' => $price,
      'storage_location' => 'LOC-' . strtoupper(substr(md5($title), 0, 4)),
      'listing_code' => strtoupper(substr(md5($title), 0, 8)),
    ]);
    $listing->save();
    return $listing;
  }

  /**
   * @param array<string,mixed> $values
   */
  private function createPublication(int $listingId, array $values): void {
    $defaults = [
      'listing' => $listingId,
      'inventory_sku_value' => 'SKU-' . $listingId,
      'marketplace_key' => 'ebay',
      'publication_type' => 'FIXED_PRICE',
    ];

    $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication')->create($defaults + $values)->save();
  }

}
