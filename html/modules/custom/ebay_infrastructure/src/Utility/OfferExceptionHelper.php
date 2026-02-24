<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Utility;

final class OfferExceptionHelper {

  private const OFFER_NOT_AVAILABLE_ERROR_ID = '"errorId":25713';

  public static function isOfferUnavailable(\RuntimeException $exception): bool {
    return str_contains($exception->getMessage(), self::OFFER_NOT_AVAILABLE_ERROR_ID);
  }
}
