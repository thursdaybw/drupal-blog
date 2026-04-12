<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\State\StateInterface;
use Drupal\compute_orchestrator\Exception\AcquirePendingException;
use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;

/**
 * Provides pooled acquire/release semantics for generic vLLM instances.
 */
final class VllmPoolManager {

  private const DEFAULT_IDLE_SHUTDOWN_SECONDS = 600;
  private const DEFAULT_LEASE_TTL_SECONDS = 600;
  private const STATE_ACTIVE_POOL_CONTRACT = 'compute_orchestrator.vllm_pool.active_contract_id';

  private const STATE_IDLE_SHUTDOWN_SECONDS = 'compute_orchestrator.vllm_pool.idle_shutdown_seconds';
  private const STATE_LEASE_TTL_SECONDS = 'compute_orchestrator.vllm_pool.lease_ttl_seconds';
  private const STATE_WORKLOAD_READY_TIMEOUT_SECONDS = 'compute_orchestrator.vllm_pool.workload_ready_timeout_seconds';
  private const DEFAULT_WORKLOAD_READY_TIMEOUT_SECONDS = 300;

  public function __construct(
    private readonly VllmPoolRepositoryInterface $poolRepository,
    private readonly VllmWorkloadCatalogInterface $workloadCatalog,
    private readonly GenericVllmRuntimeManagerInterface $runtimeManager,
    private readonly VastInstanceLifecycleClientInterface $instanceLifecycleClient,
    private readonly VastRestClientInterface $vastClient,
    private readonly StateInterface $state,
    private readonly int $wakeSchedulingProbeAttempts = 3,
    private readonly int $wakeSchedulingProbeDelaySeconds = 10,
  ) {}

  /**
   * Registers an arbitrary leased Vast contract in the reusable pool.
   *
   * This is the operator path used to stage real-world pool scenarios, such as
   * "sleeping leased instance" or "already-running reusable instance",
   * without relying on the currently active Drupal runtime state.
   *
   * @return array<string,mixed>
   *   Saved pool record.
   */
  public function registerInstance(
    string $contractId,
    string $image,
    string $workload = '',
    string $model = '',
    string $source = 'manual',
  ): array {
    $contractId = trim($contractId);
    if ($contractId === '') {
      throw new \InvalidArgumentException('A Vast contract ID is required.');
    }

    if (trim($image) === '') {
      throw new \InvalidArgumentException('A generic image reference is required.');
    }

    $info = $this->vastClient->showInstance($contractId);
    $record = [
      'contract_id' => $contractId,
      'image' => trim($image),
      'current_workload_mode' => trim($workload),
      'current_model' => trim($model),
      'lease_status' => 'available',
      'host' => trim((string) ($info['public_ipaddr'] ?? '')),
      'port' => $this->extractPublicPort($info),
      'url' => $this->buildPublicUrl($info),
      'source' => $source,
      'last_seen_at' => time(),
      'last_used_at' => time(),
      'last_error' => '',
    ];

    $this->poolRepository->save($record);
    return $record;
  }

  /**
   * Returns the known pool inventory.
   *
   * @return array<string, array<string,mixed>>
   *   Pool records keyed by contract ID.
   */
  public function listInstances(): array {
    return $this->poolRepository->all();
  }

