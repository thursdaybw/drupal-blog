<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\ebay_infrastructure\Exception\EbayInventoryItemMissingException;
use Drupal\ebay_infrastructure\Utility\OfferExceptionHelper;

/**
 * Removes the current eBay Sell inventory/offer state for a SKU.
 */
final class EbaySkuRemovalService {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly ?EbayAccountManager $accountManager,
    private readonly ?EbayInventoryMirrorSyncService $inventoryMirrorSyncService,
    private readonly ?EbayOfferMirrorSyncService $offerMirrorSyncService,
  ) {}

  public function removeSku(string $sku): int {
    $offers = [];

    try {
      $offers = $this->sellApiClient->listOffersBySku($sku);
    }
    catch (\RuntimeException $exception) {
      if (!OfferExceptionHelper::isOfferUnavailable($exception)) {
        throw $exception;
      }
      $offers = ['offers' => []];
    }

    $deletedOfferCount = 0;
    foreach ($offers['offers'] ?? [] as $offer) {
      $offerId = $offer['offerId'] ?? NULL;
      if ($offerId === NULL || $offerId === '') {
        continue;
      }

      try {
        $this->sellApiClient->deleteOffer((string) $offerId);
        $deletedOfferCount++;
      }
      catch (\RuntimeException $exception) {
        if (OfferExceptionHelper::isOfferUnavailable($exception)) {
          continue;
        }
        throw $exception;
      }
    }

    $inventoryItemMissing = FALSE;
    try {
      $this->sellApiClient->deleteInventoryItem($sku);
    }
    catch (\RuntimeException $exception) {
      if (!OfferExceptionHelper::isInventoryItemMissing($exception)) {
        throw $exception;
      }
      $inventoryItemMissing = TRUE;
    }

    $this->deleteMirrorRowsForSku($sku);

    if ($inventoryItemMissing) {
      throw new EbayInventoryItemMissingException(sprintf('Inventory item already missing for SKU %s.', $sku));
    }

    return $deletedOfferCount;
  }

  private function deleteMirrorRowsForSku(string $sku): void {
    if ($this->accountManager === NULL || $this->inventoryMirrorSyncService === NULL || $this->offerMirrorSyncService === NULL) {
      return;
    }

    $accountId = (int) $this->accountManager->loadPrimaryAccount()->id();
    $this->offerMirrorSyncService->deleteSku($accountId, $sku);
    $this->inventoryMirrorSyncService->deleteSku($accountId, $sku);
  }

}
