<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Plugin\WorkloadReadinessAdapterManager;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

final class VastRestClient implements VastRestClientInterface {

  private ClientInterface $httpClient;
  private string $apiKey;
  private LoggerInterface $logger;

  public function __construct(
    ClientInterface $http_client,
    private readonly BadHostRegistry $badHosts,
    private readonly WorkloadReadinessAdapterManager $workloadAdapterManager,
    private readonly SshProbeExecutor $sshProbeExecutor,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->httpClient = $http_client;
    $this->logger = $loggerFactory->get('compute_orchestrator');

    $apiKey = getenv('VAST_API_KEY');
    if (!$apiKey) {
      throw new \RuntimeException('VAST_API_KEY environment variable is not set.');
    }

    $this->apiKey = $apiKey;
  }

  public function searchOffers(string $query, int $limit = 20): array {
    throw new \LogicException('Use structured searchOffersStructured() instead.');
  }

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

  public function createInstance(string $offerId, string $image, array $options = []): array {

    $payload = array_merge(
      [
        'image' => $image,
      ],
      $options
    );

  //error_log('CREATE PAYLOAD DEBUG: ' . json_encode($payload, JSON_PRETTY_PRINT));

  // TEMPORARY: abort before provisioning to inspect payload.
  //throw new \RuntimeException('DEBUG EXIT AFTER CREATE PAYLOAD');
  return $this->request('PUT', 'asks/' . (int) $offerId . '/', [
    'json' => $payload,
  ]);

  }
  public function startInstance(string $instanceId): array {
    throw new \LogicException('Not implemented yet.');
  }

  public function showInstance(string $instanceId): array {
    $response = $this->request('GET', 'instances/' . (int) $instanceId . '/');
    if (!isset($response['instances']) || !is_array($response['instances'])) {
      throw new \RuntimeException(
        'Malformed Vast instance response: ' . json_encode($response)
      );
    }

    return $response['instances'];
  }

  public function destroyInstance(string $instanceId): array {
    return $this->request('DELETE', 'instances/' . (int) $instanceId . '/');
  }

  public function getInstanceLogs(string $instanceId, bool $extra = FALSE): array {
    $uri = 'instances/' . (int) $instanceId . '/log';
    if ($extra) {
      $uri .= '?type=extra';
    }
    return $this->request('GET', $uri);
  }

  private function request(string $method, string $uri, array $options = []): array {
    try {

      $response = $this->httpClient->request(
        $method,
        'https://console.vast.ai/api/v0/' . ltrim($uri, '/'),
        array_merge_recursive([
          'headers' => [
            'Authorization' => 'Bearer ' . $this->apiKey,
            'Accept' => 'application/json',
          ],
          'timeout' => 20,
          'connect_timeout' => 10,
        ], $options)
      );

      $body = (string) $response->getBody();
      $decoded = json_decode($body, true);

      if (!is_array($decoded)) {
        throw new \RuntimeException('Invalid JSON response from Vast API.');
      }

      return $decoded;

    } catch (GuzzleException $e) {

      if ($e instanceof \GuzzleHttp\Exception\ClientException) {
        $response = $e->getResponse();
        if ($response) {
          $body = (string) $response->getBody();
          throw new \RuntimeException('Vast API error response: ' . $body, 0, $e);
        }
      }

      throw new \RuntimeException('Vast API request failed: ' . $e->getMessage(), 0, $e);
    }

  }

