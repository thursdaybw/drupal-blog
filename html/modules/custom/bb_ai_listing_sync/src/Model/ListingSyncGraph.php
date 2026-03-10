<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Model;

use Drupal\Core\Entity\EntityInterface;

final class ListingSyncGraph {

  /**
   * @param array<string, array<int, \Drupal\Core\Entity\EntityInterface>> $entitiesByType
   * @param array<int, string> $uuids
   * @param array<string, int> $counts
   */
  public function __construct(
    private readonly array $entitiesByType,
    private readonly array $uuids,
    private readonly string $rootListingUuid,
    private readonly int $rootListingId,
    private readonly array $counts,
    private readonly int $totalEntities,
  ) {}

  /**
   * @return array<string, array<int, \Drupal\Core\Entity\EntityInterface>>
   */
  public function entitiesByType(): array {
    return $this->entitiesByType;
  }

  /**
   * @return array<int, string>
   */
  public function uuids(): array {
    return $this->uuids;
  }

  public function rootListingUuid(): string {
    return $this->rootListingUuid;
  }

  public function rootListingId(): int {
    return $this->rootListingId;
  }

  /**
   * @return array<string, int>
   */
  public function counts(): array {
    return $this->counts;
  }

  public function totalEntities(): int {
    return $this->totalEntities;
  }

  public function totalUuids(): int {
    return count($this->uuids);
  }

  public function uuidsCsv(): string {
    return implode(',', $this->uuids);
  }

  public function nativeExportCommandPreview(): string {
    return sprintf(
      'vendor/bin/drush content-sync:export sync --uuids=%s --skiplist -y',
      $this->uuidsCsv()
    );
  }

  /**
   * @return array{
   *   entities_by_type: array<string, array<int, \Drupal\Core\Entity\EntityInterface>>,
   *   uuids: array<int, string>,
   *   root_listing_uuid: string,
   *   root_listing_id: int,
   *   counts: array<string, int>,
   *   total_entities: int
   * }
   */
  public function toArray(): array {
    return [
      'entities_by_type' => $this->entitiesByType,
      'uuids' => $this->uuids,
      'root_listing_uuid' => $this->rootListingUuid,
      'root_listing_id' => $this->rootListingId,
      'counts' => $this->counts,
      'total_entities' => $this->totalEntities,
    ];
  }

}

