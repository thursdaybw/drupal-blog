<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\State\StateInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Drupal\compute_orchestrator\Exception\AcquirePendingException;
use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;

/**
 * Provides pooled acquire/release semantics for generic vLLM instances.
 */
final class VllmPoolManager implements VllmPoolLeaseBrokerInterface {

  /**
   * Module logger channel.
   */
  private LoggerInterface $logger;

  private const DEFAULT_IDLE_SHUTDOWN_SECONDS = 600;
  private const DEFAULT_LEASE_TTL_SECONDS = 600;
  private const STATE_ACTIVE_POOL_CONTRACT = 'compute_orchestrator.vllm_pool.active_contract_id';

  private const STATE_IDLE_SHUTDOWN_SECONDS = 'compute_orchestrator.vllm_pool.idle_shutdown_seconds';
  private const STATE_LEASE_TTL_SECONDS = 'compute_orchestrator.vllm_pool.lease_ttl_seconds';
  private const STATE_MAX_INSTANCES_PER_WORKLOAD = 'compute_orchestrator.vllm_pool.max_instances_per_workload';
  private const DEFAULT_MAX_INSTANCES_PER_WORKLOAD = 5;
  private const STATE_WORKLOAD_READY_TIMEOUT_SECONDS = 'compute_orchestrator.vllm_pool.workload_ready_timeout_seconds';
  private const STATE_VAST_STATE_CHANGE_SPACING_SECONDS = 'compute_orchestrator.vast.state_change_spacing_seconds';
  // vLLM cold starts (especially first-time model loads) frequently exceed
  // 5 minutes. Keep the default aligned with the vLLM readiness adapter's
  // declared startup timeout.
  private const DEFAULT_WORKLOAD_READY_TIMEOUT_SECONDS = 900;

  public function __construct(
    private readonly VllmPoolRepositoryInterface $poolRepository,
    private readonly VllmWorkloadCatalogInterface $workloadCatalog,
    private readonly GenericVllmRuntimeManagerInterface $runtimeManager,
    private readonly VastInstanceLifecycleClientInterface $instanceLifecycleClient,
    private readonly VastRestClientInterface $vastClient,
    private readonly StateInterface $state,
    private readonly BadHostRegistry $badHostRegistry,
    private readonly int $wakeSchedulingProbeAttempts = 3,
    private readonly int $wakeSchedulingProbeDelaySeconds = 10,
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
      'host_id' => trim((string) ($info['host_id'] ?? '')),
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
    $this->logger->notice('POOL acquire begin workload={workload} allow_fresh={allow_fresh}', [
      'workload' => $workload,
      'allow_fresh' => $allowFresh ? 'yes' : 'no',
    ]);

    foreach ($this->sortCandidates($this->poolRepository->all(), $definition) as $record) {
      if (($record['lease_status'] ?? '') === 'destroyed') {
        continue;
      }
      if (in_array(($record['lease_status'] ?? ''), ['unavailable', 'rented_elsewhere'], TRUE)) {
        $record['lease_status'] = 'available';
      }
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
      throw new \RuntimeException('No pooled instances available. Fresh provisioning is disabled.');
    }

    $matchingPoolSize = $this->countMatchingPoolMembers($definition);
    $maxInstances = $this->getMaxInstancesPerWorkload();
    if ($maxInstances > 0 && $matchingPoolSize >= $maxInstances) {
      throw new \RuntimeException(
        'No pooled capacity available for '
        . (string) ($definition['mode'] ?? $workload)
        . ': matching pool size limit '
        . $maxInstances
        . ' reached.'
      );
    }

    return $this->acquireFreshInstance($definition, $bootstrapTimeout, $workloadTimeout);
  }

  /**
   * Returns the configured max instances per workload, or 0 when unlimited.
   */
  public function getMaxInstancesPerWorkload(): int {
    return max(0, (int) $this->state->get(self::STATE_MAX_INSTANCES_PER_WORKLOAD, self::DEFAULT_MAX_INSTANCES_PER_WORKLOAD));
  }