  public function selectBestOffer( array $filters, array $excludeHosts = [], array $excludeRegions = [], int $limit = 5, ?float $maxPrice = null): ?array {

    $offers = $this->searchOffersStructured($filters, $limit);

    $valid = [];

    foreach ($offers as $offer) {

      $hostId = (string) ($offer['host_id'] ?? '');

      if (in_array($hostId, $excludeHosts, true)) {
        continue;
      }

      $geo = (string) ($offer['geolocation'] ?? '');

      if (preg_match('/,\s*([A-Z]{2})$/', $geo, $m)) {
        $country = $m[1];
        if (in_array($country, $excludeRegions, true)) {
          continue;
        }
      }

      $price = (float) ($offer['dph_total'] ?? 0);

      if ($maxPrice !== null && $price > $maxPrice) {
        continue;
      }

      $valid[] = $offer;
    }

    if (empty($valid)) {
      return null;
    }

    usort($valid, function ($a, $b) {
      return ($a['dph_total'] ?? 0) <=> ($b['dph_total'] ?? 0);
    });

    return $valid[0];
  }

  public function waitForRunningAndSsh(string $instanceId, string $workload = 'vllm', int $timeoutSeconds = 180): array {

    $start = time();
    $adapter = $this->workloadAdapterManager->createInstance($workload);
    // Caller timeout is the hard cap; adapter startup timeout remains guidance for
    // workload-specific warmup classification, not an override of the caller cap.
    $absoluteSafetyTimeout = max(60, $timeoutSeconds);
    $stallThresholdSeconds = 600;
    $sshLossThresholdSeconds = 300;
    $sshNeverReadyThresholdSeconds = 180;
    $sshFailureGraceSeconds = 180;
    $lastProgressAt = $start;
    $sshLostSince = null;
    $sshWasReachable = false;
    $sshFailureStartedAt = null;
    $sshFailureReason = null;
    $previousProbeResults = [];
    $lastLogCheckAt = $start;
    $logCheckInterval = 60;

    $lastCurState = null;
    $lastActualStatus = null;
    $lastStatusMsg = null;
    $lastSshHost = null;
    $lastSshPort = null;

    $lastProbeWhy = null;
    $lastProbeKind = null;

    while (true) {

      $this->logWithTime('Polling instance ' . $instanceId);

      if ((time() - $start) > $absoluteSafetyTimeout) {
        $extra = '';
        if ($lastProbeKind && $lastProbeWhy) {
          $extra = ' Last probe failure (' . $lastProbeKind . '): ' . $lastProbeWhy;
        }
        throw new \RuntimeException('Instance exceeded absolute safety timeout for workload ' . $workload . '.' . $extra);
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
        stripos($statusMsg, 'OCI runtime create failed') !== false ||
        stripos($statusMsg, 'failed to create task for container') !== false ||
        stripos($statusMsg, 'Error response from daemon') !== false
      )) {
        throw new \RuntimeException('Container start failed: ' . $statusMsg);
      }

      // Hard fail: explicit failure states.
      if (in_array($actualStatus, ['error', 'exited', 'failed'], true)) {
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

      // Only probe after Vast reports actual_status=running. Vast can expose SSH
      // host/port while still "creating", which causes pointless probe churn.
      if ($curState === 'running' && $actualStatus === 'running' && $sshHost !== '' && $sshPort !== '') {

        $user = (string) ($info['ssh_user'] ?? 'root');

        $sshCheck = $this->sshLoginCheck($sshHost, (int) $sshPort, $user);
        if (!$sshCheck['ok']) {
          $why = (string) $sshCheck['why'];
          $sshUnavailableFor = 0;
          if (!$sshWasReachable) {
            $sshUnavailableFor = time() - $lastProgressAt;
          }
          if ($sshWasReachable && $sshLostSince === null) {
            $sshLostSince = time();
          }
          $sshLossSeconds = $sshLostSince !== null ? (time() - $sshLostSince) : 0;
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
            $sshFailureStartedAt !== null &&
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
          sleep(30);
          continue;
        }
        $sshLostSince = null;
        $sshWasReachable = true;
        $sshFailureReason = null;
        $sshFailureStartedAt = null;

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
        if (in_array($classification, $classifications, true)) {

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
        } else {
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

        sleep(30);
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

      sleep(30);
    }
  }

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
    return null;
  }

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
          $text = $line['line'] ?? $line['log'] ?? null;
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

  private function splitAndTrim(string $chunk): array {
    return array_filter(array_map('trim', explode("\n", $chunk)));
  }

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
      if ($fatal !== null) {
        throw new \RuntimeException('Container log fatality detected: ' . $fatal);
      }
    }
  }

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
    return null;
  }

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
      if ($fatal !== null) {
        throw new \RuntimeException('SSH tunnel fatality detected: ' . $fatal);
      }
    }
  }

  private function isCreationFailureMessage(string $statusMsg): bool {
    $lower = strtolower($statusMsg);
    foreach (['failed', 'error', 'denied', 'cannot', 'timeout', 'unavailable'] as $term) {
      if (str_contains($lower, $term)) {
        return true;
      }
    }
    return false;
  }

  private function isSshPortForwardingFailure(string $message): bool {
    $lower = strtolower($message);
    foreach (['remote port forwarding failed', 'port forwarding failed for'] as $term) {
      if (str_contains($lower, $term)) {
        return true;
      }
    }
    return false;
  }

  public function provisionInstanceFromOffers(
    array $filters,
    array $excludeRegions = [],
    int $limit = 5,
    ?float $maxPrice = null,
    ?float $minPrice = null,
    array $createOptions = [],
    int $maxAttempts = 5,
    int $bootTimeoutSeconds = 600
  ): array {

    $preferSuccessHosts = (bool) ($createOptions['prefer_success_hosts'] ?? true);
    $preserveOnFailure = (bool) ($createOptions['preserve_on_failure'] ?? false);
    $workload = (string) ($createOptions['workload'] ?? 'vllm');

    $globalBlacklist = $this->getGlobalBlacklist();
    $globallyBlockedHosts = array_keys($globalBlacklist);
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

    $workloadBlacklist = \Drupal::state()->get('compute_orchestrator.workload_bad_hosts', []);
    $workloadBlockedHosts = $workloadBlacklist[$workload] ?? [];

    $excludedHostIds = array_values(array_unique(array_merge(
      $globallyBlockedHosts,
      $registryBlockedHosts,
      $workloadBlockedHosts
    )));

    $offers = $this->searchOffersStructured($filters, $limit);

    if (empty($offers)) {
      throw new \RuntimeException('No offers returned from Vast.');
    }

    // Filter + sort same logic as selectBestOffer
    $valid = [];

    foreach ($offers as $offer) {

      $hostId = (string) ($offer['host_id'] ?? '');
      if (in_array($hostId, $excludedHostIds, true)) {
        continue;
      }

      $geo = (string) ($offer['geolocation'] ?? '');
      if (preg_match('/,\s*([A-Z]{2})$/', $geo, $m)) {
        $country = $m[1];
        if (in_array($country, $excludeRegions, true)) {
          continue;
        }
      }

      $price = (float) ($offer['dph_total'] ?? 0);
      if ($maxPrice !== null && $price > $maxPrice) {
        continue;
      }
      if ($minPrice !== null && $price < $minPrice) {
        continue;
      }

      $valid[] = $offer;
    }

    if (empty($valid)) {
      throw new \RuntimeException('No valid offers after filtering.');
    }

    $hostStats = $this->getHostStats();
    usort($valid, function ($a, $b) use ($hostStats, $preferSuccessHosts) {
      if ($preferSuccessHosts) {
        $hostA = (string) ($a['host_id'] ?? '');
        $hostB = (string) ($b['host_id'] ?? '');
        $successA = (int) ($hostStats[$hostA]['success'] ?? 0);
        $successB = (int) ($hostStats[$hostB]['success'] ?? 0);
        if ($successA !== $successB) {
          return $successB <=> $successA;
        }
      }

      return ($a['dph_total'] ?? 0) <=> ($b['dph_total'] ?? 0);
    });

    $attempts = 0;
    $lastFailureMessage = null;

    foreach ($valid as $offer) {

      if ($attempts >= $maxAttempts) {
        break;
      }

      $hostId = (string) ($offer['host_id'] ?? '');
      if (in_array($hostId, $excludedHostIds, true)) {
        continue;
      }

      $offerId = (string) $offer['id'];

      $this->logWithTime('Provision attempt #' . $attempts . ' using offer ' . $offerId);

      $attempts++;

      $contractId = null;

      try {

        $create = $this->createInstance(
          $offerId,
          $createOptions['image'],
          $createOptions['options'] ?? []
        );

        $contractId = (string) $create['new_contract'];

        $this->logWithTime('Waiting for running + SSH for contract ' . $contractId);

        $info = $this->waitForRunningAndSsh(
          $contractId,
          $workload,
          $bootTimeoutSeconds
        );

        $this->recordHostSuccess($hostId);

        return [
          'contract_id' => $contractId,
          'instance_info' => $info,
          'offer' => $offer,
        ];


      } catch (\Throwable $e) {

        $lastFailureMessage = $e->getMessage();
        $isInfraFatal = $this->isInfrastructureFatalFailure($lastFailureMessage);
        if ($e instanceof WorkloadReadinessException) {
          $isInfraFatal = $e->getFailureClass() === FailureClass::INFRA_FATAL;
        }

        if ($e instanceof WorkloadReadinessException) {

          $failureClass = $e->getFailureClass();

          if ($failureClass === FailureClass::WORKLOAD_INCOMPATIBLE && $hostId !== '') {

            $key = 'compute_orchestrator.workload_bad_hosts';
            $all = \Drupal::state()->get($key, []);

            if (!isset($all[$workload]) || !is_array($all[$workload])) {
              $all[$workload] = [];
            }

            if (!in_array($hostId, $all[$workload], true)) {
              $all[$workload][] = $hostId;
              \Drupal::state()->set($key, $all);
              $this->logWithTime("Added host {$hostId} to WORKLOAD blacklist [{$workload}]");
            }
          }
        }


        if ($isInfraFatal && $hostId !== '') {
          $this->incrementInfraFailureStats($hostId);
          $this->addToGlobalBlacklist($hostId);
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
        $logProvisionTraces = (bool) \Drupal::state()->get('compute_orchestrator.log_provision_exception_traces', FALSE);
        if ($logProvisionTraces) {
          $this->logWithTime('Trace: ' . $e->getTraceAsString());
        }
        $this->logWithTime('--- PROVISION EXCEPTION END ---');

        if (!$preserveOnFailure && !empty($contractId)) {
          try {
            $this->destroyInstance($contractId);
          } catch (\Throwable $destroyError) {
            $this->logWithTime(sprintf(
              'Destroy failed: contract=%s error=%s',
              (string) $contractId,
              $destroyError->getMessage()
            ));
          }
        } else {
          $this->logWithTime('Preserving failed instance for investigation: contract=' . $contractId);
        }

        if ($hostId !== '') {
          $excludedHostIds[] = $hostId;
          if ($isInfraFatal) {
            $this->badHosts->add($hostId);
            $this->logWithTime('Added bad host to registry: ' . $hostId);
          }
        }

        continue;
      }

    }

    throw new \RuntimeException(
      'All provisioning attempts failed.' . ($lastFailureMessage ? ' Last error: ' . $lastFailureMessage : '')
    );
  }

  private function sshLoginCheck(string $sshHost, int $sshPort, string $sshUser): array {

    $keyPath = $this->resolveSshKeyPath();
    if (!$keyPath) {
      return [
        'ok' => false,
        'why' => 'SSH key not found at: ' . ($this->getSshKeyCandidate() ?: '(empty)'),
      ];
    }

    $process = new Process([
      'ssh',
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
    } catch (\Throwable $e) {
      return [
        'ok' => false,
        'why' => 'ssh probe exception: ' . $e->getMessage(),
      ];
    }

    if ($process->isSuccessful()) {
      return ['ok' => true, 'why' => ''];
    }

    $stderr = trim($process->getErrorOutput());
    $stdout = trim($process->getOutput());

    return [
      'ok' => false,
      'why' => sprintf(
        'ssh probe failed: exit=%s stderr=%s stdout=%s',
        (string) $process->getExitCode(),
        $stderr !== '' ? $stderr : '(empty)',
        $stdout !== '' ? $stdout : '(empty)'
      ),
    ];
  }

  private function executeWorkloadProbesViaSsh(array $commands, string $sshHost, int $sshPort, string $sshUser): array {
    $keyPath = $this->resolveSshKeyPath();
    if (!$keyPath) {
      return [
        'ssh_key' => [
          'ok' => false,
          'transport_ok' => false,
          'failure_kind' => 'transport',
          'exit_code' => null,
          'stdout' => '',
          'stderr' => 'SSH key not found at: ' . ($this->getSshKeyCandidate() ?: '(empty)'),
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

  private function formatProbeFailure(string $classification, array $probeResults): string {
    $parts = ['class=' . $classification];
    foreach ($probeResults as $name => $result) {
      $parts[] = sprintf(
        '%s(ok=%s transport_ok=%s kind=%s exit=%s stderr=%s exception=%s)',
        (string) $name,
        ($result['ok'] ?? false) ? '1' : '0',
        ($result['transport_ok'] ?? false) ? '1' : '0',
        (string) ($result['failure_kind'] ?? 'unknown'),
        isset($result['exit_code']) ? (string) $result['exit_code'] : 'null',
        trim((string) ($result['stderr'] ?? '')) !== '' ? trim((string) ($result['stderr'] ?? '')) : '(empty)',
        trim((string) ($result['exception'] ?? '')) !== '' ? trim((string) ($result['exception'] ?? '')) : '(none)'
      );
    }

    $logs = trim((string) ($probeResults['logs']['stdout'] ?? ''));
    if ($logs !== '') {
      $parts[] = 'logs=' . $logs;
    }

    return implode(' | ', $parts);
  }

  private function getHostStats(): array {
    $stats = \Drupal::state()->get('compute_orchestrator.host_stats', []);
    return is_array($stats) ? $stats : [];
  }

  private function saveHostStats(array $stats): void {
    \Drupal::state()->set('compute_orchestrator.host_stats', $stats);
  }

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

  private function getHostCdiFailures(): array {
    $value = \Drupal::state()->get('compute_orchestrator.host_cdi_failures', []);
    return is_array($value) ? $value : [];
  }

  private function saveHostCdiFailures(array $failures): void {
    \Drupal::state()->set('compute_orchestrator.host_cdi_failures', $failures);
  }

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

  private function getGlobalBlacklist(): array {
    $value = \Drupal::state()->get('compute_orchestrator.global_bad_hosts', []);
    return is_array($value) ? $value : [];
  }

  private function addToGlobalBlacklist(string $hostId): void {
    if ($hostId === '') {
      return;
    }

    $bad = $this->getGlobalBlacklist();
    $bad[$hostId] = time();
    \Drupal::state()->set('compute_orchestrator.global_bad_hosts', $bad);
  }

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
      return true;
    }
    }

    return false;
  }

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

  private function resolveSshKeyPath(): ?string {
    $candidate = $this->getSshKeyCandidate();
    if ($candidate === '') {
      return null;
    }

    return file_exists($candidate) ? $candidate : null;
  }

  private function getSshKeyCandidate(): string {
    $keyPath = getenv('VAST_SSH_KEY_PATH') ?: '';
    if ($keyPath !== '') {
      return $keyPath;
    }

    $home = getenv('HOME') ?: '';
    if ($home === '') {
      return '';
    }

    return rtrim($home, '/') . '/.ssh/id_rsa_vastai';
  }

  private function logWithTime(string $message): void {
    error_log('[' . date('Y-m-d H:i:s') . '] ' . $message);
  }

}