  /**
   * Acquires a pooled instance for the requested workload.
   *
   * @return array<string,mixed>
   *   Acquired pool record.
   */
  public function acquire(
    string $workload,
    ?string $modelOverride = NULL,
    bool $allowFresh = TRUE,
    ?int $bootstrapTimeoutSeconds = NULL,
    ?int $workloadTimeoutSeconds = NULL,
  ): array {
    $bootstrapTimeout = max(10, $bootstrapTimeoutSeconds ?? 600);
    $workloadTimeout = max(10, $workloadTimeoutSeconds ?? $this->getWorkloadReadyTimeoutSeconds());
    $definition = $this->workloadCatalog->getDefinition($workload, $modelOverride);

    foreach ($this->sortCandidates($this->poolRepository->all(), $definition) as $record) {
      if (($record['lease_status'] ?? '') === 'leased') {
        $recovered = $this->recoverStaleLeasedRecord($record);
        if ($recovered === NULL) {
          continue;
        }
        $record = $recovered;
      }

      $updated = $this->tryAcquireExistingInstance($record, $definition, $bootstrapTimeout, $workloadTimeout);
      if ($updated !== NULL) {
        return $updated;
      }
    }

    if (!$allowFresh) {
      throw new \RuntimeException('No pooled instance was available and fresh provisioning is disabled.');
    }

    $activeContract = $this->findActivePoolRuntimeContractId();
    if ($activeContract !== NULL) {
      throw new \RuntimeException(
        'Refusing fresh provisioning: pooled contract '
        . $activeContract
        . ' is already running or bootstrapping. Refresh pool status, then reuse/release/reap that instance.'
      );
    }

    return $this->acquireFreshInstance($definition, $bootstrapTimeout, $workloadTimeout);
  }

  /**
   * Finds an active pooled contract that should block fresh spin-up.
   *
   * @return string|null
   *   Contract ID when any pooled runtime is active, NULL otherwise.
   */
  private function findActivePoolRuntimeContractId(): ?string {
    foreach ($this->poolRepository->all() as $contractId => $record) {
      if (!is_array($record)) {
        continue;
      }

      $leaseStatus = (string) ($record['lease_status'] ?? '');
      if ($leaseStatus === 'bootstrapping') {
        return (string) $contractId;
      }

      try {
        $info = $this->vastClient->showInstance((string) $contractId);
        if ($this->isRunningState($info)) {
          return (string) $contractId;
        }
      }
      catch (\Throwable) {
        // Ignore probe failures here; candidate probing is handled during
        // the main acquisition loop.
      }
    }

    return NULL;
  }

  /**
   * Reclassifies a stale leased record when the backing Vast instance is idle.
   *
   * A record may remain marked as "leased" if an operator manually stops the
   * Vast instance outside Drupal. In that case, acquire should reclaim it
   * instead of provisioning fresh capacity.
   *
   * @param array<string,mixed> $record
   *   Candidate pool record.
   *
   * @return array<string,mixed>|null
   *   Updated reusable record, or NULL when it should still be skipped.
   */
  private function recoverStaleLeasedRecord(array $record): ?array {
    $contractId = trim((string) ($record['contract_id'] ?? ''));
    if ($contractId === '') {
      return NULL;
    }

    try {
      $info = $this->vastClient->showInstance($contractId);
    }
    catch (\Throwable $exception) {
      $record['lease_status'] = 'unavailable';
      $record['last_error'] = $exception->getMessage();
      $record['last_seen_at'] = time();
      $this->poolRepository->save($record);
      return NULL;
    }

    if ($this->isRunningState($info)) {
      // Still running under lease; leave untouched and skip this candidate.
      return NULL;
    }

    $record['lease_status'] = 'available';
    $this->clearLeaseMetadata($record);
    $record['vast_cur_state'] = (string) ($info['cur_state'] ?? '');
    $record['vast_actual_status'] = (string) ($info['actual_status'] ?? '');
    $record['last_seen_at'] = time();
    $record['last_error'] = '';
    $this->poolRepository->save($record);
    return $record;
  }

  /**
   * Releases a previously acquired instance back to the pool.
   *
   * @return array<string,mixed>
   *   Updated pool record.
   */
  public function release(string $contractId): array {
    $record = $this->poolRepository->get($contractId);
    if ($record === NULL) {
      throw new \RuntimeException('Unknown pooled instance: ' . $contractId);
    }

    $record['lease_status'] = 'available';
    $this->clearLeaseMetadata($record);
    $record['last_used_at'] = time();
    $this->poolRepository->save($record);
    $this->clearActivePoolContractIfMatches($contractId);
    return $record;
  }

