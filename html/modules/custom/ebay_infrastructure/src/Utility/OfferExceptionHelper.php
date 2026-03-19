<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Utility;

use Drupal\ebay_infrastructure\Exception\EbaySellApiException;

final class OfferExceptionHelper {

  private const OFFER_NOT_AVAILABLE_ERROR_ID = 25713;
  private const INVENTORY_ITEM_NOT_FOUND_ERROR_ID = 25710;

  public static function isOfferUnavailable(\Throwable $exception): bool {
    return $exception instanceof EbaySellApiException
      ? $exception->hasErrorId(self::OFFER_NOT_AVAILABLE_ERROR_ID)
      : str_contains($exception->getMessage(), '"errorId":25713');
  }

  public static function isInventoryItemMissing(\Throwable $exception): bool {
    return $exception instanceof EbaySellApiException
      ? $exception->hasErrorId(self::INVENTORY_ITEM_NOT_FOUND_ERROR_ID)
      : str_contains($exception->getMessage(), '"errorId":25710');
  }
}
