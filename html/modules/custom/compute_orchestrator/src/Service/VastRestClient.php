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
    $absoluteSafetyTimeout = max(5400, $timeoutSeconds + $adapter->getStartupTimeoutSeconds());
    $stallThresholdSeconds = 600;
    $sshLossThresholdSeconds = 300;
    $lastProgressAt = $start;
    $sshLostSince = null;
    $sshWasReachable = false;
    $previousProbeResults = [];

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

      // Ready check: instance running AND we can SSH AND workload probe passes.
      if ($curState === 'running' && $sshHost !== '' && $sshPort !== '') {

        $user = (string) ($info['ssh_user'] ?? 'root');

        $sshCheck = $this->sshLoginCheck($sshHost, (int) $sshPort, $user);
        if (!$sshCheck['ok']) {
          $why = (string) $sshCheck['why'];
          if ($sshWasReachable && $sshLostSince === null) {
            $sshLostSince = time();
          }
          $sshLossSeconds = $sshLostSince !== null ? (time() - $sshLostSince) : 0;
          if ($lastProbeKind !== 'ssh' || $lastProbeWhy !== $why) {
            $this->logWithTime('PROBE ssh not ready: ' . $why);
            $this->logger->notice('WORKLOAD ssh not ready: {why}', ['why' => $why]);
            $lastProbeKind = 'ssh';
            $lastProbeWhy = $why;
          }
          if ($sshWasReachable && $sshLossSeconds >= $sshLossThresholdSeconds) {
            throw new \RuntimeException('Connectivity loss: SSH probe unavailable for ' . $sshLossSeconds . ' seconds.');
          }
          sleep(30);
          continue;
        }
        $sshLostSince = null;
        $sshWasReachable = true;

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

        if (in_array($classification, [FailureClass::INFRA_FATAL, FailureClass::WORKLOAD_FATAL], true)) {
          throw new WorkloadReadinessException($classification, 'Fatal workload startup failure: ' . $why);
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
      if ($stalledFor >= $stallThresholdSeconds) {
        throw new \RuntimeException(
          'Instance stalled before workload readiness for ' . $stalledFor . ' seconds.'
        );
      }

      sleep(30);
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

  public function provisionInstanceFromOffers(
    array $filters,
    array $excludeHosts = [],
    array $excludeRegions = [],
    int $limit = 5,
    ?float $maxPrice = null,
    array $createOptions = [],
    int $maxAttempts = 5,
    int $bootTimeoutSeconds = 600
  ): array {

    $preferSuccessHosts = (bool) ($createOptions['prefer_success_hosts'] ?? true);
    $preserveOnFailure = (bool) ($createOptions['preserve_on_failure'] ?? false);
    $workload = (string) ($createOptions['workload'] ?? 'vllm');

    $persistedBadHosts = $this->badHosts->all();
    $globalBlacklist = $this->getGlobalBlacklist();
    $globallyBlockedHosts = array_keys($globalBlacklist);
    $excludeHosts = array_values(array_unique(array_merge($excludeHosts, $persistedBadHosts, $globallyBlockedHosts)));
    if (!empty($persistedBadHosts)) {
      $this->logWithTime('Loaded persisted bad hosts: ' . implode(',', $persistedBadHosts));
    }
    if (!empty($globallyBlockedHosts)) {
      $this->logWithTime('Loaded global blacklist hosts: ' . implode(',', $globallyBlockedHosts));
    }

    $offers = $this->searchOffersStructured($filters, $limit);

    if (empty($offers)) {
      throw new \RuntimeException('No offers returned from Vast.');
    }

    // Filter + sort same logic as selectBestOffer
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
    $excludedHostIds = array_values(array_unique(array_merge($persistedBadHosts, $globallyBlockedHosts)));
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
        if ($isInfraFatal && $hostId !== '') {
          $this->recordHostInfraFailure($hostId);
          $this->addToGlobalBlacklist($hostId);
        }

        $this->logWithTime('--- PROVISION EXCEPTION START ---');
        $this->logWithTime('Offer: ' . $offerId);
        $this->logWithTime('Host: ' . $hostId);
        $this->logWithTime('Message: ' . $e->getMessage());
        $this->logWithTime('File: ' . $e->getFile());
        $this->logWithTime('Line: ' . $e->getLine());
        $this->logWithTime('Trace: ' . $e->getTraceAsString());
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

  private function recordHostInfraFailure(string $hostId): void {
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
      'cuda',
      'gpu error, unable to start instance',
      'unsupported display driver / cuda driver combination',
    ] as $marker) {
      if (str_contains($lower, $marker)) {
        return true;
      }
    }

    return false;
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
