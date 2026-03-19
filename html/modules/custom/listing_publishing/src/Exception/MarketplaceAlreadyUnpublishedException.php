<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Exception;

use Drupal\listing_publishing\Model\MarketplaceUnpublishRequest;

/**
 * Indicates the remote marketplace resource is already gone.
 */
final class MarketplaceAlreadyUnpublishedException extends \RuntimeException {

  public function __construct(
    public readonly MarketplaceUnpublishRequest $request,
    string $message = 'Marketplace resource is already unpublished.',
  ) {
    parent::__construct($message);
  }

}
