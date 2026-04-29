<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapterManager;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

/**
 * Vast API client for offer discovery and instance lifecycle operations.
 */
final class VastRestClient implements VastRestClientInterface {

  /**
   * Module logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly VastApiClientInterface $vastApiClient,
    private readonly BadHostRegistry $badHosts,
    private readonly WorkloadReadinessAdapterManager $workloadAdapterManager,
    private readonly SshProbeExecutor $sshProbeExecutor,
    private readonly SshKeyPathResolverInterface $sshKeyPathResolver,
    LoggerChannelFactoryInterface $loggerFactory,
    private readonly StateInterface $state,
  ) {
    $this->logger = $loggerFactory->get('compute_orchestrator');
  }

  /**
   * {@inheritdoc}
   */
  public function searchOffers(string $query, int $limit = 20): array {
    throw new \LogicException('Use structured searchOffersStructured() instead.');
  }

  /**
   * {@inheritdoc}
   */
  public function searchOffersStructured(array $filters, int $limit = 20): array {
    $response = $this->request('POST', 'bundles/', [
      'json' => array_merge(
        ['limit' => $limit],
        $filters
      ),
    ]);

    if (!isset($response['offers']) || !is_array($response['offers'])) {
      throw new \RuntimeException(
        'Malformed offers response: ' . json_encode($response)
      );
    }

    return $response['offers'];
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance(string $offerId, string $image, array $options = []): array {
    $payload = array_merge(
      [
        'image' => $image,
      ],
      $options
    );

    return $this->request('PUT', 'asks/' . (int) $offerId . '/', [
      'json' => $payload,
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function startInstance(string $instanceId): array {
    throw new \LogicException('Not implemented yet.');
  }

  /**
   * {@inheritdoc}
   */
  public function showInstance(string $instanceId): array {
    $response = $this->request('GET', 'instances/' . (int) $instanceId . '/');
    if (!isset($response['instances']) || !is_array($response['instances'])) {
      throw new \RuntimeException(
        'Malformed Vast instance response: ' . json_encode($response)
      );
    }

    return $response['instances'];
  }

  /**
   * {@inheritdoc}
   */
  public function destroyInstance(string $instanceId): array {
    return $this->request('DELETE', 'instances/' . (int) $instanceId . '/');
  }

  /**
   * {@inheritdoc}
   */
  public function getInstanceLogs(string $instanceId, bool $extra = FALSE): array {
    $uri = 'instances/' . (int) $instanceId . '/log';
    if ($extra) {
      $uri .= '?type=extra';
    }
    return $this->request('GET', $uri);
  }

  /**
   * Executes an authenticated Vast REST request.
   */
  private function request(string $method, string $uri, array $options = []): array {
    return $this->vastApiClient->request($method, $uri, $options);
  }

  /**
   * {@inheritdoc}
   */
  public function selectBestOffer(array $filters, array $excludeHosts = [], array $excludeRegions = [], int $limit = 5, ?float $maxPrice = NULL): ?array {

    $offers = $this->searchOffersStructured($filters, $limit);
    $excludedHostIds = $this->normalizeHostIds($excludeHosts);
    $hostStats = $this->getHostStats();

    $valid = [];

    foreach ($offers as $offer) {

      $hostId = $this->normalizeHostId($offer['host_id'] ?? NULL);

      if ($hostId !== '' && in_array($hostId, $excludedHostIds, TRUE)) {
        continue;
      }

      $geo = (string) ($offer['geolocation'] ?? '');

      if (preg_match('/,\s*([A-Z]{2})$/', $geo, $m)) {
        $country = $m[1];
        if (in_array($country, $excludeRegions, TRUE)) {
          continue;
        }
      }

      $price = (float) ($offer['dph_total'] ?? 0);

      if ($maxPrice !== NULL && $price > $maxPrice) {
        continue;
      }

      $valid[] = $offer;
    }

    if (empty($valid)) {
      return NULL;
    }

    $valid = $this->preferKnownGoodOffersWhenAvailable($valid, $hostStats);

    usort($valid, function ($a, $b) {
      return ($a['dph_total'] ?? 0) <=> ($b['dph_total'] ?? 0);
    });

    return $valid[0];
  }

  /**
   * {@inheritdoc}
   */
  public function waitForRunningAndSsh(string $instanceId, string $workload = 'vllm', int $timeoutSeconds = 180): array {

    $start = time();
    $adapter = $this->workloadAdapterManager->createInstance($workload);
    // Caller timeout is the hard cap for this polling slice. Stall detection
    // and readiness classification still govern the overall startup behavior.
    $absoluteSafetyTimeout = max(5, $timeoutSeconds);
    // Drupal batch operations intentionally invoke this method with short
    // "slice" timeouts. Use a shorter poll interval in that case so the slice
    // can run multiple probes before timing out.
    $pollIntervalSeconds = $absoluteSafetyTimeout <= 90 ? 10 : 30;
    $stallThresholdSeconds = 600;
    $sshLossThresholdSeconds = 300;
    $sshNeverReadyThresholdSeconds = 180;
    $sshFailureGraceSeconds = 180;
    $lastProgressAt = $start;
    $sshLostSince = NULL;
    $sshWasReachable = FALSE;
    $sshFailureStartedAt = NULL;
    $sshFailureReason = NULL;
    $previousProbeResults = [];
    $lastLogCheckAt = $start;
    $logCheckInterval = 60;

    $lastCurState = NULL;
    $lastActualStatus = NULL;
    $lastStatusMsg = NULL;
    $lastSshHost = NULL;
    $lastSshPort = NULL;

    $lastProbeWhy = NULL;
    $lastProbeKind = NULL;

    while (TRUE) {

      $this->logWithTime('Polling instance ' . $instanceId);

      if ((time() - $start) > $absoluteSafetyTimeout) {
        $extra = '';
        if ($lastProbeKind && $lastProbeWhy) {
          $extra = ' Last probe failure (' . $lastProbeKind . '): ' . $lastProbeWhy;
        }
        $workloadLabel = $workload !== '' ? $workload : '(unknown)';
        throw new \RuntimeException('Readiness polling slice timed out after ' . $absoluteSafetyTimeout . ' seconds for workload ' . $workloadLabel . '.' . $extra);
      }

      $info = $this->showInstance($instanceId);

      $curState = (string) ($info['cur_state'] ?? '');
      $actualStatus = (string) ($info['actual_status'] ?? '');
      $statusMsg = (string) ($info['status_msg'] ?? '');
      $sshHost = (string) ($info['ssh_host'] ?? '');
      $sshPort = (string) ($info['ssh_port'] ?? '');

      // Log only on change (keeps output readable).
      $changed =
        $curState !== $lastCurState ||
        $actualStatus !== $lastActualStatus ||
        $statusMsg !== $lastStatusMsg ||
        $sshHost !== $lastSshHost ||
        $sshPort !== $lastSshPort;

      if ($changed) {
        $this->logWithTime(sprintf(
          'INSTANCE %s cur_state=%s actual_status=%s ssh=%s:%s msg=%s',
          $instanceId,
          $curState !== '' ? $curState : '(null)',
          $actualStatus !== '' ? $actualStatus : '(null)',
          $sshHost !== '' ? $sshHost : '(null)',
          $sshPort !== '' ? $sshPort : '(null)',
          $statusMsg !== '' ? $statusMsg : '(null)'
        ));

        $lastCurState = $curState;
        $lastActualStatus = $actualStatus;
        $lastStatusMsg = $statusMsg;
        $lastSshHost = $sshHost;
        $lastSshPort = $sshPort;
        $lastProgressAt = time();
      }

      // Hard fail: Docker/container runtime error reported by Vast.
      if ($statusMsg !== '' && (
        stripos($statusMsg, 'OCI runtime create failed') !== FALSE ||
        stripos($statusMsg, 'failed to create task for container') !== FALSE ||
        stripos($statusMsg, 'Error response from daemon') !== FALSE ||
        stripos($statusMsg, 'no such container') !== FALSE
      )) {
        throw new \RuntimeException('Container start failed: ' . $statusMsg);
      }

      $isFailureState = in_array($actualStatus, ['error', 'exited', 'failed'], TRUE);
      $isStatusMismatch = $this->isBootstrapStatusMismatch($curState, $actualStatus, $statusMsg, $sshHost, $sshPort);

      // Hard fail: explicit failure states unless Vast is lagging behind a
      // successful SSH runtime startup report.
      if ($isFailureState && !$isStatusMismatch) {
        throw new \RuntimeException(
          'Instance entered failure state: ' . $actualStatus . ' — ' . $statusMsg
        );
      }

      // Some failures show up as "created" + error message.
      if ($actualStatus === 'created' && $statusMsg !== '') {
        if ($this->isCreationFailureMessage($statusMsg)) {
          throw new \RuntimeException('Container failed during creation: ' . $statusMsg);
        }
      }

      // Probe when Vast reports the workload as running, or when Vast status is
      // stale but SSH startup has clearly succeeded.
      if ($curState === 'running' && ($actualStatus === 'running' || $isStatusMismatch) && $sshHost !== '' && $sshPort !== '') {

        $user = (string) ($info['ssh_user'] ?? 'root');

        $sshCheck = $this->sshLoginCheck($sshHost, (int) $sshPort, $user);
        if (!$sshCheck['ok']) {
          $why = (string) $sshCheck['why'];
          $sshUnavailableFor = 0;
          if (!$sshWasReachable) {
            $sshUnavailableFor = time() - $lastProgressAt;
          }
          if ($sshWasReachable && $sshLostSince === NULL) {
            $sshLostSince = time();
          }
          $sshLossSeconds = $sshLostSince !== NULL ? (time() - $sshLostSince) : 0;
          if ($sshFailureReason !== $why) {
            $sshFailureReason = $why;
            $sshFailureStartedAt = time();
          }
          if ($lastProbeKind !== 'ssh' || $lastProbeWhy !== $why) {
            $this->logWithTime('PROBE ssh not ready: ' . $why);
            $this->logger->notice('WORKLOAD ssh not ready: {why}', ['why' => $why]);
            $lastProbeKind = 'ssh';
            $lastProbeWhy = $why;
          }
          if ((time() - $lastLogCheckAt) >= $logCheckInterval) {
            $lastLogCheckAt = time();
            $this->inspectInstanceLogsForSshTunnelFailures($instanceId);
          }
          if (
            $this->isSshPortForwardingFailure($why) &&
            $sshFailureStartedAt !== NULL &&
            (time() - $sshFailureStartedAt) >= $sshFailureGraceSeconds
          ) {
            throw new \RuntimeException('SSH port forwarding stuck: ' . $why);
          }
          if (!$sshWasReachable && $sshUnavailableFor >= $sshNeverReadyThresholdSeconds) {
            throw new \RuntimeException('SSH never became reachable after workload container reported running for ' . $sshUnavailableFor . ' seconds. Last SSH probe: ' . $why);
          }
          if ($sshWasReachable && $sshLossSeconds >= $sshLossThresholdSeconds) {
            throw new \RuntimeException('Connectivity loss: SSH probe unavailable for ' . $sshLossSeconds . ' seconds.');
          }
          sleep($pollIntervalSeconds);
          continue;
        }
        $sshLostSince = NULL;
        $sshWasReachable = TRUE;
        $sshFailureReason = NULL;
        $sshFailureStartedAt = NULL;

        $probeResults = $this->executeWorkloadProbesViaSsh($adapter->getReadinessProbeCommands(), $sshHost, (int) $sshPort, $user);
        if ($adapter->isReadyFromProbeResults($probeResults)) {
          $this->logger->notice('WORKLOAD ready - adapter={adapter} instance={instance}', [
            'adapter' => $workload,
            'instance' => $instanceId,
          ]);
          return $info;
        }

        $classification = $adapter->classifyFailure($probeResults);
        $why = $this->formatProbeFailure($classification, $probeResults);

        $classifications = [
          FailureClass::INFRA_FATAL,
          FailureClass::WORKLOAD_FATAL,
          FailureClass::WORKLOAD_INCOMPATIBLE,
        ];
        if (in_array($classification, $classifications, TRUE)) {

          throw new WorkloadReadinessException(
            $classification,
            'Terminal workload startup failure: ' . $why
          );
        }

        $forwardProgress = $adapter->detectForwardProgress($previousProbeResults, $probeResults);
        $previousProbeResults = $probeResults;

        if ($forwardProgress) {
          $lastProgressAt = time();
          $this->logger->notice('WORKLOAD warmup - forward progress detected (class={class}) instance={instance}', [
            'class' => $classification,
            'instance' => $instanceId,
          ]);
        }
        else {
          $stalledFor = time() - $lastProgressAt;
          $this->logger->warning('WORKLOAD warmup - stalled {stalled}/{threshold} seconds (class={class}) instance={instance}', [
            'stalled' => (string) $stalledFor,
            'threshold' => (string) $stallThresholdSeconds,
            'class' => $classification,
            'instance' => $instanceId,
          ]);
          if ($stalledFor >= $stallThresholdSeconds) {
            throw new \RuntimeException('Workload stalled for ' . $stalledFor . ' seconds without forward progress.');
          }
        }

        if ($lastProbeKind !== 'workload' || $lastProbeWhy !== $why) {
          $this->logWithTime('PROBE workload not ready: ' . $why);
          $lastProbeKind = 'workload';
          $lastProbeWhy = $why;
        }

        sleep($pollIntervalSeconds);
        continue;
      }

      $stalledFor = time() - $lastProgressAt;
      if ($stalledFor >= ($stallThresholdSeconds / 2) && (time() - $lastLogCheckAt) >= $logCheckInterval) {
        $lastLogCheckAt = time();
        $this->inspectInstanceLogsForFailures($instanceId);
      }
      if ($stalledFor >= $stallThresholdSeconds) {
        throw new \RuntimeException(
          'Instance stalled before workload readiness for ' . $stalledFor . ' seconds.'
        );
      }

      sleep($pollIntervalSeconds);
    }
  }

  /**
   * Returns the first log line that indicates a fatal container/runtime error.
   */
  private function containsFatalLogLine(array $lines): ?string {
    foreach ($lines as $line) {
      $normalized = strtolower($line);
      if ($normalized === '' || strlen($line) < 5) {
        continue;
      }
      foreach ([
        'error response from daemon',
        'no such container',
        'oci runtime create failed',
        'failed to create task for container',
        'pull access denied',
      ] as $term) {
        if (str_contains($normalized, $term)) {
          return $line;
        }
      }
    }
    return NULL;
  }

  /**
   * Extracts log lines from a Vast log payload response.
   *
   * Vast can return different shapes depending on endpoint/type.
   *
   * @return string[]
   *   Log lines.
   */
  private function extractLogLinesFromPayload(array $payload): array {
    $lines = [];
    if (isset($payload['log']) && is_string($payload['log'])) {
      $lines = array_merge($lines, $this->splitAndTrim($payload['log']));
    }
    if (isset($payload['lines']) && is_array($payload['lines'])) {
      foreach ($payload['lines'] as $line) {
        if (is_string($line)) {
          $lines[] = trim($line);
        }
        elseif (is_array($line)) {
          $text = $line['line'] ?? $line['log'] ?? NULL;
          if (is_string($text)) {
            $lines[] = trim($text);
          }
        }
      }
    }
    if (isset($payload['debug']) && is_string($payload['debug'])) {
      $lines = array_merge($lines, $this->splitAndTrim($payload['debug']));
    }
    return array_values(array_filter($lines));
  }

  /**
   * Detects a stale Vast status mismatch during runtime readiness polling.
   */
  private function isBootstrapStatusMismatch(
    string $curState,
    string $actualStatus,
    string $statusMessage,
    string $sshHost,
    string $sshPort,
  ): bool {
    if ($curState !== 'running') {
      return FALSE;
    }

    if (!in_array($actualStatus, ['error', 'exited', 'failed'], TRUE)) {
      return FALSE;
    }

    if ($sshHost === '' || $sshPort === '') {
      return FALSE;
    }

    $normalizedMessage = strtolower($statusMessage);
    if ($normalizedMessage === '') {
      return FALSE;
    }

    return str_contains($normalizedMessage, 'success, running')
      && str_contains($normalizedMessage, '/ssh');
  }

  /**
   * Splits a newline chunk and trims each line.
   *
   * @return string[]
   *   Non-empty trimmed lines.
   */
  private function splitAndTrim(string $chunk): array {
    return array_filter(array_map('trim', explode("\n", $chunk)));
  }

  /**
   * Inspects instance logs and throws if a fatal container error is detected.
   */
  private function inspectInstanceLogsForFailures(string $instanceId): void {
    foreach ([FALSE, TRUE] as $extra) {
      try {
        $logs = $this->getInstanceLogs($instanceId, $extra);
      }
      catch (\RuntimeException $e) {
        continue;
      }

      $lines = $this->extractLogLinesFromPayload($logs);
      $fatal = $this->containsFatalLogLine($lines);
      if ($fatal !== NULL) {
        throw new \RuntimeException('Container log fatality detected: ' . $fatal);
      }
    }
  }

  /**
   * Detects SSH tunnel failure messages in log lines.
   */
  private function containsSshTunnelFatalLogLine(array $lines): ?string {
    foreach ($lines as $line) {
      $normalized = strtolower($line);
      if ($normalized === '' || strlen($line) < 5) {
        continue;
      }
      foreach ([
        'remote port forwarding failed for listen port',
        'port forwarding failed for listen port',
      ] as $term) {
        if (str_contains($normalized, $term)) {
          return $line;
        }
      }
    }
    return NULL;
  }

  /**
   * Inspects instance logs and throws if SSH tunnelling failures are detected.
   */
  private function inspectInstanceLogsForSshTunnelFailures(string $instanceId): void {
    foreach ([FALSE, TRUE] as $extra) {
      try {
        $logs = $this->getInstanceLogs($instanceId, $extra);
      }
      catch (\RuntimeException $e) {
        continue;
      }

      $lines = $this->extractLogLinesFromPayload($logs);
      $fatal = $this->containsSshTunnelFatalLogLine($lines);
      if ($fatal !== NULL) {
        throw new \RuntimeException('SSH tunnel fatality detected: ' . $fatal);
      }
    }
  }

  /**
   * Heuristic check for container creation failures reported via status_msg.
   */
  private function isCreationFailureMessage(string $statusMsg): bool {
    $lower = strtolower($statusMsg);
    foreach (['failed', 'error', 'denied', 'cannot', 'timeout', 'unavailable'] as $term) {
      if (str_contains($lower, $term)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Detects SSH port-forward failures in error messages.
   */
  private function isSshPortForwardingFailure(string $message): bool {
    $lower = strtolower($message);
    foreach (['remote port forwarding failed', 'port forwarding failed for'] as $term) {
      if (str_contains($lower, $term)) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function provisionInstanceFromOffers(
    array $filters,
    array $excludeRegions = [],
    int $limit = 5,
    ?float $maxPrice = NULL,
    ?float $minPrice = NULL,
    array $createOptions = [],
    int $maxAttempts = 5,
    int $bootTimeoutSeconds = 600,
  ): array {

    $preserveOnFailure = (bool) ($createOptions['preserve_on_failure'] ?? FALSE);
    $bootstrapOnly = (bool) ($createOptions['bootstrap_only'] ?? FALSE);
    $workload = (string) ($createOptions['workload'] ?? 'vllm');

    $globalBlacklist = $this->getGlobalBlacklist();
    $globallyBlockedHosts = $this->normalizeHostIds(array_keys($globalBlacklist));
    $registryBlockedHosts = $this->badHosts->all();

    if (!empty($globallyBlockedHosts)) {
      $this->logWithTime(
        'Loaded GLOBAL infra blacklist hosts (all workloads): ' .
        implode(',', $globallyBlockedHosts)
      );
    }
    if (!empty($registryBlockedHosts)) {
      $this->logWithTime(
        'Loaded BAD HOST registry entries: ' . implode(',', $registryBlockedHosts)
      );
    }

    $workloadBlacklist = $this->state->get('compute_orchestrator.workload_bad_hosts', []);
    $workloadBlockedHosts = $this->normalizeHostIds($workloadBlacklist[$workload] ?? []);

    $excludedHostIds = array_values(array_unique(array_merge(
      $globallyBlockedHosts,
      $this->normalizeHostIds($registryBlockedHosts),
      $workloadBlockedHosts
    )));

    $offers = $this->searchOffersStructured($filters, $limit);

    if (empty($offers)) {
      throw new \RuntimeException('No offers returned from Vast.');
    }

    $offerHostIds = $this->extractOfferHostIds($offers);
    $matchedGlobalBadHosts = $this->findMatchedHostIds($offerHostIds, $globallyBlockedHosts);
    $matchedWorkloadBadHosts = $this->findMatchedHostIds($offerHostIds, $workloadBlockedHosts);

    $this->logMatchedBadHostsForOfferSelection($workload, $matchedGlobalBadHosts, $matchedWorkloadBadHosts);

    // Filter + sort same logic as selectBestOffer.
    $valid = [];

    foreach ($offers as $offer) {

      $hostId = $this->normalizeHostId($offer['host_id'] ?? NULL);
      if ($hostId !== '' && in_array($hostId, $excludedHostIds, TRUE)) {
        continue;
      }

      $geo = (string) ($offer['geolocation'] ?? '');
      if (preg_match('/,\s*([A-Z]{2})$/', $geo, $m)) {
        $country = $m[1];
        if (in_array($country, $excludeRegions, TRUE)) {
          continue;
        }
      }

      $price = (float) ($offer['dph_total'] ?? 0);
      if ($maxPrice !== NULL && $price > $maxPrice) {
        continue;
      }
      if ($minPrice !== NULL && $price < $minPrice) {
        continue;
      }

      $valid[] = $offer;
    }

    if (empty($valid)) {
      throw new \RuntimeException('No valid offers after filtering.');
    }

    $hostStats = $this->getHostStats();
    $valid = $this->preferKnownGoodOffersWhenAvailable($valid, $hostStats);

    usort($valid, function ($a, $b) use ($hostStats) {
      $hostA = $this->normalizeHostId($a['host_id'] ?? NULL);
      $hostB = $this->normalizeHostId($b['host_id'] ?? NULL);
      $successA = (int) ($hostStats[$hostA]['success'] ?? 0);
      $successB = (int) ($hostStats[$hostB]['success'] ?? 0);

      if ($successA !== $successB) {
        return $successB <=> $successA;
      }

      return ($a['dph_total'] ?? 0) <=> ($b['dph_total'] ?? 0);
    });

    $attempts = 0;
    $lastFailureMessage = NULL;

    foreach ($valid as $offer) {

      if ($attempts >= $maxAttempts) {
        break;
      }

      $hostId = $this->normalizeHostId($offer['host_id'] ?? NULL);
      if ($hostId !== '' && in_array($hostId, $excludedHostIds, TRUE)) {
        continue;
      }

      $offerId = (string) $offer['id'];

      $this->logWithTime('Provision attempt #' . $attempts . ' using offer ' . $offerId);

      $attempts++;

      $contractId = NULL;

      try {

        $create = $this->createInstance(
          $offerId,
          $createOptions['image'],
          $createOptions['options'] ?? []
        );

        $contractId = (string) $create['new_contract'];

        if ($bootstrapOnly) {
          $this->logWithTime('Waiting for SSH bootstrap for contract ' . $contractId);
          $info = $this->waitForSshBootstrapOnly($contractId, $bootTimeoutSeconds);
        }
        else {
          $this->logWithTime('Waiting for running + SSH for contract ' . $contractId);
          $info = $this->waitForRunningAndSsh(
            $contractId,
            $workload,
            $bootTimeoutSeconds
          );
        }

        $this->recordHostSuccess($hostId);

        return [
          'contract_id' => $contractId,
          'instance_info' => $info,
          'offer' => $offer,
        ];

      }
      catch (\Throwable $e) {

        $lastFailureMessage = $e->getMessage();
        $isInfraFatal = $this->isInfrastructureFatalFailure($lastFailureMessage);
        if ($e instanceof WorkloadReadinessException) {
          $isInfraFatal = $e->getFailureClass() === FailureClass::INFRA_FATAL;
        }

        if ($hostId !== '') {
          $shouldRecordWorkloadBadHost = FALSE;
          $workloadBadHostReason = '';

          if ($e instanceof WorkloadReadinessException) {
            $failureClass = $e->getFailureClass();
            if ($failureClass === FailureClass::WORKLOAD_INCOMPATIBLE) {
              $shouldRecordWorkloadBadHost = TRUE;
              $workloadBadHostReason = 'workload_incompatible';
            }
          }

          if (
            !$shouldRecordWorkloadBadHost &&
            $this->isAbsoluteSafetyTimeoutForWorkload($lastFailureMessage, $workload)
          ) {
            $shouldRecordWorkloadBadHost = TRUE;
            $workloadBadHostReason = 'absolute_safety_timeout';
          }

          if ($shouldRecordWorkloadBadHost) {
            $this->addHostToWorkloadBlacklist(
              $hostId,
              $workload,
              $contractId,
              $workloadBadHostReason
            );
          }
        }

        if ($isInfraFatal && $hostId !== '') {
          $this->incrementInfraFailureStats($hostId);
          $this->addToGlobalBlacklist($hostId);
          $this->logWithTime(sprintf(
            'Recorded GLOBAL bad host: instance=%s host=%s',
            !empty($contractId) ? (string) $contractId : '(unknown)',
            $hostId
          ));
        }

        foreach ($this->extractCdiDeviceIds($lastFailureMessage) as $deviceId) {
          $this->recordHostCdiFailure($hostId, $deviceId);
        }

        $this->logWithTime('--- PROVISION EXCEPTION START ---');
        $this->logWithTime('Offer: ' . $offerId);
        $this->logWithTime('Host: ' . $hostId);
        $this->logWithTime('Message: ' . $e->getMessage());
        $this->logWithTime('File: ' . $e->getFile());
        $this->logWithTime('Line: ' . $e->getLine());
        $logProvisionTraces = (bool) $this->state->get('compute_orchestrator.log_provision_exception_traces', FALSE);
        if ($logProvisionTraces) {
          $this->logWithTime('Trace: ' . $e->getTraceAsString());
        }
        $this->logWithTime('--- PROVISION EXCEPTION END ---');

        if (!$preserveOnFailure && !empty($contractId)) {
          try {
            $this->destroyInstance($contractId);
          }
          catch (\Throwable $destroyError) {
            $this->logWithTime(sprintf(
              'Destroy failed: contract=%s error=%s',
              (string) $contractId,
              $destroyError->getMessage()
            ));
          }
        }
        else {
          $this->logWithTime('Preserving failed instance for investigation: contract=' . $contractId);
        }

        if ($hostId !== '') {
          $excludedHostIds[] = $hostId;
          $excludedHostIds = array_values(array_unique($this->normalizeHostIds($excludedHostIds)));
          if ($isInfraFatal) {
            $this->badHosts->add($hostId);
            $this->logWithTime(sprintf(
              'Recorded REGISTRY bad host: instance=%s host=%s',
              !empty($contractId) ? (string) $contractId : '(unknown)',
              $hostId
            ));
          }
        }

        continue;
      }

    }

    throw new \RuntimeException(
      'All provisioning attempts failed.' . ($lastFailureMessage ? ' Last error: ' . $lastFailureMessage : '')
    );
  }

  /**
   * Waits for a freshly created generic image to reach SSH bootstrap only.
   *
   * @return array<string,mixed>
   *   Latest Vast instance information.
   */
  private function waitForSshBootstrapOnly(string $contractId, int $timeoutSeconds): array {
    $start = time();
    $lastProgressAt = $start;
    $lastSnapshot = [];
    $stallThresholdSeconds = 600;
    $pollIntervalSeconds = 10;

    while (TRUE) {
      if ((time() - $start) > max(5, $timeoutSeconds)) {
        throw new \RuntimeException('Instance exceeded SSH bootstrap timeout.');
      }

      $info = $this->showInstance($contractId);
      $snapshot = [
        'cur_state' => (string) ($info['cur_state'] ?? ''),
        'actual_status' => (string) ($info['actual_status'] ?? ''),
        'status_msg' => (string) ($info['status_msg'] ?? ''),
        'ssh_host' => (string) ($info['ssh_host'] ?? ''),
        'ssh_port' => (string) ($info['ssh_port'] ?? ''),
      ];

      if ($snapshot !== $lastSnapshot) {
        $this->logWithTime(sprintf(
          'BOOTSTRAP %s cur_state=%s actual_status=%s ssh=%s:%s msg=%s',
          $contractId,
          $snapshot['cur_state'] !== '' ? $snapshot['cur_state'] : '(null)',
          $snapshot['actual_status'] !== '' ? $snapshot['actual_status'] : '(null)',
          $snapshot['ssh_host'] !== '' ? $snapshot['ssh_host'] : '(null)',
          $snapshot['ssh_port'] !== '' ? $snapshot['ssh_port'] : '(null)',
          $snapshot['status_msg'] !== '' ? $snapshot['status_msg'] : '(null)'
        ));
        $lastSnapshot = $snapshot;
        $lastProgressAt = time();
      }

      if ($snapshot['status_msg'] !== '' && (
        stripos($snapshot['status_msg'], 'OCI runtime create failed') !== FALSE ||
        stripos($snapshot['status_msg'], 'failed to create task for container') !== FALSE ||
        stripos($snapshot['status_msg'], 'Error response from daemon') !== FALSE ||
        stripos($snapshot['status_msg'], 'no such container') !== FALSE
      )) {
        throw new \RuntimeException('Container failed during bootstrap: ' . $snapshot['status_msg']);
      }

      $isFailureState = in_array($snapshot['actual_status'], ['error', 'exited', 'failed'], TRUE);
      $isStatusMismatch = $this->isBootstrapStatusMismatch(
        $snapshot['cur_state'],
        $snapshot['actual_status'],
        $snapshot['status_msg'],
        $snapshot['ssh_host'],
        $snapshot['ssh_port'],
      );

      if ($isFailureState && !$isStatusMismatch) {
        throw new \RuntimeException('Instance entered failure state: ' . $snapshot['actual_status'] . ' — ' . $snapshot['status_msg']);
      }

      if ($snapshot['actual_status'] === 'created' && $snapshot['status_msg'] !== '' && $this->isCreationFailureMessage($snapshot['status_msg'])) {
        throw new \RuntimeException('Container failed during creation: ' . $snapshot['status_msg']);
      }

      if ($snapshot['cur_state'] === 'running' && $snapshot['ssh_host'] !== '' && $snapshot['ssh_port'] !== '') {
        $sshCheck = $this->sshLoginCheck(
          $snapshot['ssh_host'],
          (int) $snapshot['ssh_port'],
          (string) ($info['ssh_user'] ?? 'root'),
        );
        if ($sshCheck['ok']) {
          return $info;
        }
        $this->logWithTime('PROBE ssh not ready: ' . $sshCheck['why']);
      }

      $stalledFor = time() - $lastProgressAt;
      if ($stalledFor >= $stallThresholdSeconds) {
        throw new \RuntimeException('Instance stalled before SSH bootstrap for ' . $stalledFor . ' seconds.');
      }

      sleep($pollIntervalSeconds);
    }
  }

  /**
   * Quick SSH connectivity check for a newly provisioned instance.
   *
   * @return array{ok:bool, why:string}
   *   Result payload.
   */
  private function sshLoginCheck(string $sshHost, int $sshPort, string $sshUser): array {

    $keyPath = $this->sshKeyPathResolver->resolvePath();
    if ($keyPath === NULL) {
      return [
        'ok' => FALSE,
        'why' => 'SSH key not found at: ' . ($this->sshKeyPathResolver->getCandidatePath() ?: '(empty)'),
      ];
    }

    $process = new Process([
      'ssh',
      '-F', '/dev/null',
      '-o', 'BatchMode=yes',
      '-o', 'StrictHostKeyChecking=no',
      '-o', 'UserKnownHostsFile=/dev/null',
      '-o', 'LogLevel=ERROR',
      '-o', 'ConnectTimeout=5',
      '-i', $keyPath,
      '-p', (string) $sshPort,
      $sshUser . '@' . $sshHost,
      'true',
    ]);

    $process->setTimeout(10);

    try {
      $process->run();
    }
    catch (\Throwable $e) {
      return [
        'ok' => FALSE,
        'why' => 'ssh probe exception: ' . $e->getMessage(),
      ];
    }

    if ($process->isSuccessful()) {
      return ['ok' => TRUE, 'why' => ''];
    }

    $stderr = trim($process->getErrorOutput());
    $stdout = trim($process->getOutput());

    return [
      'ok' => FALSE,
      'why' => sprintf(
        'ssh probe failed: exit=%s stderr=%s stdout=%s',
        (string) $process->getExitCode(),
        $stderr !== '' ? $stderr : '(empty)',
        $stdout !== '' ? $stdout : '(empty)'
      ),
    ];
  }

  /**
   * Executes workload readiness probes through SSH.
   *
   * @param array<string, array{command:string, timeout:int}> $commands
   *   Probe commands keyed by name.
   * @param string $sshHost
   *   SSH host.
   * @param int $sshPort
   *   SSH port.
   * @param string $sshUser
   *   SSH username.
   *
   * @return array<string, mixed>
   *   Probe results keyed by name.
   */
  private function executeWorkloadProbesViaSsh(array $commands, string $sshHost, int $sshPort, string $sshUser): array {
    $keyPath = $this->sshKeyPathResolver->resolvePath();
    if ($keyPath === NULL) {
      return [
        'ssh_key' => [
          'ok' => FALSE,
          'transport_ok' => FALSE,
          'failure_kind' => 'transport',
          'exit_code' => NULL,
          'stdout' => '',
          'stderr' => 'SSH key not found at: ' . ($this->sshKeyPathResolver->getCandidatePath() ?: '(empty)'),
          'exception' => 'missing_ssh_key',
        ],
      ];
    }

    $context = new SshConnectionContext(
      host: $sshHost,
      port: $sshPort,
      user: $sshUser,
      keyPath: $keyPath,
    );

    $results = [];
    foreach ($commands as $name => $meta) {
      $command = (string) ($meta['command'] ?? '');
      $timeout = (int) ($meta['timeout'] ?? 10);
      $request = new SshProbeRequest(
        name: (string) $name,
        command: $command,
        timeoutSeconds: $timeout,
      );
      $results[$name] = $this->sshProbeExecutor->run($context, $request);
    }

    return $results;
  }

  /**
   * Formats probe results for operator logs/errors.
   */
  private function formatProbeFailure(string $classification, array $probeResults): string {
    $parts = ['class=' . $classification];
    foreach ($probeResults as $name => $result) {
      $parts[] = sprintf(
        '%s(ok=%s transport_ok=%s kind=%s exit=%s stderr=%s exception=%s)',
        (string) $name,
        ($result['ok'] ?? FALSE) ? '1' : '0',
        ($result['transport_ok'] ?? FALSE) ? '1' : '0',
        (string) ($result['failure_kind'] ?? 'unknown'),
        isset($result['exit_code']) ? (string) $result['exit_code'] : 'null',
        trim((string) ($result['stderr'] ?? '')) !== '' ? trim((string) ($result['stderr'] ?? '')) : '(empty)',
        trim((string) ($result['exception'] ?? '')) !== '' ? trim((string) ($result['exception'] ?? '')) : '(none)'
      );
    }

    return implode(' | ', $parts);
  }

  /**
   * Loads host stats used for known-good preference and infra-failure tracking.
   */
  private function getHostStats(): array {
    $stats = $this->state->get('compute_orchestrator.host_stats', []);
    return is_array($stats) ? $stats : [];
  }

  /**
   * Persists host stats.
   */
  private function saveHostStats(array $stats): void {
    $this->state->set('compute_orchestrator.host_stats', $stats);
  }

  /**
   * Records a successful provision event for a host.
   */
  private function recordHostSuccess(string $hostId): void {
    if ($hostId === '') {
      return;
    }

    $stats = $this->getHostStats();
    if (!isset($stats[$hostId]) || !is_array($stats[$hostId])) {
      $stats[$hostId] = [
        'success' => 0,
        'infra_fail' => 0,
      ];
    }

    $stats[$hostId]['success'] = (int) ($stats[$hostId]['success'] ?? 0) + 1;
    $stats[$hostId]['last_success'] = time();
    $this->saveHostStats($stats);
  }

  /**
   * Loads the CDI failure history map.
   */
  private function getHostCdiFailures(): array {
    $value = $this->state->get('compute_orchestrator.host_cdi_failures', []);
    return is_array($value) ? $value : [];
  }

  /**
   * Persists CDI failure history.
   */
  private function saveHostCdiFailures(array $failures): void {
    $this->state->set('compute_orchestrator.host_cdi_failures', $failures);
  }

  /**
   * Records a CDI failure for a host/device (last 5 entries retained).
   */
  private function recordHostCdiFailure(string $hostId, string $deviceId): void {
    if ($hostId === '' || $deviceId === '') {
      return;
    }

    $failures = $this->getHostCdiFailures();
    if (!isset($failures[$hostId]) || !is_array($failures[$hostId])) {
      $failures[$hostId] = [];
    }

    $failures[$hostId][] = [
      'device' => $deviceId,
      'when' => time(),
    ];

    if (count($failures[$hostId]) > 5) {
      $failures[$hostId] = array_slice($failures[$hostId], -5);
    }

    $this->saveHostCdiFailures($failures);
    $this->logWithTime(sprintf(
      'Recorded CDI failure for host %s device %s',
      $hostId,
      $deviceId
    ));
  }

  /**
   * Increments infra-failure counters for a host.
   */
  private function incrementInfraFailureStats(string $hostId): void {
    if ($hostId === '') {
      return;
    }

    $stats = $this->getHostStats();
    if (!isset($stats[$hostId]) || !is_array($stats[$hostId])) {
      $stats[$hostId] = [
        'success' => 0,
        'infra_fail' => 0,
      ];
    }

    $stats[$hostId]['infra_fail'] = (int) ($stats[$hostId]['infra_fail'] ?? 0) + 1;
    $stats[$hostId]['last_fail'] = time();
    $this->saveHostStats($stats);
  }

  /**
   * Loads the global "bad host" blacklist (all workloads).
   *
   * Values are timestamps/reasons depending on origin.
   */
  private function getGlobalBlacklist(): array {
    $value = $this->state->get('compute_orchestrator.global_bad_hosts', []);
    return is_array($value) ? $value : [];
  }

  /**
   * Adds a host to the global blacklist with the current timestamp.
   */
  private function addToGlobalBlacklist(string $hostId): void {
    if ($hostId === '') {
      return;
    }

    $bad = $this->getGlobalBlacklist();
    $bad[$hostId] = time();
    $this->state->set('compute_orchestrator.global_bad_hosts', $bad);
  }

  /**
   * Heuristic check for infra-fatal failures from an exception/log message.
   */
  private function isInfrastructureFatalFailure(string $message): bool {
    $lower = strtolower($message);
    foreach ([
      'cdi',
      'oci runtime create failed',
      'error response from daemon',
      'no such container',
      'cuda',
      'gpu error, unable to start instance',
      'unsupported display driver / cuda driver combination',
      'cannot find -lcuda',
    ] as $marker) {
      if (str_contains($lower, $marker)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Extracts CDI device IDs from a failure message.
   *
   * @return string[]
   *   Device identifiers.
   */
  private function extractCdiDeviceIds(string $message): array {
    if ($message === '') {
      return [];
    }

    $matches = [];
    preg_match_all('/(D\\.[0-9a-f]+\\/gpu=\\d+)/i', $message, $matches);
    if (empty($matches[1])) {
      return [];
    }

    $unique = array_unique($matches[1]);
    $trimmed = array_map('trim', $unique);
    return array_values(array_filter($trimmed));
  }

  /**
   * Normalizes a list/map of host IDs into unique canonical strings.
   *
   * Supports lists (values) or associative maps keyed by host ID.
   *
   * @param mixed $rawHostIds
   *   Raw host IDs list/map.
   *
   * @return string[]
   *   Unique host ID strings.
   */
  private function normalizeHostIds(mixed $rawHostIds): array {
    if (!is_array($rawHostIds)) {
      return [];
    }

    $normalized = [];

    foreach ($rawHostIds as $key => $value) {
      if (is_scalar($value)) {
        $normalizedValue = $this->normalizeHostId($value);
        if ($normalizedValue !== '') {
          $normalized[] = $normalizedValue;
          continue;
        }
      }

      // Support associative maps keyed by host ID
      // (e.g. host => timestamp/reason).
      if (is_scalar($key)) {
        $normalizedKey = $this->normalizeHostId($key);
        if ($normalizedKey !== '') {
          $normalized[] = $normalizedKey;
        }
        continue;
      }
    }

    return array_values(array_unique($normalized));
  }

  /**
   * Normalizes a single host ID value to a canonical string.
   */
  private function normalizeHostId(mixed $rawHostId): string {
    if (!is_scalar($rawHostId)) {
      return '';
    }

    $hostId = trim((string) $rawHostId);
    if ($hostId === '') {
      return '';
    }

    // Normalize numeric host IDs so integer and float-like string encodings
    // match.
    if (preg_match('/^[0-9]+(?:\\.0+)?$/', $hostId) === 1) {
      $hostId = (string) ((int) (float) $hostId);
    }

    return $hostId;
  }

  /**
   * Prefer known-good hosts when at least one candidate has prior success.
   */
  private function preferKnownGoodOffersWhenAvailable(array $offers, array $hostStats): array {
    $knownGoodOffers = [];

    foreach ($offers as $offer) {
      $hostId = $this->normalizeHostId($offer['host_id'] ?? NULL);
      $hostSuccessCount = (int) ($hostStats[$hostId]['success'] ?? 0);

      if ($hostSuccessCount > 0) {
        $knownGoodOffers[] = $offer;
      }
    }

    if (!empty($knownGoodOffers)) {
      $this->logWithTime(
        sprintf(
          'Known-good preference active: using %d known-good offers out of %d candidates.',
          count($knownGoodOffers),
          count($offers)
        )
      );
      return $knownGoodOffers;
    }

    return $offers;
  }

  /**
   * Logs which excluded hosts were present in the current offer selection set.
   */
  private function logMatchedBadHostsForOfferSelection(string $workload, array $matchedGlobalBadHosts, array $matchedWorkloadBadHosts): void {
    if (!empty($matchedGlobalBadHosts)) {
      $this->logWithTime(
        'Offer selection matched GLOBAL bad hosts: ' . implode(',', $matchedGlobalBadHosts)
      );
    }
    else {
      $this->logWithTime('Offer selection matched GLOBAL bad hosts: (none)');
    }

    if (!empty($matchedWorkloadBadHosts)) {
      $this->logWithTime(
        sprintf(
          'Offer selection matched WORKLOAD bad hosts [%s]: %s',
          $workload,
          implode(',', $matchedWorkloadBadHosts)
        )
      );
    }
    else {
      $this->logWithTime(
        sprintf(
          'Offer selection matched WORKLOAD bad hosts [%s]: (none)',
          $workload
        )
      );
    }
  }

  /**
   * Extracts unique host IDs from a Vast offers list.
   *
   * @return string[]
   *   Host IDs.
   */
  private function extractOfferHostIds(array $offers): array {
    $hostIds = [];

    foreach ($offers as $offer) {
      $hostId = $this->normalizeHostId($offer['host_id'] ?? NULL);
      if ($hostId === '') {
        continue;
      }

      $hostIds[] = $hostId;
    }

    return array_values(array_unique($hostIds));
  }

  /**
   * Returns host IDs present in both lists (normalized).
   *
   * @return string[]
   *   Matched host IDs.
   */
  private function findMatchedHostIds(array $offerHostIds, array $blockedHostIds): array {
    if (empty($offerHostIds) || empty($blockedHostIds)) {
      return [];
    }

    $normalizedOfferHostIds = $this->normalizeHostIds($offerHostIds);
    $normalizedBlockedHostIds = $this->normalizeHostIds($blockedHostIds);
    $matched = array_values(array_intersect($normalizedOfferHostIds, $normalizedBlockedHostIds));

    return array_values(array_unique($matched));
  }

  /**
   * Adds a host to the per-workload blacklist (idempotent).
   */
  private function addHostToWorkloadBlacklist(string $hostId, string $workload, ?string $contractId, string $reason): void {
    $key = 'compute_orchestrator.workload_bad_hosts';
    $all = $this->state->get($key, []);

    if (!isset($all[$workload]) || !is_array($all[$workload])) {
      $all[$workload] = [];
    }

    $normalizedWorkloadHosts = $this->normalizeHostIds($all[$workload]);
    if (in_array($hostId, $normalizedWorkloadHosts, TRUE)) {
      return;
    }

    $all[$workload][] = $hostId;
    $this->state->set($key, $all);

    $this->logWithTime(sprintf(
      'Recorded WORKLOAD bad host: instance=%s host=%s workload=%s reason=%s',
      !empty($contractId) ? (string) $contractId : '(unknown)',
      $hostId,
      $workload,
      $reason !== '' ? $reason : '(unknown)'
    ));
  }

  /**
   * Detects whether an exception message was caused by the safety timeout.
   */
  private function isAbsoluteSafetyTimeoutForWorkload(string $message, string $workload): bool {
    if ($message === '') {
      return FALSE;
    }

    $lower = strtolower($message);
    if (!str_contains($lower, 'readiness polling slice timed out')) {
      return FALSE;
    }

    if ($workload === '') {
      return TRUE;
    }

    return str_contains($lower, 'workload ' . strtolower($workload));
  }

  /**
   * Logs messages with a timestamp (stderr).
   */
  private function logWithTime(string $message): void {
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $message);
  }

}