  /**
   * Counts tracked pool members that already match the requested workload.
   *
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   */
  private function countMatchingPoolMembers(array $definition): int {
    $count = 0;
    foreach ($this->poolRepository->all() as $record) {
      if (!is_array($record)) {
        continue;
      }
      if ((string) ($record['current_workload_mode'] ?? '') !== (string) ($definition['mode'] ?? '')) {
        continue;
      }
      $leaseStatus = (string) ($record['lease_status'] ?? '');
      if (in_array($leaseStatus, ['destroyed', 'rented_elsewhere', 'unavailable'], TRUE)) {
        continue;
      }
      $count++;
    }

    return $count;
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

    $leaseExpiresAt = (int) ($record['lease_expires_at'] ?? 0);
    if ($leaseExpiresAt === 0 || $leaseExpiresAt > time()) {
      return NULL;
    }

    $this->logger->notice('POOL acquire candidate contract={contract} lease_status={lease_status} workload={workload}', [
      'contract' => $contractId,
      'lease_status' => (string) ($record['lease_status'] ?? ''),
      'workload' => (string) ($record['current_workload_mode'] ?? ''),
    ]);
    $phase = 'show_instance';
    $action = 'fetch Vast instance state';

    try {
      $info = $this->vastClient->showInstance($contractId);
    }
    catch (\Throwable $exception) {
      $this->recordAcquireFailure($record, 'unavailable', $phase, $action, $exception);
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
  public function getWorkloadReadyTimeoutSeconds(): int {
    $configured = (int) $this->state->get(
      self::STATE_WORKLOAD_READY_TIMEOUT_SECONDS,
      self::DEFAULT_WORKLOAD_READY_TIMEOUT_SECONDS,
    );
    return max(60, $configured);
  }

  /**
   * Reconciles tracked pool records against real Vast instance state.
   *
   * @return array<int,array<string,string>>
   *   Operator-facing reconciliation rows.
   */
  public function reconcile(bool $dryRun = FALSE): array {
    $results = [];
    foreach ($this->poolRepository->all() as $contractId => $record) {
      if (!is_array($record)) {
        continue;
      }

      try {
        $info = $this->vastClient->showInstance((string) $contractId);
      }
      catch (\Throwable $exception) {
        $results[] = [
          'contract_id' => (string) $contractId,
          'action' => 'inspect_failed',
          'message' => $exception->getMessage(),
        ];
        continue;
      }

      $runtimeState = $this->deriveRuntimeState($info);
      $updated = $record;
      $updated['runtime_state'] = $runtimeState;
      $updated['host'] = trim((string) ($info['public_ipaddr'] ?? ($updated['host'] ?? '')));
      $updated['port'] = $this->extractPublicPort($info);
      $updated['url'] = $this->buildPublicUrl($info);
      $updated['last_seen_at'] = time();

      $action = 'unchanged';
      $message = 'Record already matched live Vast state.';

      if ($this->shouldNormalizeToAvailable($record, $runtimeState)) {
        $updated['lease_status'] = 'available';
        $updated['last_error'] = '';
        $updated['last_phase'] = 'state_reconcile';
        $updated['last_action'] = 'normalize_available';
        if (
          (string) ($record['lease_status'] ?? '') !== 'available'
          || (string) ($record['runtime_state'] ?? '') !== $runtimeState
          || trim((string) ($record['last_error'] ?? '')) !== ''
        ) {
          $action = 'normalized_available';
          $message = 'Stopped or running instance had no active lease and was restored to available.';
        }
      }

      if ($runtimeState === 'destroyed' && (string) ($updated['lease_status'] ?? '') !== 'destroyed') {
        $updated['lease_status'] = 'destroyed';
        $updated['last_phase'] = 'state_reconcile';
        $updated['last_action'] = 'mark_destroyed';
        $action = 'marked_destroyed';
        $message = 'Tracked record points at a destroyed instance.';
      }

      if ($action !== 'unchanged' && !$dryRun) {
        $this->poolRepository->save($updated);
      }
      elseif ($action === 'unchanged' && $updated != $record && !$dryRun) {
        $this->poolRepository->save($updated);
      }

      if ($dryRun && $action !== 'unchanged') {
        $action = 'would_' . $action;
      }

      $results[] = [
        'contract_id' => (string) $contractId,
        'action' => $action,
        'message' => $message,
      ];
    }

    return $results;
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
      if ($lastUsedAt <= 0) {
        continue;
      }
      if ($threshold > 0 && ($now - $lastUsedAt) < $threshold) {
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
        return $this->recordIdleReapAlreadyInactive($contractId, $record);
      }

      if ($dryRun) {
        return [
          'contract_id' => $contractId,
          'action' => 'would_stop',
          'message' => 'Instance is running and past the idle threshold.',
        ];
      }

      $this->stopRunningInstanceForIdleReap($contractId);
      return $this->recordIdleReapStopped($contractId, $record);
    }
    catch (\Throwable $exception) {
      return $this->recordIdleReapFailure($contractId, $record, $exception);
    }
  }

  /**
   * Records a reaped instance that Vast already reports as inactive.
   *
   * @param string $contractId
   *   Vast contract ID for the pool record.
   * @param array<string,mixed> $record
   *   Pool record to update.
   *
   * @return array<string,string>
   *   Operator-facing result row.
   */
  private function recordIdleReapAlreadyInactive(string $contractId, array $record): array {
    $record['runtime_state'] = 'stopped';
    $record['last_phase'] = 'idle_reap';
    $record['last_action'] = 'already_inactive';
    $record['last_reap_at'] = time();
    $record['last_error'] = '';
    $this->poolRepository->save($record);

    return [
      'contract_id' => $contractId,
      'action' => 'already_inactive',
      'message' => 'Instance is not running.',
    ];
  }

  /**
   * Records a reaped instance after Vast accepts the stop request.
   *
   * @param string $contractId
   *   Vast contract ID for the pool record.
   * @param array<string,mixed> $record
   *   Pool record to update.
   *
   * @return array<string,string>
   *   Operator-facing result row.
   */
  private function recordIdleReapStopped(string $contractId, array $record): array {
    $now = time();
    $record['runtime_state'] = 'stopped';
    $record['last_phase'] = 'idle_reap';
    $record['last_action'] = 'stopped';
    $record['last_stopped_at'] = $now;
    $record['last_reap_at'] = $now;
    $record['last_error'] = '';
    $this->poolRepository->save($record);

    return [
      'contract_id' => $contractId,
      'action' => 'stopped',
      'message' => 'Idle running instance was stopped.',
    ];
  }

  /**
   * Records a failed idle reap without hiding retryable live instances.
   *
   * A Vast 429 means the stop request was throttled, not that the instance is
   * unusable. Keep the record available and running so the next cron pass still
   * sees an expensive live instance that needs to be stopped. Non-rate-limit
   * failures are marked unavailable because their live state is less certain.
   *
   * @param string $contractId
   *   Vast contract ID for the pool record.
   * @param array<string,mixed> $record
   *   Pool record to update.
   * @param \Throwable $exception
   *   Failure raised while reading or stopping the Vast instance.
   *
   * @return array<string,string>
   *   Operator-facing result row.
   */
  private function recordIdleReapFailure(string $contractId, array $record, \Throwable $exception): array {
    $record['lease_status'] = $this->isVastRateLimitFailure($exception) ? 'available' : 'unavailable';
    $record['runtime_state'] = 'running';
    $record['last_seen_at'] = time();
    $record['last_phase'] = 'idle_reap';
    $record['last_action'] = 'failed';
    $record['last_error'] = $exception->getMessage();
    $this->poolRepository->save($record);

    return [
      'contract_id' => $contractId,
      'action' => 'failed',
      'message' => $exception->getMessage(),
    ];
  }

  /**
   * Stops a running Vast instance during idle reap.
   *
   * Vast applies a tight rate limit to instance state changes. The reaper has
   * just called showInstance() to avoid stopping an already-inactive instance;
   * sending the PUT state change immediately afterwards can exceed Vast's
   * per-instance endpoint threshold. Space the state change out, and retry 429s
   * with a small backoff, because leaving a paid instance running is worse than
   * making cron wait a few seconds.
   */
  private function stopRunningInstanceForIdleReap(string $contractId): void {
    $maxAttempts = 4;
    $lastException = NULL;
    $spacingSeconds = $this->getVastStateChangeSpacingSeconds();

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      $this->waitBeforeVastStateChange($spacingSeconds);
      try {
        $this->instanceLifecycleClient->stopInstance($contractId);
        return;
      }
      catch (\Throwable $exception) {
        $lastException = $exception;
        if (!$this->shouldRetryVastStateChange($exception, $attempt, $maxAttempts)) {
          throw $exception;
        }
        $this->waitAfterVastRateLimit($attempt, $spacingSeconds);
      }
    }

    if ($lastException instanceof \Throwable) {
      throw $lastException;
    }
  }

