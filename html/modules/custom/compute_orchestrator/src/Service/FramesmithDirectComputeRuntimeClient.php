<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Direct in-process Framesmith compute client backed by VllmPoolManager.
 *
 * This preserves the current production behaviour while the remote HTTP client
 * is developed and tested behind the same interface.
 */
final class FramesmithDirectComputeRuntimeClient implements FramesmithRuntimeLeaseManagerInterface {

  public function __construct(
    private readonly VllmPoolManager $poolManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function acquireWhisperRuntime(): array {
    $record = $this->poolManager->acquire('whisper');

    return $this->normalizePoolRecord($record);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseRuntime(string $contractId): array {
    return $this->poolManager->release($contractId);
  }

  /**
   * Converts a pool record into the Framesmith backend lease shape.
   *
   * @param array<string,mixed> $record
   *   Internal pool record.
   *
   * @return array<string,mixed>
   *   Framesmith backend lease details.
   */
  private function normalizePoolRecord(array $record): array {
    return [
      'contract_id' => (string) ($record['contract_id'] ?? ''),
      'lease_token' => (string) ($record['lease_token'] ?? ''),
      'host' => (string) ($record['host'] ?? ''),
      'port' => (string) ($record['port'] ?? ''),
      'url' => (string) ($record['url'] ?? ''),
      'current_workload_mode' => (string) ($record['current_workload_mode'] ?? 'whisper'),
      'current_model' => (string) ($record['current_model'] ?? ''),
      'pool_record' => $record,
    ];
  }

}
