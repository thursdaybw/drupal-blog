<?php

declare(strict_types=1);

namespace Drupal\Tests\listing_publishing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_listing\Service\MarketplaceLifecycleRecorder;
use Drupal\listing_publishing\Contract\MarketplaceUnpublisherInterface;
use Drupal\listing_publishing\Exception\MarketplaceAlreadyUnpublishedException;
use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;
use Drupal\listing_publishing\Service\MarketplaceUnpublishService;

/**
 * Verifies marketplace takedown use-case behavior.
 */
final class MarketplaceUnpublishServiceTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installSchema('ai_listing', ['bb_ai_listing_marketplace_lifecycle']);
  }

  public function testUnpublishPublicationDeletesLocalPublicationAfterAdapterSuccess(): void {
    $listing = $this->createListing();
    $publication = $this->createPublication((int) $listing->id(), 'ebay', 'legacy-ebay-177516641386');
    $adapter = new TestMarketplaceUnpublisher();

    $service = new MarketplaceUnpublishService(
      $this->container->get('entity_type.manager'),
      [$adapter],
      new MarketplaceLifecycleRecorder(
        $this->container->get('database'),
        $this->container->get('datetime.time'),
      ),
    );

    $result = $service->unpublishPublication((int) $publication->id());

    $this->assertSame('ebay', $result->marketplaceKey);
    $this->assertSame('legacy-ebay-177516641386', $result->sku);
    $this->assertSame(1, $result->deletedOfferCount);
    $this->assertCount(1, $adapter->requests);
    $this->assertSame('legacy-ebay-177516641386', $adapter->requests[0]->sku);
    $this->assertNull(
      $this->container->get('entity_type.manager')
        ->getStorage('ai_marketplace_publication')
        ->load((int) $publication->id())
    );
  }

  public function testUnpublishPublicationFailsWhenNoAdapterSupportsMarketplace(): void {
    $listing = $this->createListing();
    $publication = $this->createPublication((int) $listing->id(), 'mercari', 'mercari-sku-1');

    $service = new MarketplaceUnpublishService(
      $this->container->get('entity_type.manager'),
      [new TestMarketplaceUnpublisher()],
      new MarketplaceLifecycleRecorder(
        $this->container->get('database'),
        $this->container->get('datetime.time'),
      ),
    );

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('No marketplace unpublisher is registered for "mercari".');
    $service->unpublishPublication((int) $publication->id());
  }

  public function testUnpublishPublicationTreatsAlreadyUnpublishedAsSuccess(): void {
    $listing = $this->createListing();
    $publication = $this->createPublication((int) $listing->id(), 'ebay', 'gone-sku-1');

    $service = new MarketplaceUnpublishService(
      $this->container->get('entity_type.manager'),
      [new class implements MarketplaceUnpublisherInterface {
        public function supports(string $marketplaceKey): bool {
          return trim(strtolower($marketplaceKey)) === 'ebay';
        }

        public function unpublish(MarketplaceUnpublishRequest $request): int {
          throw new MarketplaceAlreadyUnpublishedException($request, 'Already gone.');
        }
      }],
      new MarketplaceLifecycleRecorder(
        $this->container->get('database'),
        $this->container->get('datetime.time'),
      ),
    );

    $result = $service->unpublishPublication((int) $publication->id());

    $this->assertTrue($result->alreadyUnpublished);
    $this->assertSame(0, $result->deletedOfferCount);
    $this->assertNull(
      $this->container->get('entity_type.manager')
        ->getStorage('ai_marketplace_publication')
        ->load((int) $publication->id())
    );
    $lifecycle = $this->container->get('database')
      ->select('bb_ai_listing_marketplace_lifecycle', 'l')
      ->fields('l')
      ->condition('listing_id', (int) $listing->id())
      ->condition('marketplace_key', 'ebay')
      ->execute()
      ->fetchObject();
    $this->assertNotFalse($lifecycle);
    $this->assertGreaterThan(0, (int) $lifecycle->last_unpublished_at);
  }

  private function createListing() {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');
    $listing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Marketplace unpublish service test listing',
      'listing_code' => 'UNPUBTST',
    ]);
    $listing->save();

    return $listing;
  }

  private function createPublication(int $listingId, string $marketplaceKey, string $sku) {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication');
    $publication = $storage->create([
      'listing' => $listingId,
      'inventory_sku_value' => $sku,
      'marketplace_key' => $marketplaceKey,
      'status' => 'published',
      'marketplace_publication_id' => 'publication-1',
      'marketplace_listing_id' => 'listing-1',
      'source' => 'local_publish',
    ]);
    $publication->save();

    return $publication;
  }

}

final class TestMarketplaceUnpublisher implements MarketplaceUnpublisherInterface {

  /**
   * @var array<int,\Drupal\listing_publishing\Model\MarketplaceUnpublishRequest>
   */
  public array $requests = [];

  public function supports(string $marketplaceKey): bool {
    return trim(strtolower($marketplaceKey)) === 'ebay';
  }

  public function unpublish(MarketplaceUnpublishRequest $request): int {
    $this->requests[] = $request;
    return 1;
  }

}