  /**
   * Waits between the Vast readback and the subsequent state-change request.
   */
  private function waitBeforeVastStateChange(int $spacingSeconds): void {
    if ($spacingSeconds > 0) {
      sleep($spacingSeconds);
    }
  }

  /**
   * Returns TRUE when another Vast state-change attempt is worthwhile.
   */
  private function shouldRetryVastStateChange(\Throwable $exception, int $attempt, int $maxAttempts): bool {
    return $this->isVastRateLimitFailure($exception) && $attempt < $maxAttempts;
  }

  /**
   * Waits after a Vast 429 before retrying the state-change request.
   */
  private function waitAfterVastRateLimit(int $attempt, int $spacingSeconds): void {
    sleep(max($spacingSeconds, $attempt));
  }

  /**
   * Returns TRUE when a Vast API failure is a retryable rate limit.
   */
  private function isVastRateLimitFailure(\Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    return str_contains($message, '429')
      || str_contains($message, 'too many requests')
      || str_contains($message, 'api requests too frequent');
  }

  /**
   * Returns the minimum spacing before Vast state-change requests.
   *
   * This is state-backed so staging can increase the spacing without a deploy
   * if Vast tightens its endpoint thresholds again.
   */
  private function getVastStateChangeSpacingSeconds(): int {
    return max(0, (int) $this->state->get(self::STATE_VAST_STATE_CHANGE_SPACING_SECONDS, 2));
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
    $phase = 'provider_readback';
    $action = 'show Vast instance';

    try {
      $info = $this->vastClient->showInstance($contractId);
    }
    catch (\Throwable $exception) {
      $this->recordTransientAcquireObservation($record, $phase, $action, $exception->getMessage());
      return NULL;
    }

    if ($this->isRunningState($info)) {
      $workloadStartIssued = FALSE;
      $phase = 'workload_ready_probe';
      $action = 'probe /v1/models (skip start; mode already matched)';
      try {
        if (($record['current_workload_mode'] ?? '') !== ($definition['mode'] ?? '')) {
          $phase = 'stop_workload';
          $action = 'stop existing workload (mode change)';
          $this->runtimeManager->stopWorkload($info);
          $phase = 'start_workload';
          $action = 'start workload (mode change)';
          $this->runtimeManager->startWorkload($info, $definition);
          $workloadStartIssued = TRUE;
        }

        $phase = 'workload_ready_probe';
        $action = $workloadStartIssued
          ? 'probe /v1/models (after start)'
          : 'probe /v1/models (skip start; mode already matched)';
        $readyInfo = $this->runtimeManager->waitForWorkloadReady($contractId, $workloadTimeoutSeconds);
        return $this->markLeased($record, $readyInfo, $definition);
      }
      catch (\Throwable $exception) {
        if ($this->shouldKeepInstanceBootstrapping($exception, TRUE, $workloadStartIssued)) {
          $progress = $this->buildRetryableProgressSnapshot($phase, $action, $exception);
          $status = $this->formatRetryableStatusLine($contractId, $progress);
          $record['lease_status'] = 'bootstrapping';
          $record['last_error'] = $status;
          $record['last_phase'] = $phase;
          $record['last_action'] = $action;
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
          throw AcquirePendingException::fromProgress($status, $contractId, $progress, $exception);
        }

        $this->recordTransientAcquireObservation($record, $phase, $action, $exception->getMessage());
        return NULL;
      }
    }

    try {
      $wakeAttempted = TRUE;
      $bootstrapCompleted = FALSE;
      $workloadStartIssued = FALSE;
      $phase = 'wake_instance';
      $action = 'request Vast start';
      $startResponse = $this->instanceLifecycleClient->startInstance($contractId);
      $record['runtime_state'] = 'starting';
      if ($this->isQueuedExternalLeaseResponse($startResponse)) {
        return $this->handleExternalLeaseWakeFailure(
          $record,
          $contractId,
          trim((string) ($startResponse['msg'] ?? 'Wake attempt was queued because required resources are unavailable.')),
        );
      }

      if ($this->isWakeBlockedByExternalLease($contractId)) {
        return $this->handleExternalLeaseWakeFailure(
          $record,
          $contractId,
          'Wake attempt stayed in scheduling; instance is likely rented by another user.',
        );
      }

      $phase = 'ssh_bootstrap';
      $action = 'wait for SSH bootstrap';
      $bootInfo = $this->runtimeManager->waitForSshBootstrap($contractId, $bootstrapTimeoutSeconds);
      $bootstrapCompleted = TRUE;
      $phase = 'start_workload';
      $action = 'start workload';
      $this->runtimeManager->startWorkload($bootInfo, $definition);
      $workloadStartIssued = TRUE;
      $phase = 'workload_ready_probe';
      $action = 'probe /v1/models (after start)';
      $readyInfo = $this->runtimeManager->waitForWorkloadReady($contractId, $workloadTimeoutSeconds);
      return $this->markLeased($record, $readyInfo, $definition);
    }
    catch (\Throwable $exception) {
      if ($wakeAttempted && !$bootstrapCompleted && !$workloadStartIssued && $this->isWakeRateLimitFailure($exception)) {
        return $this->handleWakeRateLimitFallback($record, $contractId, $exception);
      }
      if ($this->shouldKeepInstanceBootstrapping($exception, $bootstrapCompleted, $workloadStartIssued)) {
        $progress = $this->buildRetryableProgressSnapshot($phase, $action, $exception);
        $status = $this->formatRetryableStatusLine($contractId, $progress);
        $record['lease_status'] = 'bootstrapping';
        $record['runtime_state'] = 'starting';
        $record['last_error'] = $status;
        $record['last_phase'] = $phase;
        $record['last_action'] = $action;
        $record['last_seen_at'] = time();
        $this->poolRepository->save($record);
        throw AcquirePendingException::fromProgress($status, $contractId, $progress, $exception);
      }
      if ($wakeAttempted && !$bootstrapCompleted && !$workloadStartIssued) {
        return $this->handleBootstrapFailureRetry(
          $record,
          $contractId,
          $phase,
          $action,
          $exception,
          $definition,
          $bootstrapTimeoutSeconds,
          $workloadTimeoutSeconds,
        );
      }
      $this->recordTransientAcquireObservation($record, $phase, $action, $exception->getMessage());
      throw new \RuntimeException(
        'Workload readiness failed for pooled instance '
        . $contractId
        . ': '
        . $exception->getMessage(),
        0,
        $exception
      );
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
   * @param int $freshBootstrapRetriesRemaining
   *   Number of fresh bootstrap retries still allowed after cleanup.
   *
   * @return array<string,mixed>
   *   Fresh leased pool record.
   */
  private function acquireFreshInstance(
    array $definition,
    int $bootstrapTimeoutSeconds,
    int $workloadTimeoutSeconds,
    int $freshBootstrapRetriesRemaining = 1,
  ): array {
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
      'host_id' => trim((string) ($instanceInfo['host_id'] ?? '')),
      'port' => $this->extractPublicPort($instanceInfo),
      'url' => $this->buildPublicUrl($instanceInfo),
      'source' => 'fresh_fallback',
      'runtime_state' => 'running',
      'last_seen_at' => time(),
      'last_used_at' => time(),
      'last_error' => '',
    ];
    $this->poolRepository->save($record);

    try {
      $bootstrapCompleted = TRUE;
      $workloadStartIssued = FALSE;
      $phase = 'start_workload';
      $action = 'start workload';
      $bootInfo = $instanceInfo;
      $this->runtimeManager->startWorkload($bootInfo, $definition);
      $workloadStartIssued = TRUE;
      $phase = 'workload_ready_probe';
      $action = 'probe /v1/models (after start)';
      $readyInfo = $this->runtimeManager->waitForWorkloadReady($contractId, $workloadTimeoutSeconds);
      return $this->markLeased($record, $readyInfo, $definition);
    }
    catch (\Throwable $exception) {
      if ($this->shouldKeepInstanceBootstrapping($exception, $bootstrapCompleted, $workloadStartIssued)) {
        $progress = $this->buildRetryableProgressSnapshot($phase, $action, $exception);
        $status = $this->formatRetryableStatusLine($contractId, $progress);
        $record['lease_status'] = 'bootstrapping';
        $record['runtime_state'] = 'starting';
        $record['last_error'] = $status;
        $record['last_phase'] = $phase;
        $record['last_action'] = $action;
        $record['last_seen_at'] = time();
        $this->poolRepository->save($record);
        throw AcquirePendingException::fromProgress($status, $contractId, $progress, $exception);
      }

      if (!$bootstrapCompleted && !$workloadStartIssued && $freshBootstrapRetriesRemaining > 0) {
        return $this->handleBootstrapFailureRetry(
          $record,
          $contractId,
          $phase,
          $action,
          $exception,
          $definition,
          $bootstrapTimeoutSeconds,
          $workloadTimeoutSeconds,
          $freshBootstrapRetriesRemaining - 1,
        );
      }

      if (!$workloadStartIssued) {
        $record = $this->recordFailedFreshHost($record, $contractId, $definition);
      }

      $record['lease_status'] = 'unavailable';
      $record['last_error'] = $exception->getMessage();
      $record['last_seen_at'] = time();
      $this->poolRepository->save($record);

      $cleanupMessage = '';
      if (!$workloadStartIssued) {
        $cleanup = $this->destroyFreshFailedContract($contractId, $record);
        $record = $cleanup['record'];
        $cleanupMessage = (string) ($cleanup['message'] ?? '');
      }

      $cleanupSuffix = '';
      if (!$workloadStartIssued && $cleanupMessage !== '') {
        $cleanupSuffix = in_array($cleanupMessage, ['and was destroyed', 'and was already gone'], TRUE)
          ? ' ' . $cleanupMessage
          : ' (' . $cleanupMessage . ')';
      }

      throw new \RuntimeException(
        'Fresh fallback contract ' . $contractId . ' failed workload startup'
        . $cleanupSuffix
        . ': '
        . $exception->getMessage(),
        0,
        $exception
      );
    }
  }

  /**
   * Persists an acquire failure with accurate phase/action metadata.
   *
   * @param array<string,mixed> $record
   *   Pool record.
   * @param string $leaseStatus
   *   Lease status to persist on the record.
   * @param string $phase
   *   Acquire phase that failed.
   * @param string $action
   *   Acquire action that failed.
   * @param \Throwable $exception
   *   Failure that occurred.
   */
  private function recordAcquireFailure(
    array &$record,
    string $leaseStatus,
    string $phase,
    string $action,
    \Throwable $exception,
  ): void {
    $record['lease_status'] = $leaseStatus;
    $record['last_error'] = $exception->getMessage();
    $record['last_phase'] = $phase;
    $record['last_action'] = $action;
    $record['last_seen_at'] = time();
    $this->poolRepository->save($record);
    $this->logger->error(
      'POOL acquire failure contract={contract} lease_status={lease_status} phase={phase} action={action} message={message}',
      [
        'contract' => (string) ($record['contract_id'] ?? ''),
        'lease_status' => $leaseStatus,
        'phase' => $phase,
        'action' => $action,
        'message' => $exception->getMessage(),
      ],
    );
  }

  /**
   * Records a transient provider observation without tombstoning the candidate.
   *
   * @param array<string,mixed> $record
   *   Pool record.
   * @param string $phase
   *   Acquire phase that observed the transient provider condition.
   * @param string $action
   *   Acquire action that observed the transient provider condition.
   * @param string $message
   *   Operator-facing diagnostic message.
   */
  private function recordTransientAcquireObservation(
    array &$record,
    string $phase,
    string $action,
    string $message,
  ): void {
    $record['lease_status'] = 'available';
    $record['last_error'] = $message;
    $record['last_phase'] = $phase;
    $record['last_action'] = $action;
    $record['last_seen_at'] = time();
    $record['last_provider_unavailable_at'] = time();
    $this->poolRepository->save($record);
    $this->logger->warning(
      'POOL transient provider observation contract={contract} phase={phase} action={action} message={message}',
      [
        'contract' => (string) ($record['contract_id'] ?? ''),
        'phase' => $phase,
        'action' => $action,
        'message' => $message,
      ],
    );
  }

  /**
   * Cleans up a bootstrap-failed instance, records bad-host data, and retries.
   *
   * @param array<string,mixed> $record
   *   Failed pool record.
   * @param string $contractId
   *   Vast contract ID.
   * @param string $phase
   *   Acquire phase that failed.
   * @param string $action
   *   Acquire action that failed.
   * @param \Throwable $exception
   *   Bootstrap failure.
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   * @param int $bootstrapTimeoutSeconds
   *   SSH bootstrap timeout in seconds for the replacement acquire.
   * @param int $workloadTimeoutSeconds
   *   Workload readiness timeout in seconds for the replacement acquire.
   * @param int $freshBootstrapRetriesRemaining
   *   Number of additional fresh bootstrap retries after this cleanup.
   *
   * @return array<string,mixed>
   *   Replacement acquired record.
   */
  private function handleBootstrapFailureRetry(
    array $record,
    string $contractId,
    string $phase,
    string $action,
    \Throwable $exception,
    array $definition,
    int $bootstrapTimeoutSeconds,
    int $workloadTimeoutSeconds,
    int $freshBootstrapRetriesRemaining = 1,
  ): array {
    try {
      $this->instanceLifecycleClient->stopInstance($contractId);
      $record['runtime_state'] = 'stopped';
      $record['last_stopped_at'] = time();
    }
    catch (\Throwable) {
      // Preserve original bootstrap failure handling below.
    }

    $record = $this->recordFailedFreshHost($record, $contractId, $definition);

    $this->recordAcquireFailure($record, 'unavailable', $phase, $action, $exception);
    $cleanup = $this->destroyFreshFailedContract($contractId, $record);
    $record = $cleanup['record'];
    $record['last_error'] = $exception->getMessage() . ' Cleanup: ' . $cleanup['message'];
    $record['last_seen_at'] = time();
    $this->poolRepository->save($record);

    return $this->acquireFreshInstance(
      $definition,
      $bootstrapTimeoutSeconds,
      $workloadTimeoutSeconds,
      $freshBootstrapRetriesRemaining,
    );
  }

  /**
   * Records the failed fresh-fallback host so later offers can avoid it.
   *
   * @param array<string,mixed> $record
   *   Failed pool record.
   * @param string $contractId
   *   Vast contract ID.
   * @param array<string,int|string> $definition
   *   Requested workload definition.
   *
   * @return array<string,mixed>
   *   Updated pool record.
   */
  private function recordFailedFreshHost(array $record, string $contractId, array $definition): array {
    $hostId = trim((string) ($this->extractHostIdFromRecordOrProvider($record, $contractId) ?? ''));
    if ($hostId === '') {
      return $record;
    }

    $this->badHostRegistry->add($hostId);
    $this->recordWorkloadBadHost((string) ($definition['mode'] ?? ''), $hostId);
    $this->recordGlobalBadHost($hostId);
    $record['bad_host_id'] = $hostId;

    return $record;
  }

  /**
   * Extracts a host ID from the record or current provider readback.
   */
  private function extractHostIdFromRecordOrProvider(array $record, string $contractId): ?string {
    $hostId = trim((string) ($record['host_id'] ?? ''));
    if ($hostId !== '') {
      return $hostId;
    }

    try {
      $info = $this->vastClient->showInstance($contractId);
      $providerHostId = trim((string) ($info['host_id'] ?? ''));
      if ($providerHostId !== '') {
        return $providerHostId;
      }
    }
    catch (\Throwable) {
      // Ignore provider readback failure here; cleanup continues.
    }

    return NULL;
  }

  /**
   * Records a host as bad for all workloads.
   */
  private function recordGlobalBadHost(string $hostId): void {
    $current = $this->state->get('compute_orchestrator.global_bad_hosts', []);
    if (!is_array($current)) {
      $current = [];
    }
    if (!in_array($hostId, $current, TRUE)) {
      $current[] = $hostId;
      $this->state->set('compute_orchestrator.global_bad_hosts', array_values(array_unique(array_map('strval', $current))));
    }
  }

  /**
   * Records a host as bad for the specific workload.
   */
  private function recordWorkloadBadHost(string $workload, string $hostId): void {
    $workload = trim($workload);
    if ($workload === '') {
      return;
    }
    $all = $this->state->get('compute_orchestrator.workload_bad_hosts', []);
    if (!is_array($all)) {
      $all = [];
    }
    $current = $all[$workload] ?? [];
    if (!is_array($current)) {
      $current = [];
    }
    if (!in_array($hostId, $current, TRUE)) {
      $current[] = $hostId;
      $all[$workload] = array_values(array_unique(array_map('strval', $current)));
      $this->state->set('compute_orchestrator.workload_bad_hosts', $all);
    }
  }

  /**
   * Attempts to destroy a failed fresh-fallback contract and verifies cleanup.
   *
   * @param string $contractId
   *   Failed Vast contract ID.
   * @param array<string,mixed> $record
   *   Pool record to update with cleanup state.
   *
   * @return array{record: array<string,mixed>, message: string}
   *   Updated pool record plus a short cleanup outcome summary.
   */
  private function destroyFreshFailedContract(string $contractId, array $record): array {
    $maxAttempts = 3;
    $cleanupError = '';
    $now = time();

    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
      $record['cleanup_attempts'] = $attempt;
      $record['cleanup_last_checked_at'] = $now;

      try {
        $this->vastClient->destroyInstance($contractId);
      }
      catch (\Throwable $cleanupException) {
        $cleanupError = $cleanupException->getMessage();
        if ($this->isAlreadyMissingCleanupFailure($cleanupException)) {
          $record['lease_status'] = 'destroyed';
          $record['runtime_state'] = 'stopped';
          $record['cleanup_status'] = 'destroyed';
          $record['cleanup_error'] = '';
          $record['last_error'] = 'Fresh fallback startup failed and contract was already gone: ' . (string) ($record['last_error'] ?? '');
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
          return [
            'record' => $record,
            'message' => 'and was already gone',
          ];
        }
        if (!$this->isRetryableCleanupFailure($cleanupException) || $attempt === $maxAttempts) {
          $record['cleanup_status'] = 'failed';
          $record['cleanup_error'] = $cleanupError;
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
          return [
            'record' => $record,
            'message' => 'cleanup failed: ' . $cleanupError,
          ];
        }
        continue;
      }

      try {
        $this->vastClient->showInstance($contractId);
        $cleanupError = 'Contract still exists after destroy attempt ' . $attempt . '.';
        if ($attempt === $maxAttempts) {
          $record['cleanup_status'] = 'failed';
          $record['cleanup_error'] = $cleanupError;
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
          return [
            'record' => $record,
            'message' => 'cleanup failed verification: ' . $cleanupError,
          ];
        }
      }
      catch (\Throwable $verificationException) {
        if ($this->isAlreadyMissingCleanupFailure($verificationException)) {
          $record['lease_status'] = 'destroyed';
          $record['cleanup_status'] = 'destroyed';
          $record['cleanup_error'] = '';
          $record['last_error'] = 'Fresh fallback startup failed and contract was destroyed: ' . (string) ($record['last_error'] ?? '');
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
          return [
            'record' => $record,
            'message' => 'and was destroyed',
          ];
        }

        $cleanupError = 'Cleanup verification failed: ' . $verificationException->getMessage();
        if ($attempt === $maxAttempts) {
          $record['cleanup_status'] = 'unknown';
          $record['cleanup_error'] = $cleanupError;
          $record['last_seen_at'] = time();
          $this->poolRepository->save($record);
          return [
            'record' => $record,
            'message' => 'cleanup verification failed',
          ];
        }
      }
    }

    $record['cleanup_status'] = 'failed';
    $record['cleanup_error'] = $cleanupError !== '' ? $cleanupError : 'Cleanup failed for an unknown reason.';
    $record['last_seen_at'] = time();
    $this->poolRepository->save($record);
    return [
      'record' => $record,
      'message' => 'cleanup failed: ' . ($cleanupError !== '' ? $cleanupError : 'unknown cleanup error'),
    ];
  }

