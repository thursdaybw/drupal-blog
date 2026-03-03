<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_mirror\Service;

use Drupal\Core\Database\Connection;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\SellApiClient;

final class EbayOfferMirrorSyncService {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly Connection $database,
  ) {}

  /**
   * Sync all eBay offers for the mirrored inventory SKUs of one account.
   *
   * @return array{skus:int,offers_seen:int,offers_upserted:int}
   */
  public function syncAll(EbayAccount $account): array {
    $accountId = (int) $account->id();
    $skus = $this->loadMirroredSkus($accountId);
    $offersSeen = 0;
    $offersUpserted = 0;
    $seenOfferIds = [];

    foreach ($skus as $sku) {
      $response = $this->sellApiClient->listOffersBySkuForAccount($account, $sku);
      $offers = $response['offers'] ?? [];

      if (!is_array($offers) || $offers === []) {
        continue;
      }

      foreach ($offers as $offer) {
        if (!is_array($offer)) {
          continue;
        }

        $this->upsertOffer($accountId, $sku, $offer);
        $offerId = trim((string) ($offer['offerId'] ?? ''));
        if ($offerId !== '') {
          $seenOfferIds[$offerId] = TRUE;
        }
        $offersSeen++;
        $offersUpserted++;
      }
    }

    $this->deleteOffersNotSeenInRun($accountId, array_keys($seenOfferIds));

    return [
      'skus' => count($skus),
      'offers_seen' => $offersSeen,
      'offers_upserted' => $offersUpserted,
    ];
  }

  /**
   * @return string[]
   */
  private function loadMirroredSkus(int $accountId): array {
    $result = $this->database->select('bb_ebay_inventory_item', 'i')
      ->fields('i', ['sku'])
      ->condition('account_id', $accountId)
      ->orderBy('sku')
      ->execute()
      ->fetchCol();

    return array_values(array_filter($result, static fn ($sku): bool => is_string($sku) && $sku !== ''));
  }

  private function upsertOffer(int $accountId, string $fallbackSku, array $offer): void {
    $offerId = trim((string) ($offer['offerId'] ?? ''));
    if ($offerId === '') {
      return;
    }

    $pricingSummary = $offer['pricingSummary'] ?? [];
    $price = $pricingSummary['price'] ?? [];
    $listingPolicies = $offer['listingPolicies'] ?? [];
    $listing = $offer['listing'] ?? [];

    $record = [
      'account_id' => $accountId,
      'offer_id' => $offerId,
      'sku' => $this->normalizeNullableString($offer['sku'] ?? NULL) ?? $fallbackSku,
      'marketplace_id' => $this->normalizeNullableString($offer['marketplaceId'] ?? NULL),
      'format' => $this->normalizeNullableString($offer['format'] ?? NULL),
      'listing_description' => $this->normalizeNullableString($offer['listingDescription'] ?? NULL),
      'available_quantity' => $this->normalizeNullableInt($offer['availableQuantity'] ?? NULL),
      'price_value' => $this->normalizeNullableString($price['value'] ?? NULL),
      'price_currency' => $this->normalizeNullableString($price['currency'] ?? NULL),
      'payment_policy_id' => $this->normalizeNullableString($listingPolicies['paymentPolicyId'] ?? NULL),
      'return_policy_id' => $this->normalizeNullableString($listingPolicies['returnPolicyId'] ?? NULL),
      'fulfillment_policy_id' => $this->normalizeNullableString($listingPolicies['fulfillmentPolicyId'] ?? NULL),
      'ebay_plus_if_eligible' => $this->normalizeNullableBoolInt($listingPolicies['eBayPlusIfEligible'] ?? NULL),
      'category_id' => $this->normalizeNullableString($offer['categoryId'] ?? NULL),
      'merchant_location_key' => $this->normalizeNullableString($offer['merchantLocationKey'] ?? NULL),
      'apply_tax' => $this->normalizeNullableBoolInt($offer['tax']['applyTax'] ?? NULL),
      'listing_id' => $this->normalizeNullableString($listing['listingId'] ?? NULL),
      'listing_status' => $this->normalizeNullableString($listing['listingStatus'] ?? NULL),
      'sold_quantity' => $this->normalizeNullableInt($listing['soldQuantity'] ?? NULL),
      'status' => $this->normalizeNullableString($offer['status'] ?? NULL),
      'listing_duration' => $this->normalizeNullableString($offer['listingDuration'] ?? NULL),
      'include_catalog_product_details' => $this->normalizeNullableBoolInt($offer['includeCatalogProductDetails'] ?? NULL),
      'hide_buyer_details' => $this->normalizeNullableBoolInt($offer['hideBuyerDetails'] ?? NULL),
      'raw_json' => $this->encodeJsonOrNull($offer),
      'last_seen' => time(),
    ];

    $this->database->merge('bb_ebay_offer')
      ->key([
        'account_id' => $accountId,
        'offer_id' => $offerId,
      ])
      ->fields($record)
      ->execute();
  }

  /**
   * Remove mirrored offer rows for this account that eBay did not return.
   *
   * @param string[] $seenOfferIds
   *   The offer IDs returned by eBay in the current sync run.
   */
  private function deleteOffersNotSeenInRun(int $accountId, array $seenOfferIds): void {
    $delete = $this->database->delete('bb_ebay_offer')
      ->condition('account_id', $accountId);

    if ($seenOfferIds !== []) {
      $delete->condition('offer_id', $seenOfferIds, 'NOT IN');
    }

    $delete->execute();
  }

  private function normalizeNullableString(mixed $value): ?string {
    if (!is_scalar($value)) {
      return NULL;
    }

    $normalizedValue = trim((string) $value);
    return $normalizedValue === '' ? NULL : $normalizedValue;
  }

  private function normalizeNullableInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    return (int) $value;
  }

  private function normalizeNullableBoolInt(mixed $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    return $value ? 1 : 0;
  }

  private function encodeJsonOrNull(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: NULL;
  }

}
