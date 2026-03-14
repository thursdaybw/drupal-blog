<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\ai_listing\Controller\AiListingMarketplacesController;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifies the marketplaces tab controller render model.
 */
final class AiListingMarketplacesControllerTest extends KernelTestBase {

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
  }

  public function testBuildRendersMarketplacePublicationDetails(): void {
    $listing = $this->createListing();
    $this->createPublication((int) $listing->id());

    $controller = AiListingMarketplacesController::create($this->container);
    $build = $controller->build($listing);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('Current marketplace publication state for this listing.', $markup);
    $this->assertStringContainsString('ebay', strtolower($markup));
    $this->assertStringContainsString('legacy-ebay-177516641386', $markup);
    $this->assertStringContainsString('177516641386', $markup);
    $this->assertStringContainsString('legacy_adopted', $markup);
    $this->assertStringContainsString('Unpublish', $markup);
  }

  public function testBuildRendersEmptyStateWithoutPublicationRows(): void {
    $listing = $this->createListing();

    $controller = AiListingMarketplacesController::create($this->container);
    $build = $controller->build($listing);
    $markup = (string) $this->container->get('renderer')->renderRoot($build);

    $this->assertStringContainsString('This listing has no marketplace publication records.', $markup);
  }

  private function createListing() {
    $storage = $this->container->get('entity_type.manager')->getStorage('bb_ai_listing');
    $listing = $storage->create([
      'listing_type' => 'book',
      'status' => 'shelved',
      'ebay_title' => 'Marketplace tab test listing',
      'listing_code' => 'MKTTAB01',
    ]);
    $listing->save();

    return $listing;
  }

  private function createPublication(int $listingId): void {
    $this->container->get('entity_type.manager')->getStorage('ai_marketplace_publication')->create([
      'listing' => $listingId,
      'inventory_sku_value' => 'legacy-ebay-177516641386',
      'marketplace_key' => 'ebay',
      'status' => 'published',
      'publication_type' => 'FIXED_PRICE',
      'marketplace_publication_id' => '128228912011',
      'marketplace_listing_id' => '177516641386',
      'source' => 'legacy_adopted',
      'published_at' => 1772807631,
    ])->save();
  }

}
