<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_mirror\Service;

use Drupal\Core\Database\Connection;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\SellApiClient;

final class EbayInventoryMirrorSyncService {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly Connection $database,
  ) {}

  /**
   * Sync all inventory pages from eBay into the local mirror table.
   *
   * @return array{pages:int,seen:int,upserted:int}
   */
  public function syncAll(EbayAccount $account, int $pageSize = 100): array {
    $offset = 0;
    $pages = 0;
    $seen = 0;
    $upserted = 0;
    $accountId = (int) $account->id();
    $seenSkus = [];

    do {
      $response = $this->sellApiClient->listInventoryItemsForAccount($account, $pageSize, $offset);
      $inventoryItems = $response['inventoryItems'] ?? [];

      if (!is_array($inventoryItems) || $inventoryItems === []) {
        break;
      }

      $pages++;

      foreach ($inventoryItems as $inventoryItem) {
        if (!is_array($inventoryItem)) {
          continue;
        }

        $this->upsertInventoryItem($accountId, $inventoryItem);
        $sku = trim((string) ($inventoryItem['sku'] ?? ''));
        if ($sku !== '') {
          $seenSkus[$sku] = TRUE;
        }
        $seen++;
        $upserted++;
      }

      $offset += count($inventoryItems);
    } while (count($inventoryItems) === $pageSize);

    $this->deleteInventoryItemsNotSeenInRun($accountId, array_keys($seenSkus));

    return [
      'pages' => $pages,
      'seen' => $seen,
      'upserted' => $upserted,
    ];
  }

  private function upsertInventoryItem(int $accountId, array $inventoryItem): void {
    $sku = trim((string) ($inventoryItem['sku'] ?? ''));
    if ($sku === '') {
      return;
    }

    $product = $inventoryItem['product'] ?? [];
    $availability = $inventoryItem['availability']['shipToLocationAvailability'] ?? [];

    $record = [
      'account_id' => $accountId,
      'sku' => $sku,
      'locale' => $this->normalizeNullableString($inventoryItem['locale'] ?? NULL),
      'title' => $this->normalizeNullableString($product['title'] ?? NULL),
      'description' => $this->normalizeNullableString($product['description'] ?? NULL),
      'condition' => $this->normalizeNullableString($inventoryItem['condition'] ?? NULL),
      'condition_description' => $this->normalizeNullableString($inventoryItem['conditionDescription'] ?? NULL),
      'available_quantity' => $this->normalizeNullableInt($availability['quantity'] ?? NULL),
      'aspects_json' => $this->encodeJsonOrNull($product['aspects'] ?? NULL),
      'image_urls_json' => $this->encodeJsonOrNull($product['imageUrls'] ?? NULL),
      'raw_json' => $this->encodeJsonOrNull($inventoryItem),
      'last_seen' => time(),
    ];

    $this->database->merge('bb_ebay_inventory_item')
      ->key([
        'account_id' => $accountId,
        'sku' => $sku,
      ])
      ->fields($record)
      ->execute();
  }

  /**
   * Remove mirrored inventory rows for this account that eBay did not return.
   *
   * A mirror should reflect current remote state, not keep stale rows forever.
   *
   * @param string[] $seenSkus
   *   The SKUs returned by eBay in the current sync run.
   */
  private function deleteInventoryItemsNotSeenInRun(int $accountId, array $seenSkus): void {
    $delete = $this->database->delete('bb_ebay_inventory_item')
      ->condition('account_id', $accountId);

    if ($seenSkus !== []) {
      $delete->condition('sku', $seenSkus, 'NOT IN');
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

  private function encodeJsonOrNull(mixed $value): ?string {
    if ($value === NULL) {
      return NULL;
    }

    return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: NULL;
  }

}
