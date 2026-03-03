<?php

declare(strict_types=1);

namespace Drupal\ebay_connector;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

final class EbayConnectorServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    $container->setAlias('listing_publishing.marketplace_publisher', 'drupal.ebay_connector.marketplace_publisher');
    $container->setAlias('Drupal\\listing_publishing\\Contract\\MarketplacePublisherInterface', 'drupal.ebay_connector.marketplace_publisher');
    $container->setAlias('listing_publishing.image_uploader', 'drupal.ebay_infrastructure.media_image_uploader');
    $container->setAlias('Drupal\\listing_publishing\\Contract\\ListingImageUploaderInterface', 'drupal.ebay_infrastructure.media_image_uploader');
  }

}
