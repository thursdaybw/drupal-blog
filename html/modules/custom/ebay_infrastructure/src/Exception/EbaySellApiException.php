<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Exception;

/**
 * Typed wrapper for eBay Sell API error responses.
 */
final class EbaySellApiException extends \RuntimeException {

  /**
   * @param int[] $errorIds
   */
  public function __construct(
    string $message,
    public readonly array $errorIds = [],
    public readonly string $rawBody = '',
  ) {
    parent::__construct($message);
  }

  public function hasErrorId(int $errorId): bool {
    return in_array($errorId, $this->errorIds, TRUE);
  }

}