  /**
   * Renews an active lease and extends its expiry.
   *
   * @return array<string,mixed>
   *   Updated pool record.
   */
  public function renewLease(string $contractId, ?string $leaseToken = NULL, ?int $ttlSeconds = NULL): array {
    $record = $this->poolRepository->get($contractId);
    if ($record === NULL) {
      throw new \RuntimeException('Unknown pooled instance: ' . $contractId);
    }

    if (($record['lease_status'] ?? '') !== 'leased') {
      throw new \RuntimeException('Cannot renew lease for non-leased instance: ' . $contractId);
    }

    $storedToken = trim((string) ($record['lease_token'] ?? ''));
    if (
      $leaseToken !== NULL
      && trim($leaseToken) !== ''
      && $storedToken !== ''
      && !hash_equals($storedToken, trim($leaseToken))
    ) {
      throw new \RuntimeException('Lease token mismatch for contract ' . $contractId . '.');
    }

    $ttl = max(60, $ttlSeconds ?? $this->getLeaseTtlSeconds());
    $now = time();
    $record['last_heartbeat_at'] = $now;
    $record['lease_expires_at'] = $now + $ttl;
    $record['last_seen_at'] = $now;
    $record['last_error'] = '';
    $this->poolRepository->save($record);
    return $record;
  }

  /**
   * Returns the configured post-lease grace period in seconds.
   */
  public function getIdleShutdownSeconds(): int {
    $configured = (int) $this->state->get(self::STATE_IDLE_SHUTDOWN_SECONDS, self::DEFAULT_IDLE_SHUTDOWN_SECONDS);
    return max(0, $configured);
  }

  /**
   * Returns the lease TTL used for acquisitions and renewals.
   */
  public function getLeaseTtlSeconds(): int {
    $configured = (int) $this->state->get(self::STATE_LEASE_TTL_SECONDS, self::DEFAULT_LEASE_TTL_SECONDS);
    return max(60, $configured);
  }

  /**
   * Returns the workload readiness timeout used for pool acquire.
   */
  private function getWorkloadReadyTimeoutSeconds(): int {
    $configured = (int) $this->state->get(
      self::STATE_WORKLOAD_READY_TIMEOUT_SECONDS,
      self::DEFAULT_WORKLOAD_READY_TIMEOUT_SECONDS,
    );
    return max(60, $configured);
  }

  /**
   * Stops reap-eligible instances.
   *
   * Eligible rows are:
   * - available instances that exceeded the post-lease grace period
   * - leased instances whose lease has expired (zombie lease recovery)
   *
   * @return array<int, array<string,string>>
   *   One result row per considered stale instance.
   */
  public function reapIdleAvailableInstances(?int $idleSeconds = NULL, bool $dryRun = FALSE): array {
    $threshold = max(0, $idleSeconds ?? $this->getIdleShutdownSeconds());
    if ($threshold === 0) {
      return [];
    }

    $now = time();
    $results = [];
    foreach ($this->poolRepository->all() as $contractId => $record) {
      if (!is_array($record)) {
        continue;
      }

      if ($this->isLeaseExpired($record, $now)) {
        $contractId = (string) $contractId;
        $record = $this->reclaimExpiredLease($record, $now);
        $results[] = $this->reapIdleAvailableInstance($contractId, $record, $dryRun);
        continue;
      }

      if (($record['lease_status'] ?? '') !== 'available') {
        continue;
      }

      $lastUsedAt = (int) ($record['last_used_at'] ?? 0);
      if ($lastUsedAt <= 0 || ($now - $lastUsedAt) < $threshold) {
        continue;
      }

      $contractId = (string) $contractId;
      $results[] = $this->reapIdleAvailableInstance($contractId, $record, $dryRun);
    }

    return $results;
  }

  /**
   * Returns TRUE when a leased record has passed its expiry timestamp.
   *
   * @param array<string,mixed> $record
   *   Pool record.
   * @param int $now
   *   Current unix timestamp.
   */
  private function isLeaseExpired(array $record, int $now): bool {
    if (($record['lease_status'] ?? '') !== 'leased') {
      return FALSE;
    }
    $expiresAt = (int) ($record['lease_expires_at'] ?? 0);
    return $expiresAt > 0 && $expiresAt <= $now;
  }

