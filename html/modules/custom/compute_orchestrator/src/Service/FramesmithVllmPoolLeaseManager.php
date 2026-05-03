<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\compute_orchestrator\Exception\AcquirePendingException;

/**
 * Adapts the pooled vLLM manager to Framesmith transcription lease needs.
 */
final class FramesmithVllmPoolLeaseManager implements FramesmithRuntimeLeaseManagerInterface {

  /**
   * Current-time provider used by deterministic Vast lifecycle tests.
   */
  private \Closure $now;

  /**
   * Sleep function used between acquire slices.
   */
  private \Closure $sleep;

  public function __construct(
    private readonly VllmPoolLeaseBrokerInterface $poolManager,
    private readonly int $acquireTimeoutSeconds = 900,
    private readonly int $acquireRetryDelaySeconds = 5,
    private readonly int $absoluteAcquireTimeoutSeconds = 1800,
    ?callable $now = NULL,
    ?callable $sleep = NULL,
  ) {
    $this->now = \Closure::fromCallable($now ?? static fn(): int => time());
    $this->sleep = \Closure::fromCallable($sleep ?? static fn(int $seconds): mixed => sleep($seconds));
  }

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
    $startedAt = $this->currentTime();
    $stallDeadline = $startedAt + max(1, $this->acquireTimeoutSeconds);
    $absoluteDeadline = $startedAt + max(
      $this->acquireTimeoutSeconds,
      $this->absoluteAcquireTimeoutSeconds,
    );
    $lastPending = NULL;
    $lastProgressFingerprint = '';

    while ($this->currentTime() < $stallDeadline && $this->currentTime() < $absoluteDeadline) {
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
        $fingerprint = $this->progressFingerprint($exception);
        if ($fingerprint !== '' && $fingerprint !== $lastProgressFingerprint) {
          $lastProgressFingerprint = $fingerprint;
          $stallDeadline = $this->currentTime() + max(1, $this->acquireTimeoutSeconds);
        }
        $this->waitBeforeNextAcquireSlice(min($stallDeadline, $absoluteDeadline));
      }
    }

    throw $this->buildAcquireTimeoutException($lastPending);
  }

  /**
   * Builds a stable fingerprint for meaningful acquire progress.
   *
   * The Framesmith runner should not spend one global deadline across several
   * different Vast phases. A replacement contract, SSH bootstrap progress,
   * start-model success, or movement into API readiness is new work and resets
   * the stall timer. Repeating the same "API not listening" observation does
   * not reset forever; the separate absolute deadline still prevents runaway
   * cost if a host keeps pretending to make progress.
   */
  private function progressFingerprint(AcquirePendingException $exception): string {
    $progress = $exception->getProgress();
    $bits = [
      (string) ($exception->getContractId() ?? ''),
      (string) ($progress['phase'] ?? ''),
      (string) ($progress['action'] ?? ''),
      (string) ($progress['step'] ?? ''),
      (string) ($progress['label'] ?? ''),
      (string) ($progress['next'] ?? ''),
    ];
    return implode('|', $bits);
  }

  /**
   * Waits between retryable acquire slices without sleeping past the deadline.
   */
  private function waitBeforeNextAcquireSlice(int $deadline): void {
    $remainingSeconds = $deadline - $this->currentTime();
    if ($remainingSeconds <= 0) {
      return;
    }
    $sleepSeconds = min(max(0, $this->acquireRetryDelaySeconds), $remainingSeconds);
    if ($sleepSeconds > 0) {
      ($this->sleep)($sleepSeconds);
    }
  }

  /**
   * Returns the current timestamp from the injected clock.
   */
  private function currentTime(): int {
    return (int) ($this->now)();
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
