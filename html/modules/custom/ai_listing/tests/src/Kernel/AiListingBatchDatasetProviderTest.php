<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Model\AiListingBatchFilter;
use Drupal\ai_listing\Service\AiListingBatchDatasetProvider;
use Drupal\KernelTests\KernelTestBase;

/**
 * Proves the batch dataset service returns the right rows and counts.
 *
 * What the batch dataset is:
 * The batch dataset is the prepared data for the batch listing screen.
 * It answers questions like:
 * - how many listings exist in total?
 * - how many match the current filters?
 * - which rows belong on this page?
 * - which storage locations should appear in the filter dropdown?
 *
 * Why this exists:
 * AiBookListingLocationBatchForm used to do all of that work itself. That made
 * the form too busy and too hard to test. We pulled the data work into a
 * dedicated service so AiBookListingLocationBatchForm can focus on wiring and
 * rendering.
 *
 * What this test is for:
 * This test checks the service that builds that prepared data. We are not
 * testing HTML here. We are testing the real Drupal-backed data that the form
 * will later turn into a table.
 *
 * Why this is a kernel test:
 * The service reads real Drupal entities, entity queries, and related records
 * like SKU rows. That means we need a real Drupal kernel, not a plain unit
 * test with mocks.
 */
final class AiListingBatchDatasetProviderTest extends KernelTestBase {

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

  private ?AiListingBatchDatasetProvider $datasetProvider = NULL;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_listing_inventory_sku');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installConfig(['ai_listing']);

    $this->datasetProvider = $this->container->get('ai_listing.batch_dataset_provider');
  }

  /**
   * Checks status filtering and paging with real listings.
   *
   * We create two shelved listings and one failed listing. Then we ask for:
   * - only shelved rows
   * - one row per page
   * - page 2
   *
   * That should give us only the second shelved row.
   */
  public function testDatasetFiltersByStatusAndPaginatesRows(): void {
    $firstShelved = $this->createBookListing('First shelved', 'shelved', 'BDMAA01', 100);
    $secondShelved = $this->createBookListing('Second shelved', 'shelved', 'BDMAA02', 200);
    $this->createBookListing('Failed listing', 'failed', 'BGNBIN008', 300);

    $filter = new AiListingBatchFilter(
      status: 'shelved',
      bargainBinFilterMode: 'any',
      publishedToEbayFilterMode: 'any',
      searchQuery: '',
      storageLocationFilter: '',
      itemsPerPage: 1,
      currentPage: 1,
    );

    $dataset = $this->datasetProvider->buildDataset($filter);

    $this->assertSame(3, $dataset->totalCount);
    $this->assertSame(2, $dataset->filteredCount);
    $this->assertSame(1, $dataset->currentPage);
    $this->assertCount(1, $dataset->pagedRows);

    $pagedRows = $dataset->pagedRows;
    $row = reset($pagedRows);
    $this->assertIsArray($row);
    $this->assertSame((int) $secondShelved->id(), $row['listing_id']);
    $this->assertNotSame((int) $firstShelved->id(), $row['listing_id']);
  }

  /**
   * Checks storage location filtering and free-text SKU search.
   *
   * This proves two things:
   * - the dedicated storage location filter works
   * - the broad search also finds a listing by its SKU
   */
  public function testDatasetFiltersByStorageLocationAndSearchableSku(): void {
    $firstListing = $this->createBookListing('Storage one', 'shelved', 'BDMAA01', 100);
    $secondListing = $this->createBookListing('Storage two', 'shelved', 'BDMAA02', 200);
    $this->createActiveSku((int) $secondListing->id(), '2026 Feb BDMAA02 ai-book-2');

    $locationFilter = new AiListingBatchFilter(
      status: 'any',
      bargainBinFilterMode: 'any',
      publishedToEbayFilterMode: 'any',
      searchQuery: '',
      storageLocationFilter: 'BDMAA02',
      itemsPerPage: 50,
      currentPage: 0,
    );

    $locationDataset = $this->datasetProvider->buildDataset($locationFilter);

    $this->assertSame(2, $locationDataset->totalCount);
    $this->assertSame(1, $locationDataset->filteredCount);
    $locationRows = $locationDataset->pagedRows;
    $locationRow = reset($locationRows);
    $this->assertIsArray($locationRow);
    $this->assertSame((int) $secondListing->id(), $locationRow['listing_id']);

    $searchFilter = new AiListingBatchFilter(
      status: 'any',
      bargainBinFilterMode: 'any',
      publishedToEbayFilterMode: 'any',
      searchQuery: 'BDMAA02 ai-book-2',
      storageLocationFilter: '',
      itemsPerPage: 50,
      currentPage: 0,
    );

    $searchDataset = $this->datasetProvider->buildDataset($searchFilter);

    $this->assertSame(1, $searchDataset->filteredCount);
    $searchRows = $searchDataset->pagedRows;
    $searchRow = reset($searchRows);
    $this->assertIsArray($searchRow);
    $this->assertSame((int) $secondListing->id(), $searchRow['listing_id']);
    $this->assertNotSame((int) $firstListing->id(), $searchRow['listing_id']);
  }

  /**
   * Creates a small real book listing for the test dataset.
   *
   * We keep this helper small so the test setup stays easy to read.
   */
  private function createBookListing(string $ebayTitle, string $status, string $storageLocation, int $created): BbAiListing {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'ebay_title' => $ebayTitle,
      'status' => $status,
      'storage_location' => $storageLocation,
      'condition_grade' => 'good',
      'created' => $created,
      'changed' => $created,
    ]);
    $listing->set('description', 'Description for ' . $ebayTitle);
    $listing->save();

    return $listing;
  }

  /**
   * Adds an active SKU row to a listing.
   *
   * The dataset service includes SKU in the broad search text, so this helper
   * lets the test prove that part of the search behaviour.
   */
  private function createActiveSku(int $listingId, string $sku): void {
    $this->container->get('entity_type.manager')->getStorage('ai_listing_inventory_sku')->create([
      'listing' => $listingId,
      'sku' => $sku,
      'status' => 'active',
    ])->save();
  }

}