  /**
   * Reclaims an expired lease so normal reap behavior can stop the instance.
   *
   * @param array<string,mixed> $record
   *   Pool record.
   * @param int $now
   *   Current unix timestamp.
   *
   * @return array<string,mixed>
   *   Updated reclaimed record.
   */
  private function reclaimExpiredLease(array $record, int $now): array {
    $record['lease_status'] = 'available';
    $record['last_error'] = '';
    $record['last_seen_at'] = $now;
    $record['last_used_at'] = $now;
    $this->clearLeaseMetadata($record);
    $this->poolRepository->save($record);
    $contractId = trim((string) ($record['contract_id'] ?? ''));
    if ($contractId !== '') {
      $this->clearActivePoolContractIfMatches($contractId);
    }
    return $record;
  }

  /**
   * Removes a single record from the pool inventory.
   */
  public function remove(string $contractId): void {
    if ($this->poolRepository->get($contractId) === NULL) {
      throw new \RuntimeException('Unknown pooled instance: ' . $contractId);
    }

    $this->poolRepository->delete($contractId);
    $this->clearActivePoolContractIfMatches($contractId);
  }

  /**
   * Destroys a Vast instance and removes it from pool tracking.
   *
   * @return array<string,string>
   *   Operator-facing result row.
   */
  public function destroyAndRemove(string $contractId): array {
    $record = $this->poolRepository->get($contractId);
    if ($record === NULL) {
      throw new \RuntimeException('Unknown pooled instance: ' . $contractId);
    }

    try {
      $this->vastClient->destroyInstance($contractId);
      $this->poolRepository->delete($contractId);
      $this->clearActivePoolContractIfMatches($contractId);
      return [
        'contract_id' => $contractId,
        'action' => 'destroyed',
        'message' => 'Vast instance destroyed and removed from pool.',
      ];
    }
    catch (\Throwable $exception) {
      $record['lease_status'] = 'unavailable';
      $record['last_error'] = $exception->getMessage();
      $record['last_seen_at'] = time();
      $this->poolRepository->save($record);
      throw new \RuntimeException(
        'Failed to destroy Vast instance ' . $contractId . ': ' . $exception->getMessage(),
        0,
        $exception
      );
    }
  }

  /**
   * Clears the entire pool inventory.
   */
  public function clear(): void {
    $this->poolRepository->clear();
    $this->state->delete(self::STATE_ACTIVE_POOL_CONTRACT);
  }

  /**
   * Stops one stale available instance when it is currently running.
   *
   * @param string $contractId
   *   Vast contract ID for the pool record.
   * @param array<string,mixed> $record
   *   Pool record to inspect.
   * @param bool $dryRun
   *   Whether to report the action without stopping the instance.
   *
   * @return array<string,string>
   *   Operator-facing result row.
   */
  private function reapIdleAvailableInstance(string $contractId, array $record, bool $dryRun): array {
    try {
      $info = $this->vastClient->showInstance($contractId);
      $record['last_seen_at'] = time();
      if (!$this->isRunningState($info)) {
        $record['last_error'] = '';
        $this->poolRepository->save($record);
        return [
          'contract_id' => $contractId,
          'action' => 'already_inactive',
          'message' => 'Instance is not running.',
        ];
      }

      if ($dryRun) {
        return [
          'contract_id' => $contractId,
          'action' => 'would_stop',
          'message' => 'Instance is running and past the idle threshold.',
        ];
      }

      $this->instanceLifecycleClient->stopInstance($contractId);
      $record['last_stopped_at'] = time();
      $record['last_error'] = '';
      $this->poolRepository->save($record);
      return [
        'contract_id' => $contractId,
        'action' => 'stopped',
        'message' => 'Idle running instance was stopped.',
      ];
    }
    catch (\Throwable $exception) {
      $record['lease_status'] = 'unavailable';
      $record['last_seen_at'] = time();
      $record['last_error'] = $exception->getMessage();
      $this->poolRepository->save($record);
      return [
        'contract_id' => $contractId,
        'action' => 'failed',
        'message' => $exception->getMessage(),
      ];
    }
  }

