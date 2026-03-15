<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Service;

use Drupal\bb_ai_listing_sync\Model\ListingSyncGraph;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

final class ListingSyncGraphFingerprintService {

  /**
   * @var array<string, string>
   */
  private array $entityUuidCache = [];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly LegacyTableSyncService $legacyTableSyncService,
  ) {}

  public function fingerprintGraph(ListingSyncGraph $graph): string {
    $normalizedGraph = $this->normalizeGraph($graph);
    $payload = json_encode($normalizedGraph, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
      throw new \RuntimeException('Unable to encode listing graph fingerprint payload.');
    }

    return hash('sha256', $payload);
  }

  /**
   * @return array{
   *   root_listing_uuid: string,
   *   entities: array<string, array<int, array<string, mixed>>>,
   *   legacy_tables: array<string, array<int, array<string, mixed>>>
   * }
   */
  private function normalizeGraph(ListingSyncGraph $graph): array {
    $entitiesByType = $graph->entitiesByType();
    ksort($entitiesByType);

    $normalizedEntities = [];
    foreach ($entitiesByType as $entityType => $entities) {
      $normalizedEntities[$entityType] = $this->normalizeEntities($entities);
    }

    $legacyPayload = $this->legacyTableSyncService->exportLegacyRowsForListing($graph->rootListingId());

    return [
      'root_listing_uuid' => $graph->rootListingUuid(),
      'entities' => $normalizedEntities,
      'legacy_tables' => $this->normalizeLegacyTables($legacyPayload),
    ];
  }

  /**
   * @param array<int, \Drupal\Core\Entity\EntityInterface> $entities
   *
   * @return array<int, array<string, mixed>>
   */
  private function normalizeEntities(array $entities): array {
    usort($entities, function (EntityInterface $left, EntityInterface $right): int {
      return strcmp((string) $left->uuid(), (string) $right->uuid());
    });

    $normalized = [];
    foreach ($entities as $entity) {
      $normalized[] = $this->normalizeEntity($entity);
    }
    return $normalized;
  }

  /**
   * @return array<string, mixed>
   */
  private function normalizeEntity(EntityInterface $entity): array {
    $values = $entity->toArray();
    unset($values['id']);
    unset($values['changed']);
    unset($values['created']);

    $fieldDefinitions = $entity->getFieldDefinitions();
    $normalizedFields = [];
    foreach ($values as $fieldName => $rawItems) {
      if (!isset($fieldDefinitions[$fieldName])) {
        continue;
      }

      $normalizedFields[$fieldName] = $this->normalizeFieldItems(
        $fieldDefinitions[$fieldName]->getType(),
        $fieldDefinitions[$fieldName]->getSetting('target_type'),
        $rawItems
      );
    }
    ksort($normalizedFields);

    return [
      'entity_type' => $entity->getEntityTypeId(),
      'bundle' => $entity->bundle(),
      'uuid' => (string) $entity->uuid(),
      'fields' => $normalizedFields,
    ];
  }

  /**
   * @param mixed $rawItems
   *
   * @return mixed
   */
  private function normalizeFieldItems(string $fieldType, mixed $targetTypeSetting, mixed $rawItems): mixed {
    if (!is_array($rawItems)) {
      return $this->canonicalize($rawItems);
    }

    $normalizedItems = [];
    foreach ($rawItems as $rawItem) {
      if (!is_array($rawItem)) {
        $normalizedItems[] = $this->canonicalize($rawItem);
        continue;
      }

      $item = $rawItem;
      if ($fieldType === 'entity_reference') {
        $referenceTargetType = is_string($targetTypeSetting) ? $targetTypeSetting : '';
        $item = $this->normalizeEntityReferenceItem($item, $referenceTargetType);
      }
      elseif ($fieldType === 'dynamic_entity_reference') {
        $item = $this->normalizeDynamicEntityReferenceItem($item);
      }

      $normalizedItems[] = $this->canonicalize($item);
    }

    return $normalizedItems;
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function normalizeEntityReferenceItem(array $item, string $targetType): array {
    $targetId = isset($item['target_id']) ? (int) $item['target_id'] : 0;
    if ($targetType !== '' && $targetId > 0) {
      $targetUuid = $this->resolveEntityUuid($targetType, $targetId);
      if ($targetUuid !== NULL) {
        $item['target_uuid'] = $targetUuid;
      }
    }

    unset($item['target_id']);
    return $item;
  }

  /**
   * @param array<string, mixed> $item
   *
   * @return array<string, mixed>
   */
  private function normalizeDynamicEntityReferenceItem(array $item): array {
    $targetType = isset($item['target_type']) ? (string) $item['target_type'] : '';
    $targetId = isset($item['target_id']) ? (int) $item['target_id'] : 0;

    if ($targetType !== '' && $targetId > 0) {
      $targetUuid = $this->resolveEntityUuid($targetType, $targetId);
      if ($targetUuid !== NULL) {
        $item['target_uuid'] = $targetUuid;
      }
    }

    unset($item['target_id']);
    return $item;
  }

  private function resolveEntityUuid(string $entityType, int $entityId): ?string {
    $cacheKey = $entityType . ':' . $entityId;
    if (array_key_exists($cacheKey, $this->entityUuidCache)) {
      return $this->entityUuidCache[$cacheKey];
    }

    $storage = $this->entityTypeManager->getStorage($entityType);
    $entity = $storage->load($entityId);
    if (!$entity instanceof EntityInterface) {
      return NULL;
    }

    $uuid = (string) $entity->uuid();
    $this->entityUuidCache[$cacheKey] = $uuid;
    return $uuid;
  }

  /**
   * @param array<string, array<int, array<string, mixed>>> $legacyPayload
   *
   * @return array<string, array<int, array<string, mixed>>>
   */
  private function normalizeLegacyTables(array $legacyPayload): array {
    $normalized = [];
    foreach ($legacyPayload as $tableName => $rows) {
      if (!is_array($rows)) {
        continue;
      }

      $normalizedRows = [];
      foreach ($rows as $row) {
        if (!is_array($row)) {
          continue;
        }
        $row = $this->stripVolatileLegacyFields($tableName, $row);
        $normalizedRows[] = $this->canonicalize($row);
      }

      usort($normalizedRows, function (array $left, array $right): int {
        return strcmp((string) json_encode($left), (string) json_encode($right));
      });

      $normalized[$tableName] = $normalizedRows;
    }

    ksort($normalized);
    return $normalized;
  }

  /**
   * @param array<string, mixed> $row
   *
   * @return array<string, mixed>
   */
  private function stripVolatileLegacyFields(string $tableName, array $row): array {
    unset($row['id']);

    if ($tableName === 'bb_ebay_legacy_listing_link') {
      unset($row['changed']);
      unset($row['created']);
    }

    if ($tableName === 'bb_ebay_legacy_listing') {
      unset($row['last_seen']);
    }

    return $row;
  }

  /**
   * @param mixed $value
   *
   * @return mixed
   */
  private function canonicalize(mixed $value): mixed {
    if (!is_array($value)) {
      return $value;
    }

    $isList = array_is_list($value);
    if ($isList) {
      $result = [];
      foreach ($value as $item) {
        $result[] = $this->canonicalize($item);
      }
      return $result;
    }

    $result = [];
    foreach ($value as $key => $item) {
      $result[(string) $key] = $this->canonicalize($item);
    }
    ksort($result);
    return $result;
  }

}
