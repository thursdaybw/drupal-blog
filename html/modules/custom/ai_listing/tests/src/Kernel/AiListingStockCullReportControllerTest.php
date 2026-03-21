<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_listing\Controller\AiListingStockCullReportController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * Verifies the stock cull report controller render model.
 */
final class AiListingStockCullReportControllerTest extends KernelTestBase {

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
    $this->installSchema('ai_listing', ['bb_ai_listing_marketplace_lifecycle']);
  }

  public function testBuildRendersReportRowsSortedByCullScore(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');

    $listingHighScore = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'High score listing',
      'price' => '5.00',
      'storage_location' => 'A-01',
      'listing_code' => 'CULLHIGH',
    ]);
    $listingHighScore->save();

    $listingLowScore = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Low score listing',
      'price' => '20.00',
      'storage_location' => 'B-02',
      'listing_code' => 'CULLLOW',
    ]);
    $listingLowScore->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $listingHighScore->id(),
      'inventory_sku_value' => 'SKU-CULLHIGH',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000001',
      'source' => 'legacy_adopted',
      'marketplace_started_at' => 1700000000,
    ])->save();
    $publicationStorage->create([
      'listing' => (int) $listingLowScore->id(),
      'inventory_sku_value' => 'SKU-CULLLOW',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000002',
      'source' => 'local_publish',
      'marketplace_started_at' => 1750000000,
    ])->save();

    $controller = AiListingStockCullReportController::create($this->container);
    $build = $controller->build();
    $this->assertSame('High score listing', $build['table']['#rows'][0]['title']['data']['#title']);
    $this->assertSame('Low score listing', $build['table']['#rows'][1]['title']['data']['#title']);
    $this->assertMatchesRegularExpression('/^\d+\.\d{2}$/', $build['table']['#rows'][0]['age_months']);
    $this->assertMatchesRegularExpression('/^\d+\.\d{4}$/', $build['table']['#rows'][0]['cull_score']);
    $this->assertGreaterThan(
      (float) $build['table']['#rows'][1]['cull_score'],
      (float) $build['table']['#rows'][0]['cull_score']
    );

    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('ranked by cull score', $markup);
    $this->assertStringContainsString('High score listing', $markup);
    $this->assertStringContainsString('Low score listing', $markup);
    $this->assertStringContainsString('177000000001', $markup);
    $this->assertStringContainsString('Cull score', $markup);
    $this->assertStringContainsString('A-01', $markup);
    $this->assertStringContainsString('SKU-CULLHIGH', $markup);
    $this->assertStringContainsString('/admin/ai-listings/' . (int) $listingHighScore->id(), $markup);
  }

  public function testBuildFiltersByListingTypeFromRequest(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');

    $bookListing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Book only listing',
      'price' => '8.00',
      'listing_code' => 'BOOKONLY',
    ]);
    $bookListing->save();

    $genericListing = $storage->create([
      'listing_type' => 'generic',
      'status' => 'shelved',
      'ebay_title' => 'Generic only listing',
      'price' => '8.00',
      'listing_code' => 'GENONLY',
    ]);
    $genericListing->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $bookListing->id(),
      'inventory_sku_value' => 'SKU-BOOKONLY',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000010',
      'source' => 'local_publish',
      'published_at' => 1710000000,
    ])->save();
    $publicationStorage->create([
      'listing' => (int) $genericListing->id(),
      'inventory_sku_value' => 'SKU-GENONLY',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000011',
      'source' => 'local_publish',
      'published_at' => 1710000001,
    ])->save();

    $request = Request::create('/admin/ai-listings/reports/stock-cull', 'GET', ['listing_type' => 'book']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = AiListingStockCullReportController::create($this->container);
    $build = $controller->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('Book only listing', $markup);
    $this->assertStringNotContainsString('Generic only listing', $markup);
  }

  public function testDownloadCsvPreservesListingTypeFilter(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');

    $bookListing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'CSV book listing',
      'price' => '8.00',
      'storage_location' => 'CSV-A',
      'listing_code' => 'CSVBOOK',
    ]);
    $bookListing->save();

    $genericListing = $storage->create([
      'listing_type' => 'generic',
      'status' => 'shelved',
      'ebay_title' => 'CSV generic listing',
      'price' => '8.00',
      'storage_location' => 'CSV-B',
      'listing_code' => 'CSVGEN',
    ]);
    $genericListing->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $bookListing->id(),
      'inventory_sku_value' => 'SKU-CSVBOOK',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000020',
      'source' => 'local_publish',
      'published_at' => 1710000000,
    ])->save();
    $publicationStorage->create([
      'listing' => (int) $genericListing->id(),
      'inventory_sku_value' => 'SKU-CSVGEN',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000021',
      'source' => 'local_publish',
      'published_at' => 1710000001,
    ])->save();

    $request = Request::create('/admin/ai-listings/reports/stock-cull.csv', 'GET', ['listing_type' => 'book']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = AiListingStockCullReportController::create($this->container);
    $response = $controller->downloadCsv();
    $content = $response->getContent();

    $this->assertSame('text/csv; charset=UTF-8', $response->headers->get('Content-Type'));
    $this->assertStringContainsString('attachment; filename="ebay-stock-cull-report-book.csv"', (string) $response->headers->get('Content-Disposition'));
    $this->assertStringContainsString('CSV book listing', (string) $content);
    $this->assertStringContainsString('CSV-A', (string) $content);
    $this->assertStringContainsString('SKU-CSVBOOK', (string) $content);
    $this->assertStringNotContainsString('CSV generic listing', (string) $content);
  }

  public function testBuildFiltersByMaxPriceFromRequest(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');

    $cheapListing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Cheap report listing',
      'price' => '9.99',
      'listing_code' => 'CHEAPREP',
    ]);
    $cheapListing->save();

    $expensiveListing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Expensive report listing',
      'price' => '29.99',
      'listing_code' => 'EXPREP',
    ]);
    $expensiveListing->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $cheapListing->id(),
      'inventory_sku_value' => 'SKU-CHEAPREP',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000030',
      'source' => 'local_publish',
      'published_at' => 1710000000,
    ])->save();
    $publicationStorage->create([
      'listing' => (int) $expensiveListing->id(),
      'inventory_sku_value' => 'SKU-EXPREP',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000031',
      'source' => 'local_publish',
      'published_at' => 1710000001,
    ])->save();

    $request = Request::create('/admin/ai-listings/reports/stock-cull', 'GET', ['max_price' => '20']);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = AiListingStockCullReportController::create($this->container);
    $build = $controller->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('Cheap report listing', $markup);
    $this->assertStringNotContainsString('Expensive report listing', $markup);
  }

  public function testBuildShowsFilteredTotalCountAndCutoffFilter(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');

    $olderListing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Older cutoff listing',
      'price' => '9.99',
      'listing_code' => 'OLDCUT',
    ]);
    $olderListing->save();

    $newerListing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Newer cutoff listing',
      'price' => '9.99',
      'listing_code' => 'NEWCUT',
    ]);
    $newerListing->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $olderListing->id(),
      'inventory_sku_value' => 'SKU-OLDCUT',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000040',
      'source' => 'local_publish',
      'published_at' => strtotime('2025-01-10 12:00:00'),
    ])->save();
    $publicationStorage->create([
      'listing' => (int) $newerListing->id(),
      'inventory_sku_value' => 'SKU-NEWCUT',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000041',
      'source' => 'local_publish',
      'published_at' => strtotime('2025-03-10 12:00:00'),
    ])->save();

    $request = Request::create('/admin/ai-listings/reports/stock-cull', 'GET', [
      'listing_type' => 'book',
      'max_price' => '20',
      'listed_before' => '2025-02-01',
    ]);
    $request->setSession(new Session(new MockArraySessionStorage()));
    $this->container->get('request_stack')->push($request);

    $controller = AiListingStockCullReportController::create($this->container);
    $build = $controller->build();
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('Total matching listings: 1', $markup);
    $this->assertStringContainsString('Older cutoff listing', $markup);
    $this->assertStringNotContainsString('Newer cutoff listing', $markup);
  }

}
