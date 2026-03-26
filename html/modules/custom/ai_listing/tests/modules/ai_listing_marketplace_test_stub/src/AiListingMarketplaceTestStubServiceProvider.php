<?php

declare(strict_types=1);

namespace Drupal\ai_listing_marketplace_test_stub;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\ai_listing_marketplace_test_stub\Service\StateBackedMarketplacePublisher;
use Symfony\Component\DependencyInjection\Reference;

final class AiListingMarketplaceTestStubServiceProvider extends ServiceProviderBase {

  public function alter(ContainerBuilder $container): void {
    if ($container->hasDefinition('listing_publishing.marketplace_publisher')) {
      $definition = $container->getDefinition('listing_publishing.marketplace_publisher');
      $definition->setClass(StateBackedMarketplacePublisher::class);
      $definition->setArguments([new Reference('state')]);
    }

    if ($container->hasAlias('Drupal\\listing_publishing\\Contract\\MarketplacePublisherInterface')) {
      $container->setAlias('Drupal\\listing_publishing\\Contract\\MarketplacePublisherInterface', 'listing_publishing.marketplace_publisher');
    }
  }

}
