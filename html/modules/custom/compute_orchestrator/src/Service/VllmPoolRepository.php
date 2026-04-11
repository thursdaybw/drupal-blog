<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\State\StateInterface;

/**
 * Stores pooled vLLM instance inventory in Drupal state.
 */
final class VllmPoolRepository implements VllmPoolRepositoryInterface {

  private const STATE_KEY = 'compute_orchestrator.vllm_pool.instances';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function all(): array {
    $records = $this->state->get(self::STATE_KEY, []);
    return is_array($records) ? $records : [];
  }

  /**
   * {@inheritdoc}
   */
  public function get(string $contractId): ?array {
    $records = $this->all();
    $record = $records[$contractId] ?? NULL;
    return is_array($record) ? $record : NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $record): void {
    $contractId = trim((string) ($record['contract_id'] ?? ''));
    if ($contractId === '') {
      throw new \InvalidArgumentException('Pool record must include a contract_id.');
    }

    $records = $this->all();
    $records[$contractId] = $record;
    ksort($records);
    $this->state->set(self::STATE_KEY, $records);
  }

  /**
   * {@inheritdoc}
   */
  public function delete(string $contractId): void {
    $records = $this->all();
    unset($records[$contractId]);
    $this->state->set(self::STATE_KEY, $records);
  }

  /**
   * {@inheritdoc}
   */
  public function clear(): void {
    $this->state->delete(self::STATE_KEY);
  }

}
