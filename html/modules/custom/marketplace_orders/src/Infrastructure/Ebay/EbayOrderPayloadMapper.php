<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Infrastructure\Ebay;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\marketplace_orders\Model\MarketplaceOrderLineSnapshot;
use Drupal\marketplace_orders\Model\MarketplaceOrderSnapshot;

/**
 * Maps eBay fulfillment payloads to marketplace order snapshots.
 */
final class EbayOrderPayloadMapper {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * @param array<string, mixed> $payload
   */
  public function mapPayload(array $payload): MarketplaceOrderSnapshot {
    $externalOrderId = $this->extractExternalOrderId($payload);

    return new MarketplaceOrderSnapshot(
      marketplace: 'ebay',
      externalOrderId: $externalOrderId,
      status: $this->extractStatus($payload),
      paymentStatus: $this->extractPaymentStatus($payload),
      fulfillmentStatus: $this->extractFulfillmentStatus($payload),
      orderedAt: $this->parseTimestamp($this->extractString($payload, ['creationDate'])),
      buyerHandle: $this->extractString($payload, ['buyer', 'username']),
      totalsJson: $this->encodeJson($this->extractTotalsPayload($payload)),
      payloadHash: hash('sha256', (string) $this->encodeJson($payload)),
      rawJson: $this->encodeJson($payload),
      lines: $this->mapLineSnapshots($payload),
    );
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function extractExternalOrderId(array $payload): string {
    $externalOrderId = $this->extractString($payload, ['orderId']);

    if ($externalOrderId !== NULL && $externalOrderId !== '') {
      return $externalOrderId;
    }

    throw new \RuntimeException('eBay order payload missing required orderId.');
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function extractStatus(array $payload): string {
    $fulfillmentStatus = $this->extractFulfillmentStatus($payload);
    if ($fulfillmentStatus !== NULL && $fulfillmentStatus !== '') {
      return $fulfillmentStatus;
    }

    $paymentStatus = $this->extractPaymentStatus($payload);
    if ($paymentStatus !== NULL && $paymentStatus !== '') {
      return $paymentStatus;
    }

    return 'unknown';
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function extractPaymentStatus(array $payload): ?string {
    $value = $this->extractString($payload, ['orderPaymentStatus']);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return strtolower($value);
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function extractFulfillmentStatus(array $payload): ?string {
    $value = $this->extractString($payload, ['orderFulfillmentStatus']);
    if ($value === NULL || $value === '') {
      return NULL;
    }
    return strtolower($value);
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array<int, \Drupal\marketplace_orders\Model\MarketplaceOrderLineSnapshot>
   */
  private function mapLineSnapshots(array $payload): array {
    $lineItems = $payload['lineItems'] ?? [];
    if (!is_array($lineItems)) {
      return [];
    }

    $snapshots = [];
    $fallbackIndex = 0;

    foreach ($lineItems as $lineItem) {
      if (!is_array($lineItem)) {
        continue;
      }

      $snapshots[] = $this->mapLineSnapshot($lineItem, $fallbackIndex);
      $fallbackIndex++;
    }

    return $snapshots;
  }

  /**
   * @param array<string, mixed> $lineItem
   */
  private function mapLineSnapshot(array $lineItem, int $fallbackIndex): MarketplaceOrderLineSnapshot {
    $sku = $this->extractString($lineItem, ['sku']);

    return new MarketplaceOrderLineSnapshot(
      externalLineId: $this->extractLineExternalId($lineItem, $fallbackIndex),
      sku: $sku,
      quantity: $this->extractQuantity($lineItem),
      titleSnapshot: $this->extractLineTitle($lineItem),
      priceSnapshot: $this->extractLinePrice($lineItem),
      listingUuid: $this->resolveListingUuidBySku($sku),
      rawJson: $this->encodeJson($lineItem),
    );
  }

  /**
   * @param array<string, mixed> $lineItem
   */
  private function extractLineExternalId(array $lineItem, int $fallbackIndex): string {
    $lineItemId = $this->extractString($lineItem, ['lineItemId']);
    if ($lineItemId !== NULL && $lineItemId !== '') {
      return $lineItemId;
    }

    return 'line_' . ($fallbackIndex + 1);
  }

  /**
   * @param array<string, mixed> $lineItem
   */
  private function extractQuantity(array $lineItem): int {
    $quantity = $lineItem['quantity'] ?? 1;
    if (!is_numeric($quantity)) {
      return 1;
    }

    $value = (int) $quantity;
    if ($value < 1) {
      return 1;
    }

    return $value;
  }

  /**
   * @param array<string, mixed> $lineItem
   */
  private function extractLineTitle(array $lineItem): ?string {
    $title = $this->extractString($lineItem, ['title']);
    if ($title !== NULL && $title !== '') {
      return $title;
    }

    return $this->extractString($lineItem, ['lineItemDescription']);
  }

  /**
   * @param array<string, mixed> $lineItem
   */
  private function extractLinePrice(array $lineItem): ?string {
    $lineItemCostValue = $this->extractString($lineItem, ['lineItemCost', 'value']);
    if ($lineItemCostValue !== NULL && $lineItemCostValue !== '') {
      return $lineItemCostValue;
    }

    return $this->extractString($lineItem, ['total', 'value']);
  }

  /**
   * @param array<string, mixed> $payload
   *
   * @return array<string, mixed>
   */
  private function extractTotalsPayload(array $payload): array {
    $totals = [];

    $pricingSummary = $payload['pricingSummary'] ?? NULL;
    if (is_array($pricingSummary)) {
      $totals['pricingSummary'] = $pricingSummary;
    }

    $orderTotal = $payload['total'] ?? NULL;
    if (is_array($orderTotal)) {
      $totals['total'] = $orderTotal;
    }

    return $totals;
  }

  private function parseTimestamp(?string $value): ?int {
    if ($value === NULL || $value === '') {
      return NULL;
    }

    $timestamp = strtotime($value);
    if ($timestamp === FALSE) {
      return NULL;
    }

    return $timestamp;
  }

  /**
   * @param array<string, mixed> $payload
   * @param array<int, string> $path
   */
  private function extractString(array $payload, array $path): ?string {
    $value = $payload;

    foreach ($path as $segment) {
      if (!is_array($value)) {
        return NULL;
      }

      if (!array_key_exists($segment, $value)) {
        return NULL;
      }

      $value = $value[$segment];
    }

    if ($value === NULL) {
      return NULL;
    }

    if (!is_scalar($value)) {
      return NULL;
    }

    return trim((string) $value);
  }

  /**
   * @param array<string, mixed> $payload
   */
  private function encodeJson(array $payload): ?string {
    $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($encoded === FALSE) {
      return NULL;
    }

    return $encoded;
  }

  private function resolveListingUuidBySku(?string $sku): ?string {
    if ($sku === NULL || $sku === '') {
      return NULL;
    }

    $inventorySkuStorage = $this->entityTypeManager->getStorage('ai_listing_inventory_sku');
    $query = $inventorySkuStorage->getQuery();
    $query->accessCheck(FALSE);
    $query->condition('sku', $sku);
    $query->sort('id', 'DESC');
    $query->range(0, 1);

    $ids = $query->execute();
    if ($ids === []) {
      return NULL;
    }

    $inventorySku = $inventorySkuStorage->load((int) reset($ids));
    if ($inventorySku === NULL) {
      return NULL;
    }

    $listingId = (int) ($inventorySku->get('listing')->target_id ?? 0);
    if ($listingId <= 0) {
      return NULL;
    }

    $listingStorage = $this->entityTypeManager->getStorage('bb_ai_listing');
    $listing = $listingStorage->load($listingId);
    if ($listing === NULL) {
      return NULL;
    }

    return (string) $listing->uuid();
  }

}
