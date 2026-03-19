<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\Core\KeyValueStore\KeyValueFactoryInterface;

/**
 * Stores local stock-cull marks outside listing publish state.
 */
final class StockCullSelectionStore {

  public const STATUS_NOT_MARKED = 'not_marked';
  public const STATUS_MARKED_FOR_CULL = 'marked_for_cull';
  public const STATUS_CULLED = 'culled';

  public function __construct(
    private readonly KeyValueFactoryInterface $keyValueFactory,
  ) {}

  /**
   * @param int[] $listingIds
   *
   * @return array<int,string>
   */
  public function getStatuses(array $listingIds): array {
    $ids = $this->normalizeListingIds($listingIds);
    if ($ids === []) {
      return [];
    }

    $stored = $this->collection()->getMultiple(array_map('strval', $ids));
    $statuses = [];
    foreach ($ids as $listingId) {
      $status = $stored[(string) $listingId] ?? self::STATUS_NOT_MARKED;
      $statuses[$listingId] = is_string($status) && $status !== '' ? $status : self::STATUS_NOT_MARKED;
    }

    return $statuses;
  }

  /**
   * @param int[] $listingIds
   */
  public function markForCull(array $listingIds): void {
    $this->setStatus($listingIds, self::STATUS_MARKED_FOR_CULL);
  }

  /**
   * @param int[] $listingIds
   */
  public function clearMark(array $listingIds): void {
    $ids = $this->normalizeListingIds($listingIds);
    if ($ids === []) {
      return;
    }

    $this->collection()->deleteMultiple(array_map('strval', $ids));
  }

  /**
   * @param int[] $listingIds
   */
  public function countMarked(array $listingIds): int {
    $count = 0;
    foreach ($this->getStatuses($listingIds) as $status) {
      if ($status === self::STATUS_MARKED_FOR_CULL) {
        $count++;
      }
    }

    return $count;
  }

  /**
   * @param int[] $listingIds
   */
  private function setStatus(array $listingIds, string $status): void {
    $ids = $this->normalizeListingIds($listingIds);
    if ($ids === []) {
      return;
    }

    $values = [];
    foreach ($ids as $listingId) {
      $values[(string) $listingId] = $status;
    }

    $this->collection()->setMultiple($values);
  }

  /**
   * @param int[] $listingIds
   *
   * @return int[]
   */
  private function normalizeListingIds(array $listingIds): array {
    $normalized = [];
    foreach ($listingIds as $listingId) {
      $listingId = (int) $listingId;
      if ($listingId > 0) {
        $normalized[] = $listingId;
      }
    }

    return array_values(array_unique($normalized));
  }

  private function collection() {
    return $this->keyValueFactory->get('ai_listing.stock_cull');
  }

}