  /**
   * Attempts to acquire a pooled record already known to the repository.
   *
   * @param array<string,mixed> $record
   *   Candidate pool record.
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   * @param int $bootstrapTimeoutSeconds
   *   SSH bootstrap timeout used for this acquire attempt.
   * @param int $workloadTimeoutSeconds
   *   Workload readiness timeout used for this acquire attempt.
   *
   * @return array<string,mixed>|null
   *   Updated record or NULL when the candidate should be skipped.
   */
  private function tryAcquireExistingInstance(
    array $record,
    array $definition,
    int $bootstrapTimeoutSeconds,
    int $workloadTimeoutSeconds,
  ): ?array {
    $contractId = trim((string) ($record['contract_id'] ?? ''));
    if ($contractId === '') {
      return NULL;
    }
    $wakeAttempted = FALSE;

    try {
      $info = $this->vastClient->showInstance($contractId);
    }
    catch (\Throwable $exception) {
      $record['lease_status'] = 'unavailable';
      $record['last_error'] = $exception->getMessage();
      $record['last_seen_at'] = time();
      $this->poolRepository->save($record);
      return NULL;
    }

    if ($this->isRunningState($info)) {
      $workloadStartIssued = FALSE;
      try {
        if (($record['current_workload_mode'] ?? '') !== ($definition['mode'] ?? '')) {
          $this->runtimeManager->stopWorkload($info);
          $this->runtimeManager->startWorkload($info, $definition);
          $workloadStartIssued = TRUE;
        }

        $readyInfo = $this->runtimeManager->waitForWorkloadReady($contractId, $workloadTimeoutSeconds);
        return $this->markLeased($record, $readyInfo, $definition);
      }
      catch (\Throwable $exception) {
        if ($this->shouldKeepInstanceBootstrapping($exception, TRUE, $workloadStartIssued)) {
          $record['lease_status'] = 'bootstrapping';
          $record['last_error'] = $exception->getMessage();
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
          throw new AcquirePendingException(
            'Pooled instance ' . $contractId . ' is still bootstrapping.',
            0,
            $exception
          );
        }

        $record['lease_status'] = 'unavailable';
        $record['last_error'] = $exception->getMessage();
        $record['last_seen_at'] = time();
        $this->poolRepository->save($record);
        return NULL;
      }
    }

    try {
      $wakeAttempted = TRUE;
      $bootstrapCompleted = FALSE;
      $workloadStartIssued = FALSE;
      $startResponse = $this->instanceLifecycleClient->startInstance($contractId);
      if ($this->isQueuedExternalLeaseResponse($startResponse)) {
        $record['lease_status'] = 'rented_elsewhere';
        $record['last_error'] = trim((string) ($startResponse['msg'] ?? 'Wake attempt was queued because required resources are unavailable.'));
        $record['last_seen_at'] = time();
        $this->poolRepository->save($record);
        return NULL;
      }

      if ($this->isWakeBlockedByExternalLease($contractId)) {
        $this->instanceLifecycleClient->stopInstance($contractId);
        $record['lease_status'] = 'rented_elsewhere';
        $record['last_error'] = 'Wake attempt stayed in scheduling; instance is likely rented by another user.';
        $record['last_seen_at'] = time();
        $this->poolRepository->save($record);
        return NULL;
      }

      $bootInfo = $this->runtimeManager->waitForSshBootstrap($contractId, $bootstrapTimeoutSeconds);
      $bootstrapCompleted = TRUE;
      $this->runtimeManager->startWorkload($bootInfo, $definition);
      $workloadStartIssued = TRUE;
      $readyInfo = $this->runtimeManager->waitForWorkloadReady($contractId, $workloadTimeoutSeconds);
      return $this->markLeased($record, $readyInfo, $definition);
    }
    catch (\Throwable $exception) {
      if ($this->shouldKeepInstanceBootstrapping($exception, $bootstrapCompleted, $workloadStartIssued)) {
        $record['lease_status'] = 'bootstrapping';
        $record['last_error'] = $exception->getMessage();
        $record['last_seen_at'] = time();
        $this->poolRepository->save($record);
        throw new AcquirePendingException(
          'Pooled instance ' . $contractId . ' is still bootstrapping.',
          0,
          $exception
        );
      }
      if ($wakeAttempted && !$bootstrapCompleted && !$workloadStartIssued) {
        try {
          $this->instanceLifecycleClient->stopInstance($contractId);
          $record['last_stopped_at'] = time();
        }
        catch (\Throwable) {
          // Preserve original startup failure below.
        }
      }
      $record['lease_status'] = $this->isExternalLeaseFailure($exception->getMessage()) ? 'rented_elsewhere' : 'unavailable';
      $record['last_error'] = $exception->getMessage();
      $record['last_seen_at'] = time();
      $this->poolRepository->save($record);
      if ($wakeAttempted && !$bootstrapCompleted && !$workloadStartIssued) {
        throw new \RuntimeException(
          'Wake/start failed for pooled instance '
          . $contractId
          . '; aborting acquire to avoid duplicate provisioning: '
          . $exception->getMessage(),
          0,
          $exception
        );
      }
      return NULL;
    }
  }