  /**
   * Returns TRUE when Vast says the failed instance is already gone.
   */
  private function isAlreadyMissingCleanupFailure(\Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    foreach ([
      'no_such_instance',
      'no such instance',
      'instance not found',
      'instance missing',
      'missing',
      'not found',
      '404',
    ] as $needle) {
      if (str_contains($message, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Returns TRUE when cleanup failure looks transient enough to retry.
   */
  private function isRetryableCleanupFailure(\Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    foreach ([
      'timed out',
      'timeout',
      'connection refused',
      'failed to connect',
      'temporarily unavailable',
      'too many requests',
      '429',
      '5xx',
      'internal server error',
      'bad gateway',
      'service unavailable',
      'gateway timeout',
    ] as $needle) {
      if (str_contains($message, $needle)) {
        return TRUE;
      }
    }

    return FALSE;
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
   * Builds a small operator-facing progress snapshot (no raw logs).
   *
   * @return array<string, string|int>
   *   Progress snapshot.
   */
  private function buildRetryableProgressSnapshot(string $phase, string $action, \Throwable $exception): array {
    $step = 1;
    $label = 'Selecting instance';

    switch ($phase) {
      case 'wake_instance':
      case 'ssh_bootstrap':
        $step = 2;
        $label = 'Waiting for instance running + SSH';
        break;

      case 'stop_workload':
      case 'start_workload':
        $step = 3;
        $label = 'Starting vLLM server';
        break;

      case 'workload_ready_probe':
      default:
        $step = 4;
        $label = 'Waiting for vLLM API (/v1/models)';
        break;
    }

    $result = $this->summarizeRetryableResult($exception);

    $next = 'retry';
    if ($phase === 'start_workload') {
      $next = 'check /v1/models';
    }
    elseif ($phase === 'ssh_bootstrap') {
      $next = 'wait for SSH';
    }

    return [
      'step' => $step,
      'step_total' => 4,
      'label' => $label,
      'result' => $result,
      'next' => $next,
      'phase' => $phase,
      'action' => $action,
    ];
  }

  /**
   * Formats a one-line, operator-facing retryable status summary.
   *
   * Intentionally avoids dumping raw logs or large probe payloads.
   *
   * @param string $contractId
   *   Vast contract ID.
   * @param array<string, string|int> $progress
   *   Progress snapshot (step/label/result/next).
   */
  private function formatRetryableStatusLine(string $contractId, array $progress): string {
    $step = (int) ($progress['step'] ?? 0);
    $stepTotal = (int) ($progress['step_total'] ?? 0);
    $label = (string) ($progress['label'] ?? '');
    $result = (string) ($progress['result'] ?? '');
    $next = (string) ($progress['next'] ?? '');

    $prefix = 'Step ' . $step . '/' . $stepTotal . ': ' . $label . '.';
    $bits = [
      $prefix,
      'contract=' . $contractId . '.',
      'Result: ' . ($result !== '' ? $result : '(unknown)') . '.',
      'Next: ' . ($next !== '' ? $next : 'retry') . '.',
    ];
    return implode(' ', $bits);
  }

  /**
   * Summarizes a retryable warmup exception into a short operator string.
   */
  private function summarizeRetryableResult(\Throwable $exception): string {
    $message = strtolower($exception->getMessage());

    if (str_contains($message, 'connection refused') && str_contains($message, 'port 8000')) {
      return 'API not listening yet on :8000 (connection refused; expected during cold start)';
    }
    if (str_contains($message, 'connection refused') && str_contains($message, 'port 8080')) {
      return 'API not listening yet on :8080 (connection refused; expected during cold start)';
    }
    if (str_contains($message, 'connection refused')) {
      return 'API not listening yet (connection refused; expected during cold start)';
    }
    if (str_contains($message, 'ssh bootstrap timeout')) {
      return 'SSH not ready within this polling slice (expected while booting)';
    }
    if (str_contains($message, 'ssh not ready')) {
      return 'SSH not ready yet (expected while booting)';
    }
    if (str_contains($message, 'readiness polling slice timed out')) {
      return 'Not ready yet within this polling slice (expected during cold start)';
    }

    if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
      return 'Timed out within this polling slice (expected during cold start)';
    }

    return 'Not ready yet (expected during cold start)';
  }

  /**
   * Detects Vast control-plane rate limiting on wake calls.
   */
  private function isWakeRateLimitFailure(\Throwable $exception): bool {
    $message = strtolower($exception->getMessage());
    return str_contains($message, 'too many requests')
      || str_contains($message, 'api requests too frequent');
  }

  /**
   * Stops and marks a wake candidate that is no longer actually reusable.
   *
   * @param array<string,mixed> $record
   *   Pool record to update.
   * @param string $contractId
   *   Vast contract ID.
   * @param string $message
   *   Operator-facing status message.
   *
   * @return null
   *   Always NULL so acquire can continue to the next candidate.
   */
  private function handleExternalLeaseWakeFailure(array $record, string $contractId, string $message): ?array {
    try {
      $this->instanceLifecycleClient->stopInstance($contractId);
      $record['runtime_state'] = 'stopped';
      $record['last_stopped_at'] = time();
    }
    catch (\Throwable) {
      $record['runtime_state'] = 'unknown';
    }

    $this->recordTransientAcquireObservation(
      $record,
      'wake_instance',
      'request Vast start',
      $message,
    );
    return NULL;
  }

  /**
   * Falls through to the next acquire candidate after a wake rate limit.
   */
  private function handleWakeRateLimitFallback(
    array $record,
    string $contractId,
    \Throwable $exception,
  ): ?array {
    try {
      $info = $this->vastClient->showInstance($contractId);
      $actualStatus = (string) ($info['actual_status'] ?? '');
      if ($actualStatus === 'scheduling') {
        try {
          $this->instanceLifecycleClient->stopInstance($contractId);
          $record['runtime_state'] = 'stopped';
          $record['last_stopped_at'] = time();
        }
        catch (\Throwable) {
          // Preserve the original wake-rate-limit observation below.
        }
      }
    }
    catch (\Throwable) {
      // Preserve the original wake-rate-limit observation below.
    }

    $this->recordTransientAcquireObservation(
      $record,
      'wake_instance',
      'request Vast start',
      $exception->getMessage(),
    );
    return NULL;
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
    $record['runtime_state'] = 'running';
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
   * Derives a normalized runtime state from Vast metadata.
   */
  private function deriveRuntimeState(array $info): string {
    if ($this->isRunningState($info)) {
      return 'running';
    }

    $curState = strtolower(trim((string) ($info['cur_state'] ?? '')));
    $actualStatus = strtolower(trim((string) ($info['actual_status'] ?? '')));
    foreach ([$curState, $actualStatus] as $state) {
      if (in_array($state, ['stopped', 'exited'], TRUE)) {
        return 'stopped';
      }
      if (in_array($state, ['created', 'starting', 'scheduled', 'scheduling'], TRUE)) {
        return 'starting';
      }
      if (in_array($state, ['destroyed', 'deleted'], TRUE)) {
        return 'destroyed';
      }
    }

    return 'unknown';
  }

  /**
   * Determines whether a drifted record can be normalized to available.
   *
   * @param array<string,mixed> $record
   *   Stored pool record.
   * @param string $runtimeState
   *   Normalized runtime state derived from Vast.
   */
  private function shouldNormalizeToAvailable(array $record, string $runtimeState): bool {
    $leaseStatus = (string) ($record['lease_status'] ?? '');
    if ($leaseStatus === 'leased' || $leaseStatus === 'destroyed' || $leaseStatus === 'rented_elsewhere') {
      return FALSE;
    }
    if (!in_array($runtimeState, ['running', 'stopped'], TRUE)) {
      return FALSE;
    }
    return !$this->hasActiveLeaseMetadata($record);
  }

  /**
   * Detects whether the record still carries an active lease claim.
   *
   * @param array<string,mixed> $record
   *   Stored pool record.
   */
  private function hasActiveLeaseMetadata(array $record): bool {
    return trim((string) ($record['lease_token'] ?? '')) !== ''
      || (int) ($record['leased_at'] ?? 0) > 0
      || (int) ($record['lease_expires_at'] ?? 0) > time();
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
