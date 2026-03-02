<?php

declare(strict_types=1);

namespace Drupal\Tests\listing_publishing\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Service\NullListingImageUploader;
use Drupal\listing_publishing\Service\NullMarketplacePublisher;

/**
 * Proves the generic publishing module can boot without eBay modules.
 *
 * Why this matters:
 * `listing_publishing` is meant to be the generic publishing layer. It should
 * not quietly require eBay just to exist. When no marketplace module is
 * enabled, it should still boot and provide safe default services.
 *
 * This test checks that boundary. It makes sure the service container gives us
 * the null adapters from `listing_publishing`, not eBay-specific services.
 */
final class ListingPublishingBoundaryTest extends KernelTestBase {

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

  public function testGenericPublishingModuleProvidesNullAdaptersByDefault(): void {
    $marketplacePublisher = $this->container->get(MarketplacePublisherInterface::class);
    $imageUploader = $this->container->get(ListingImageUploaderInterface::class);

    $this->assertInstanceOf(NullMarketplacePublisher::class, $marketplacePublisher);
    $this->assertInstanceOf(NullListingImageUploader::class, $imageUploader);
  }

}