  /**
   * Provisions a fresh pooled instance as the last-resort fallback.
   *
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   * @param int $bootstrapTimeoutSeconds
   *   SSH bootstrap timeout used for this acquire attempt.
   * @param int $workloadTimeoutSeconds
   *   Workload readiness timeout used for this acquire attempt.
   *
   * @return array<string,mixed>
   *   Fresh leased pool record.
   */
  private function acquireFreshInstance(array $definition, int $bootstrapTimeoutSeconds, int $workloadTimeoutSeconds): array {
    $image = $this->workloadCatalog->getDefaultGenericImage();
    $fresh = $this->runtimeManager->provisionFresh($definition, $image);
    $contractId = (string) ($fresh['contract_id'] ?? '');
    $instanceInfo = (array) ($fresh['instance_info'] ?? []);
    if ($contractId === '') {
      throw new \RuntimeException('Fresh provisioning did not return a contract ID.');
    }

    $record = [
      'contract_id' => $contractId,
      'image' => $image,
      'current_workload_mode' => (string) ($definition['mode'] ?? ''),
      'current_model' => (string) ($definition['model'] ?? ''),
      'lease_status' => 'bootstrapping',
      'host' => trim((string) ($instanceInfo['public_ipaddr'] ?? '')),
      'port' => $this->extractPublicPort($instanceInfo),
      'url' => $this->buildPublicUrl($instanceInfo),
      'source' => 'fresh_fallback',
      'last_seen_at' => time(),
      'last_used_at' => time(),
      'last_error' => '',
    ];
    $this->poolRepository->save($record);

    try {
      $bootstrapCompleted = FALSE;
      $workloadStartIssued = FALSE;
      $bootInfo = $this->runtimeManager->waitForSshBootstrap($contractId, $bootstrapTimeoutSeconds);
      $bootstrapCompleted = TRUE;
      $this->runtimeManager->startWorkload($bootInfo, $definition);
      $workloadStartIssued = TRUE;
      $readyInfo = $this->runtimeManager->waitForWorkloadReady($contractId, $workloadTimeoutSeconds);
      return $this->markLeased($record, $readyInfo, $definition);
    }
    catch (\Throwable $exception) {
      if ($this->shouldKeepInstanceBootstrapping($exception, $bootstrapCompleted, $workloadStartIssued)) {
        $record['lease_status'] = 'bootstrapping';
        $record['last_error'] = $exception->getMessage();
        $record['last_seen_at'] = time();
        $this->poolRepository->save($record);
        throw new AcquirePendingException(
          'Fresh contract ' . $contractId . ' is still bootstrapping.',
          0,
          $exception
        );
      }

      $record['lease_status'] = 'unavailable';
      $record['last_error'] = $exception->getMessage();
      $record['last_seen_at'] = time();
      $this->poolRepository->save($record);

      try {
        if (!$workloadStartIssued) {
          $this->vastClient->destroyInstance($contractId);
          $record['lease_status'] = 'destroyed';
          $record['last_error'] = 'Fresh fallback startup failed and contract was destroyed: ' . $exception->getMessage();
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
        }
      }
      catch (\Throwable) {
        // Ignore cleanup failures. Preserve the original startup exception.
      }

      throw new \RuntimeException(
        'Fresh fallback contract ' . $contractId . ' failed workload startup'
        . (!$workloadStartIssued ? ' and was destroyed' : '')
        . ': '
        . $exception->getMessage(),
        0,
        $exception
      );
    }
  }

