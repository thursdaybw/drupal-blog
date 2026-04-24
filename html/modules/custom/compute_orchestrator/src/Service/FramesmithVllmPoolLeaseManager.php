<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

/**
 * Adapts the pooled vLLM manager to Framesmith transcription lease needs.
 */
final class FramesmithVllmPoolLeaseManager implements FramesmithRuntimeLeaseManagerInterface {

  public function __construct(
    private readonly VllmPoolManager $poolManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function acquireWhisperRuntime(): array {
    $record = $this->poolManager->acquire('whisper');

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

  /**
   * {@inheritdoc}
   */
  public function releaseRuntime(string $contractId): array {
    return $this->poolManager->release($contractId);
  }

}
