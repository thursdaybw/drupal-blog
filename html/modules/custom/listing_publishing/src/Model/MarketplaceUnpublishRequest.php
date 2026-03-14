<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Model;

/**
 * Immutable application request for marketplace unpublish operations.
 */
final class MarketplaceUnpublishRequest {

  public function __construct(
    public readonly int $publicationId,
    public readonly string $marketplaceKey,
    public readonly string $sku,
    public readonly string $marketplacePublicationId,
    public readonly string $marketplaceListingId,
  ) {}

}
