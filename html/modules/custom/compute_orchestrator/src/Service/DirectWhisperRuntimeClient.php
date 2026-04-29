<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Direct in-process Whisper runtime client backed by VllmPoolManager.
 *
 * This preserves the current production behaviour while the remote HTTP client
 * is developed and tested behind the same interface.
 */
final class DirectWhisperRuntimeClient implements FramesmithRuntimeLeaseManagerInterface {

  /**
   * Module logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly VllmPoolManager $poolManager,
    LoggerChannelFactoryInterface|LoggerInterface|null $logger = NULL,
  ) {
    if ($logger instanceof LoggerChannelFactoryInterface) {
      $this->logger = $logger->get('compute_orchestrator');
    }
    elseif ($logger instanceof LoggerInterface) {
      $this->logger = $logger;
    }
    else {
      $this->logger = new NullLogger();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function acquireWhisperRuntime(): array {
    $this->emitTransitionalWarning('acquireWhisperRuntime');
    $record = $this->poolManager->acquire('whisper');

    return $this->normalizePoolRecord($record);
  }

  /**
   * {@inheritdoc}
   */
  public function releaseRuntime(string $contractId, ?string $leaseToken = NULL): array {
    $this->emitTransitionalWarning('releaseRuntime');
    return $this->poolManager->release($contractId);
  }

  /**
   * Emits a loud warning when the transitional direct compute path is used.
   */
  private function emitTransitionalWarning(string $operation): void {
    $message = 'The transitional direct in-process Whisper runtime client is in use. Migrate this path to the remote compute runtime lease API before extracting product backends.';
    $this->logger->warning($message, [
      'operation' => $operation,
    ]);
    trigger_error($message, E_USER_WARNING);
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
