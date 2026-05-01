<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\compute_orchestrator\Exception\AcquirePendingException;

/**
 * Adapts the pooled vLLM manager to Framesmith transcription lease needs.
 */
final class FramesmithVllmPoolLeaseManager implements FramesmithRuntimeLeaseManagerInterface {

  public function __construct(
    private readonly VllmPoolLeaseBrokerInterface $poolManager,
    private readonly int $acquireTimeoutSeconds = 900,
    private readonly int $acquireRetryDelaySeconds = 5,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function acquireWhisperRuntime(): array {
    $record = $this->acquireWhisperRuntimeUntilReady();
    return $this->buildLeaseFromPoolRecord($record);
  }

  /**
   * Blocks until the pool returns a ready Whisper runtime or times out.
   *
   * VllmPoolManager::acquire() is deliberately slice-friendly because Drupal
   * Batch needs to return between HTTP requests. Framesmith's detached runner
   * is a different context: it owns one background process and should wait
   * there
   * until the runtime is ready. Letting AcquirePendingException escape turns a
   * normal cold-start probe into a fake task failure.
   *
   * @return array<string,mixed>
   *   Ready pool record.
   */
  private function acquireWhisperRuntimeUntilReady(): array {
    $deadline = time() + max(1, $this->acquireTimeoutSeconds);
    $lastPending = NULL;

    while (time() < $deadline) {
      try {
        return $this->poolManager->acquire(
          'whisper',
          NULL,
          TRUE,
          max(1, $this->acquireRetryDelaySeconds),
          max(1, $this->acquireRetryDelaySeconds),
        );
      }
      catch (AcquirePendingException $exception) {
        $lastPending = $exception;
        $this->waitBeforeNextAcquireSlice($deadline);
      }
    }

    throw $this->buildAcquireTimeoutException($lastPending);
  }

  /**
   * Waits between retryable acquire slices without sleeping past the deadline.
   */
  private function waitBeforeNextAcquireSlice(int $deadline): void {
    $remainingSeconds = $deadline - time();
    if ($remainingSeconds <= 0) {
      return;
    }
    $sleepSeconds = min(max(0, $this->acquireRetryDelaySeconds), $remainingSeconds);
    if ($sleepSeconds > 0) {
      sleep($sleepSeconds);
    }
  }

  /**
   * Builds the terminal timeout exception after all warmup retries are spent.
   */
  private function buildAcquireTimeoutException(?AcquirePendingException $lastPending): \RuntimeException {
    $message = 'Timed out waiting for Whisper runtime to become ready.';
    if ($lastPending !== NULL) {
      $message .= ' Last progress: ' . $lastPending->getMessage();
    }
    return new \RuntimeException($message, 0, $lastPending);
  }

  /**
   * Converts a ready pool record into Framesmith's lease payload.
   *
   * @param array<string,mixed> $record
   *   Ready pool record returned by the pool manager.
   *
   * @return array<string,mixed>
   *   Framesmith runtime lease payload.
   */
  private function buildLeaseFromPoolRecord(array $record): array {
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
