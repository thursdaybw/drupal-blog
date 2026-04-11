<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Persists pooled vLLM instance inventory.
 */
interface VllmPoolRepositoryInterface {

  /**
   * Returns all tracked pool instances keyed by contract ID.
   *
   * @return array<string, array<string, mixed>>
   *   Pool records.
   */
  public function all(): array;

  /**
   * Returns a single pool record if present.
   *
   * @return array<string,mixed>|null
   *   Pool record or NULL if missing.
   */
  public function get(string $contractId): ?array;

  /**
   * Saves or updates a pool record.
   *
   * @param array<string,mixed> $record
   *   Pool record including contract_id.
   */
  public function save(array $record): void;

  /**
   * Deletes a single pool record if it exists.
   */
  public function delete(string $contractId): void;

  /**
   * Clears the entire pool inventory.
   */
  public function clear(): void;

}