  /**
   * Keeps bootstrapping instances alive when failure is still pending warmup.
   */
  private function shouldKeepInstanceBootstrapping(
    \Throwable $exception,
    bool $bootstrapCompleted,
    bool $workloadStartIssued,
  ): bool {
    return $this->isPendingStartupFailure($exception)
      || (($bootstrapCompleted || $workloadStartIssued) && $this->isWarmupLikeFailure($exception));
  }

  /**
   * Detects "still warming/bootstrapping" failures that should be retried.
   */
  private function isPendingStartupFailure(\Throwable $exception): bool {
    if ($exception instanceof AcquirePendingException) {
      return TRUE;
    }

    if ($exception instanceof WorkloadReadinessException) {
      $failureClass = $exception->getFailureClass();
      if ($failureClass === FailureClass::WARMUP || $failureClass === FailureClass::UNKNOWN) {
        return TRUE;
      }
    }

    $message = strtolower($exception->getMessage());
    foreach ([
      'bootstrap timeout',
      'absolute safety timeout',
      'stalled before ssh bootstrap',
      'connection refused',
      'failed to connect',
      'workload not ready',
      'warmup',
      'timed out',
      'operation timed out',
    ] as $needle) {
      if (str_contains($message, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Detects startup failures that are still warmup-like.
   */
  private function isWarmupLikeFailure(\Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    foreach ([
      'actual_status=exited',
      'success, running',
      'connection refused',
      'failed to connect',
      'workload not ready',
      'warmup',
      'timed out',
      'operation timed out',
    ] as $needle) {
      if (str_contains($message, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Marks a pool record as leased with refreshed runtime metadata.
   *
   * @param array<string,mixed> $record
   *   Existing pool record.
   * @param array<string,mixed> $readyInfo
   *   Ready instance metadata.
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   *
   * @return array<string,mixed>
   *   Updated record.
   */
  private function markLeased(array $record, array $readyInfo, array $definition): array {
    $now = time();
    $ttl = $this->getLeaseTtlSeconds();
    $record['current_workload_mode'] = (string) ($definition['mode'] ?? '');
    $record['current_model'] = (string) ($definition['model'] ?? '');
    $record['lease_status'] = 'leased';
    $record['lease_token'] = $this->generateLeaseToken();
    $record['leased_at'] = $now;
    $record['last_heartbeat_at'] = $now;
    $record['lease_expires_at'] = $now + $ttl;
    $record['host'] = trim((string) ($readyInfo['public_ipaddr'] ?? ''));
    $record['port'] = $this->extractPublicPort($readyInfo);
    $record['url'] = $this->buildPublicUrl($readyInfo);
    $record['last_seen_at'] = $now;
    $record['last_used_at'] = $now;
    $record['last_error'] = '';
    $this->poolRepository->save($record);
    $contractId = trim((string) ($record['contract_id'] ?? ''));
    if ($contractId !== '') {
      $this->state->set(self::STATE_ACTIVE_POOL_CONTRACT, $contractId);
    }
    return $record;
  }

  /**
   * Sorts candidates by the desired lease preference order.
   *
   * @param array<string, array<string,mixed>> $records
   *   Pool records keyed by contract ID.
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   *
   * @return array<int, array<string,mixed>>
   *   Sorted candidate list.
   */
  private function sortCandidates(array $records, array $definition): array {
    $candidates = array_values($records);
    usort($candidates, function (array $left, array $right) use ($definition): int {
      return $this->candidatePriority($left, $definition) <=> $this->candidatePriority($right, $definition);
    });
    return $candidates;
  }

  /**
   * Calculates candidate priority for pool acquisition.
   *
   * Lower values are preferred.
   *
   * @param array<string,mixed> $record
   *   Pool record.
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   */
  private function candidatePriority(array $record, array $definition): int {
    if (($record['lease_status'] ?? '') === 'leased') {
      return 99;
    }

    if (($record['current_workload_mode'] ?? '') === ($definition['mode'] ?? '')) {
      return 0;
    }

    if (($record['lease_status'] ?? '') === 'available') {
      return 10;
    }

    if (($record['lease_status'] ?? '') === 'rented_elsewhere') {
      return 50;
    }

    return 20;
  }

  /**
   * Detects whether an instance is already in a running state.
   *
   * @param array<string,mixed> $info
   *   Vast instance metadata.
   */
  private function isRunningState(array $info): bool {
    return (string) ($info['cur_state'] ?? '') === 'running'
      && (string) ($info['actual_status'] ?? '') === 'running';
  }

  /**
   * Detects whether a wake attempt is blocked by an external renter.
   */
  private function isWakeBlockedByExternalLease(string $contractId): bool {
    for ($index = 0; $index < $this->wakeSchedulingProbeAttempts; $index++) {
      if ($this->wakeSchedulingProbeDelaySeconds > 0) {
        sleep($this->wakeSchedulingProbeDelaySeconds);
      }
      $info = $this->vastClient->showInstance($contractId);
      if ($this->isRunningState($info)) {
        return FALSE;
      }

      $actualStatus = (string) ($info['actual_status'] ?? '');
      if ($actualStatus !== 'scheduling') {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Detects error text that implies the sleeping instance is rented elsewhere.
   */
  private function isExternalLeaseFailure(string $message): bool {
    $needles = [
      'rented',
      'scheduling',
      'not available',
      'unable to start instance',
    ];

    foreach ($needles as $needle) {
      if (stripos($message, $needle) !== FALSE) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Detects a direct Vast wake response from an externally rented instance.
   *
   * Some sleeping Vast instances do not enter the later "scheduling" phase.
   * Instead, Vast replies immediately that resources are unavailable and the
   * state change has been queued. Treat that as rented elsewhere.
   *
   * @param array<string,mixed> $response
   *   Vast state-change response payload.
   */
  private function isQueuedExternalLeaseResponse(array $response): bool {
    $error = trim((string) ($response['error'] ?? ''));
    $message = trim((string) ($response['msg'] ?? ''));

    return $error === 'resources_unavailable'
      || $this->isExternalLeaseFailure($message);
  }

  /**
   * Extracts the public port mapped to the vLLM HTTP server.
   *
   * @param array<string,mixed> $info
   *   Vast instance metadata.
   */
  private function extractPublicPort(array $info): string {
    $ports = $info['ports'] ?? [];
    if (!is_array($ports)) {
      return '';
    }

    foreach ($ports as $key => $value) {
      if (!str_contains((string) $key, '8000')) {
        continue;
      }

      if (!is_array($value) || !isset($value[0]['HostPort'])) {
        continue;
      }

      return trim((string) $value[0]['HostPort']);
    }

    return '';
  }

  /**
   * Builds the public vLLM URL for a ready instance.
   *
   * @param array<string,mixed> $info
   *   Vast instance metadata.
   */
  private function buildPublicUrl(array $info): string {
    $host = trim((string) ($info['public_ipaddr'] ?? ''));
    $port = $this->extractPublicPort($info);
    return ($host !== '' && $port !== '') ? 'http://' . $host . ':' . $port : '';
  }

  /**
   * Clears lease ownership metadata from a pool record.
   *
   * @param array<string,mixed> $record
   *   Mutable pool record.
   */
  private function clearLeaseMetadata(array &$record): void {
    $record['lease_token'] = '';
    $record['leased_at'] = 0;
    $record['last_heartbeat_at'] = 0;
    $record['lease_expires_at'] = 0;
  }

  /**
   * Generates a cryptographically random lease token.
   */
  private function generateLeaseToken(): string {
    return bin2hex(random_bytes(16));
  }

  /**
   * Clears active pooled lease pointer when it references this contract.
   */
  private function clearActivePoolContractIfMatches(string $contractId): void {
    $activeContract = trim((string) $this->state->get(self::STATE_ACTIVE_POOL_CONTRACT, ''));
    if ($activeContract === $contractId) {
      $this->state->delete(self::STATE_ACTIVE_POOL_CONTRACT);
    }
  }

}
