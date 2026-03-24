<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Service\ListingCullService;
use Drupal\ai_listing\Service\ListingHistoryQuery;
use Drupal\ai_listing\Service\ListingHistoryRecorder;
use Drupal\Tests\ai_listing\Traits\InstallsBbAiListingKernelSchemaTrait;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface;
use Drupal\listing_publishing\Exception\MarketplaceAlreadyUnpublishedException;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;

/**
 * Verifies stacked cull behavior and history recording.
 */
final class ListingCullServiceTest extends KernelTestBase {

  use InstallsBbAiListingKernelSchemaTrait;

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'taxonomy',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installBbAiListingKernelSchema();
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installSchema('ai_listing', ['bb_ai_listing_history']);
  }

  public function testCullUnpublishesArchivesAndRecordsHistory(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Cull candidate',
      'price' => '9.99',
      'listing_code' => 'CULLTEST',
    ]);
    $listing->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $listing->id(),
      'inventory_sku_value' => 'SKU-1',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000101',
      'source' => 'local_publish',
      'published_at' => 1710000000,
    ])->save();
    $publicationStorage->create([
      'listing' => (int) $listing->id(),
      'inventory_sku_value' => 'SKU-2',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000102',
      'source' => 'local_publish',
      'published_at' => 1710000001,
    ])->save();

    $cullService = new ListingCullService(
      $this->container->get('entity_type.manager'),
      [new class implements MarketplaceUnpublisherInterface {
        public function supports(string $marketplaceKey): bool {
          return $marketplaceKey === 'ebay';
        }

        public function unpublish(MarketplaceUnpublishRequest $request): int {
          return 1;
        }
      }],
      new ListingHistoryRecorder(
        $this->container->get('database'),
        $this->container->get('datetime.time'),
        $this->container->get('current_user'),
      ),
    );

    $result = $cullService->cull($listing, 'stale_low_value', 'Picked from shelf.');

    $this->assertSame(2, $result->unpublishedCount);
    $this->assertSame(['ebay'], $result->marketplaces);

    $reloadedListing = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing')->load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloadedListing);
    $this->assertSame('archived', (string) $reloadedListing->get('status')->value);

    $remainingPublicationIds = $publicationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', (int) $listing->id())
      ->execute();
    $this->assertSame([], $remainingPublicationIds);

    $historyEntries = (new ListingHistoryQuery($this->container->get('database')))
      ->fetchByListingId((int) $listing->id(), 10);

    $this->assertCount(4, $historyEntries);
    $this->assertSame('culled', $historyEntries[0]->eventType);
    $this->assertSame('stale_low_value', $historyEntries[0]->reasonCode);
    $this->assertStringContainsString('Picked from shelf.', $historyEntries[0]->message);
    $this->assertSame('listing_archived', $historyEntries[1]->eventType);
    $this->assertSame('marketplace_unpublished', $historyEntries[2]->eventType);
    $this->assertSame('marketplace_unpublished', $historyEntries[3]->eventType);
  }

  public function testCullCanMarkLostAndRecordsLostHistory(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Lost candidate',
      'price' => '9.99',
      'listing_code' => 'LOSTTEST',
    ]);
    $listing->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $listing->id(),
      'inventory_sku_value' => 'SKU-LOST',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000201',
      'source' => 'local_publish',
      'published_at' => 1710001000,
    ])->save();

    $cullService = new ListingCullService(
      $this->container->get('entity_type.manager'),
      [new class implements MarketplaceUnpublisherInterface {
        public function supports(string $marketplaceKey): bool {
          return $marketplaceKey === 'ebay';
        }

        public function unpublish(MarketplaceUnpublishRequest $request): int {
          return 1;
        }
      }],
      new ListingHistoryRecorder(
        $this->container->get('database'),
        $this->container->get('datetime.time'),
        $this->container->get('current_user'),
      ),
    );

    $result = $cullService->cull($listing, 'not_found_on_shelf', 'Shelf checked twice.', ListingCullService::TARGET_LOST);

    $this->assertSame(1, $result->unpublishedCount);
    $reloadedListing = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing')->load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloadedListing);
    $this->assertSame('lost', (string) $reloadedListing->get('status')->value);

    $historyEntries = (new ListingHistoryQuery($this->container->get('database')))
      ->fetchByListingId((int) $listing->id(), 10);

    $this->assertSame('culled', $historyEntries[0]->eventType);
    $this->assertStringContainsString('marked listing lost', $historyEntries[0]->message);
    $this->assertSame('listing_lost', $historyEntries[1]->eventType);
    $this->assertSame('not_found_on_shelf', $historyEntries[1]->reasonCode);
  }

  public function testCullContinuesWhenMarketplaceAlreadyMissing(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Already gone candidate',
      'price' => '9.99',
      'listing_code' => 'GONETEST',
    ]);
    $listing->save();

    $publicationStorage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publicationStorage->create([
      'listing' => (int) $listing->id(),
      'inventory_sku_value' => 'SKU-GONE',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_listing_id' => '177000000301',
      'source' => 'local_publish',
      'published_at' => 1710002000,
    ])->save();

    $cullService = new ListingCullService(
      $this->container->get('entity_type.manager'),
      [new class implements MarketplaceUnpublisherInterface {
        public function supports(string $marketplaceKey): bool {
          return $marketplaceKey === 'ebay';
        }

        public function unpublish(MarketplaceUnpublishRequest $request): int {
          throw new MarketplaceAlreadyUnpublishedException($request, 'Already missing on eBay.');
        }
      }],
      new ListingHistoryRecorder(
        $this->container->get('database'),
        $this->container->get('datetime.time'),
        $this->container->get('current_user'),
      ),
    );

    $result = $cullService->cull($listing, 'stale_low_value', 'Remote listing already gone.');

    $this->assertSame(0, $result->unpublishedCount);
    $reloadedListing = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing')->load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloadedListing);
    $this->assertSame('archived', (string) $reloadedListing->get('status')->value);

    $remainingPublicationIds = $publicationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', (int) $listing->id())
      ->execute();
    $this->assertSame([], $remainingPublicationIds);

    $historyEntries = (new ListingHistoryQuery($this->container->get('database')))
      ->fetchByListingId((int) $listing->id(), 10);

    $this->assertSame('culled', $historyEntries[0]->eventType);
    $this->assertStringContainsString('Remote listing already gone.', $historyEntries[0]->message);
    $this->assertSame('listing_archived', $historyEntries[1]->eventType);
    $this->assertSame('marketplace_already_unpublished', $historyEntries[2]->eventType);
    $this->assertStringContainsString('Removed local publication record.', $historyEntries[2]->message);
  }

}
