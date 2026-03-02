<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

final class AiListingBatchSelectionManager {

  public function buildSelectionKey(string $listingType, int $id): string {
    return trim($listingType) . ':' . $id;
  }

  /**
   * @return array{listing_type:string,id:int}|null
   */
  public function parseSelectionKey(string $key): ?array {
    if (!str_contains($key, ':')) {
      return null;
    }

    [$listingType, $id] = explode(':', $key, 2);
    $listingType = trim($listingType);
    $entityId = (int) $id;

    if ($listingType === '' || $entityId <= 0) {
      return null;
    }

    return [
      'listing_type' => $listingType,
      'id' => $entityId,
    ];
  }

  /**
   * @param mixed $submittedValue
   * @param array<string,mixed> $currentPageSelection
   * @return string[]
   */
  public function extractSelectionKeys(mixed $submittedValue, array $currentPageSelection = []): array {
    if (!is_string($submittedValue) || trim($submittedValue) === '') {
      return $this->extractSelectionKeysFromCurrentPageSelection($currentPageSelection);
    }

    $decoded = json_decode($submittedValue, true);
    if (!is_array($decoded)) {
      return [];
    }

    return $this->normalizeSelectionKeys($decoded);
  }

  /**
   * @param array<int|string,mixed> $currentPageSelection
   * @return string[]
   */
  private function extractSelectionKeysFromCurrentPageSelection(array $currentPageSelection): array {
    $selected = array_filter($currentPageSelection);
    $keys = array_map('strval', array_keys($selected));
    return $this->normalizeSelectionKeys($keys);
  }

  /**
   * @param mixed[] $keys
   * @return string[]
   */
  public function normalizeSelectionKeys(array $keys): array {
    $normalizedKeys = [];

    foreach ($keys as $value) {
      if (!is_string($value)) {
        continue;
      }

      $normalizedValue = trim(rawurldecode($value));
      if ($normalizedValue === '') {
        continue;
      }

      $normalizedKeys[] = $normalizedValue;
    }

    return array_values(array_unique($normalizedKeys));
  }

  /**
   * @param string[] $keys
   */
  public function encodeSelectionKeys(array $keys): string {
    $normalizedKeys = $this->normalizeSelectionKeys($keys);
    $encoded = json_encode($normalizedKeys);

    if (!is_string($encoded)) {
      return '[]';
    }

    return $encoded;
  }

  /**
   * @param string[] $keys
   */
  public function countSelectionKeys(array $keys): int {
    return count($this->buildSelectionRefs($keys));
  }

  /**
   * @param string[] $keys
   * @return array<int,array{listing_type:string,id:int}>
   */
  public function buildSelectionRefs(array $keys): array {
    $selection = [];

    foreach ($this->normalizeSelectionKeys($keys) as $key) {
      $decoded = $this->parseSelectionKey($key);
      if ($decoded !== null) {
        $selection[] = $decoded;
      }
    }

    return $selection;
  }

}
