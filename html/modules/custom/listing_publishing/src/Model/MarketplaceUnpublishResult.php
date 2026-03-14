<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Model;

/**
 * Outcome of a marketplace unpublish operation.
 */
final class MarketplaceUnpublishResult {

  public function __construct(
    public readonly int $publicationId,
    public readonly string $marketplaceKey,
    public readonly string $sku,
    public readonly int $deletedOfferCount,
  ) {}

}
