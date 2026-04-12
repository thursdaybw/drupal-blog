<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Controls generic vLLM runtime bootstrapping and workload startup.
 */
final class GenericVllmRuntimeManager implements GenericVllmRuntimeManagerInterface {

  /**
   * Module logger channel.
   */
  private LoggerInterface $logger;

  public function __construct(
    private readonly VastRestClientInterface $vastClient,
    private readonly SshProbeExecutor $sshProbeExecutor,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('compute_orchestrator');
  }

  /**
   * {@inheritdoc}
   */
  public function provisionFresh(array $workloadDefinition, string $image): array {
    $filters = [
      'reliability' => ['gte' => 0.98],
      'gpu_ram' => ['gte' => ((int) $workloadDefinition['gpu_ram_gte']) * 1024],
      'num_gpus' => ['eq' => 1],
      'rentable' => ['eq' => TRUE],
      'verification' => ['eq' => 'verified'],
      'direct_port_count' => ['gte' => 8],
    ];

    $offer = $this->vastClient->selectBestOffer(
      $filters,
      [],
      ['RU', 'CN', 'IR', 'KP', 'SY'],
      20,
    );

    if ($offer === NULL || empty($offer['id'])) {
      throw new \RuntimeException('No suitable Vast offer matched the generic vLLM filters.');
    }

    $create = $this->vastClient->createInstance(
      (string) $offer['id'],
      $image,
      [
        'disk' => 40,
        'runtype' => 'ssh',
        'target_state' => 'running',
        'env' => [
          '-p 8000:8000' => '1',
        ],
      ],
    );

    $contractId = (string) ($create['new_contract'] ?? '');
    if ($contractId === '') {
      throw new \RuntimeException('Vast did not return a contract ID for fresh provisioning.');
    }

    $instanceInfo = $this->vastClient->showInstance($contractId);

    return [
      'contract_id' => $contractId,
      'instance_info' => $instanceInfo,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function waitForSshBootstrap(string $contractId, int $timeoutSeconds = 600): array {
    if ($contractId === '') {
      throw new \RuntimeException('Vast did not return a contract ID.');
    }

    $start = time();
    $lastProgressAt = $start;
    $lastSnapshot = [];
    $stallThresholdSeconds = 600;

    while (TRUE) {
      if ((time() - $start) > max(60, $timeoutSeconds)) {
        throw new \RuntimeException('Instance exceeded SSH bootstrap timeout.');
      }

      $info = $this->vastClient->showInstance($contractId);
      $snapshot = [
        'cur_state' => (string) ($info['cur_state'] ?? ''),
        'actual_status' => (string) ($info['actual_status'] ?? ''),
        'status_msg' => (string) ($info['status_msg'] ?? ''),
        'ssh_host' => (string) ($info['ssh_host'] ?? ''),
        'ssh_port' => (string) ($info['ssh_port'] ?? ''),
      ];

      if ($snapshot !== $lastSnapshot) {
        $this->logger->info(
          'BOOTSTRAP {contract} cur_state={cur_state} actual_status={actual_status} ssh={ssh_host}:{ssh_port} msg={status_msg}',
          [
            'contract' => $contractId,
            'cur_state' => $snapshot['cur_state'] !== '' ? $snapshot['cur_state'] : '(null)',
            'actual_status' => $snapshot['actual_status'] !== '' ? $snapshot['actual_status'] : '(null)',
            'ssh_host' => $snapshot['ssh_host'] !== '' ? $snapshot['ssh_host'] : '(null)',
            'ssh_port' => $snapshot['ssh_port'] !== '' ? $snapshot['ssh_port'] : '(null)',
            'status_msg' => $snapshot['status_msg'] !== '' ? $snapshot['status_msg'] : '(null)',
          ],
        );
        $lastSnapshot = $snapshot;
        $lastProgressAt = time();
      }

      if ($this->isBootstrapFailureMessage($snapshot['status_msg'])) {
        $this->logger->error(
          'BOOTSTRAP failure contract={contract} cur_state={cur_state} actual_status={actual_status} ssh={ssh_host}:{ssh_port} msg={status_msg}',
          [
            'contract' => $contractId,
            'cur_state' => $snapshot['cur_state'] !== '' ? $snapshot['cur_state'] : '(null)',
            'actual_status' => $snapshot['actual_status'] !== '' ? $snapshot['actual_status'] : '(null)',
            'ssh_host' => $snapshot['ssh_host'] !== '' ? $snapshot['ssh_host'] : '(null)',
            'ssh_port' => $snapshot['ssh_port'] !== '' ? $snapshot['ssh_port'] : '(null)',
            'status_msg' => $snapshot['status_msg'] !== '' ? $snapshot['status_msg'] : '(null)',
          ],
        );
        throw new \RuntimeException('Container failed during bootstrap: ' . $snapshot['status_msg']);
      }

      $isFailureState = in_array($snapshot['actual_status'], ['error', 'exited', 'failed'], TRUE);

      if (
        $snapshot['cur_state'] === 'running' &&
        $snapshot['ssh_host'] !== '' &&
        $snapshot['ssh_port'] !== ''
      ) {
        $context = new SshConnectionContext(
          $snapshot['ssh_host'],
          (int) $snapshot['ssh_port'],
          (string) ($info['ssh_user'] ?? 'root'),
          $this->resolveSshKeyPath(),
        );
        $probe = $this->sshProbeExecutor->run($context, new SshProbeRequest(
          'bootstrap_login_check',
          'echo ok',
          30,
        ));
        if (($probe['ok'] ?? FALSE) === TRUE && ($probe['stdout'] ?? '') === 'ok') {
          if ($isFailureState) {
            $this->logger->warning(
              'BOOTSTRAP state mismatch contract={contract}: SSH is reachable, but actual_status={actual_status} while cur_state={cur_state} msg={status_msg}. Proceeding as running.',
              [
                'contract' => $contractId,
                'actual_status' => $snapshot['actual_status'],
                'cur_state' => $snapshot['cur_state'],
                'status_msg' => $snapshot['status_msg'] !== '' ? $snapshot['status_msg'] : '(null)',
              ],
            );
          }
          return $info;
        }
        $this->logger->warning(
          'BOOTSTRAP login check failed contract={contract} cur_state={cur_state} actual_status={actual_status} probe_transport_ok={transport_ok} probe_exit={exit_code} probe_stderr={stderr} probe_exception={exception}',
          [
            'contract' => $contractId,
            'cur_state' => $snapshot['cur_state'] !== '' ? $snapshot['cur_state'] : '(null)',
            'actual_status' => $snapshot['actual_status'] !== '' ? $snapshot['actual_status'] : '(null)',
            'transport_ok' => (($probe['transport_ok'] ?? FALSE) === TRUE) ? '1' : '0',
            'exit_code' => isset($probe['exit_code']) ? (string) $probe['exit_code'] : '(null)',
            'stderr' => trim((string) ($probe['stderr'] ?? '')) !== '' ? trim((string) $probe['stderr']) : '(empty)',
            'exception' => trim((string) ($probe['exception'] ?? '')) !== '' ? trim((string) $probe['exception']) : '(none)',
          ],
        );
      }

      if ($isFailureState && $this->isBootstrapStatusMismatch($snapshot)) {
        $this->logger->warning(
          'BOOTSTRAP mismatch contract={contract} actual_status={actual_status} ssh={ssh_host}:{ssh_port} msg={status_msg}. Waiting for Vast status to converge.',
          [
            'contract' => $contractId,
            'actual_status' => $snapshot['actual_status'] !== '' ? $snapshot['actual_status'] : '(null)',
            'ssh_host' => $snapshot['ssh_host'] !== '' ? $snapshot['ssh_host'] : '(null)',
            'ssh_port' => $snapshot['ssh_port'] !== '' ? $snapshot['ssh_port'] : '(null)',
            'status_msg' => $snapshot['status_msg'] !== '' ? $snapshot['status_msg'] : '(null)',
          ],
        );
        sleep(10);
        continue;
      }

      if ($isFailureState) {
        $this->logger->error(
          'BOOTSTRAP terminal state contract={contract} cur_state={cur_state} actual_status={actual_status} ssh={ssh_host}:{ssh_port} msg={status_msg}',
          [
            'contract' => $contractId,
            'cur_state' => $snapshot['cur_state'] !== '' ? $snapshot['cur_state'] : '(null)',
            'actual_status' => $snapshot['actual_status'] !== '' ? $snapshot['actual_status'] : '(null)',
            'ssh_host' => $snapshot['ssh_host'] !== '' ? $snapshot['ssh_host'] : '(null)',
            'ssh_port' => $snapshot['ssh_port'] !== '' ? $snapshot['ssh_port'] : '(null)',
            'status_msg' => $snapshot['status_msg'] !== '' ? $snapshot['status_msg'] : '(null)',
          ],
        );
        throw new \RuntimeException(
          'Instance entered failure state: actual_status='
          . $snapshot['actual_status']
          . ', cur_state='
          . ($snapshot['cur_state'] !== '' ? $snapshot['cur_state'] : '(null)')
          . ', ssh='
          . ($snapshot['ssh_host'] !== '' ? $snapshot['ssh_host'] : '(null)')
          . ':'
          . ($snapshot['ssh_port'] !== '' ? $snapshot['ssh_port'] : '(null)')
          . ', status_msg='
          . ($snapshot['status_msg'] !== '' ? $snapshot['status_msg'] : '(null)')
        );
      }

      $stalledFor = time() - $lastProgressAt;
      if ($stalledFor >= $stallThresholdSeconds) {
        throw new \RuntimeException('Instance stalled before SSH bootstrap for ' . $stalledFor . ' seconds.');
      }

      sleep(10);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function startWorkload(array $instanceInfo, array $workloadDefinition): void {
    $context = $this->buildSshContext($instanceInfo);
    $mode = (string) ($workloadDefinition['mode'] ?? '');
    $model = (string) ($workloadDefinition['model'] ?? '');

    $statusResult = $this->sshProbeExecutor->run($context, new SshProbeRequest(
      'status_before_start',
      '/opt/vllm/bin/status.sh || true',
      30,
    ));
    if (($statusResult['stdout'] ?? '') !== '') {
      $this->logger->info('Remote status before start: {status}', ['status' => trim((string) $statusResult['stdout'])]);
    }

    $startCommand = '';
    if (!empty($workloadDefinition['max_model_len'])) {
      // Temporary runtime override: the published generic image currently
      // defaults MAX_MODEL_LEN to 4096. Qwen previously worked at 16384, so we
      // keep that effective value here until the image default is rebuilt.
      $startCommand .= 'export MAX_MODEL_LEN=' . (int) $workloadDefinition['max_model_len'] . '; ';
    }
    $startCommand .= '/opt/vllm/bin/start-model.sh ' . escapeshellarg($mode) . ' ' . escapeshellarg($model);

    $startResult = $this->sshProbeExecutor->run($context, new SshProbeRequest(
      'start_model',
      $startCommand,
      180,
    ));

    if (($startResult['ok'] ?? FALSE) !== TRUE) {
      throw new \RuntimeException('Remote start-model failed: ' . trim((string) ($startResult['stderr'] ?? $startResult['stdout'] ?? 'unknown error')));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function stopWorkload(array $instanceInfo): void {
    $context = $this->buildSshContext($instanceInfo);
    $stopResult = $this->sshProbeExecutor->run($context, new SshProbeRequest(
      'stop_model',
      '/opt/vllm/bin/stop-model.sh || true',
      60,
    ));

    if (($stopResult['transport_ok'] ?? FALSE) !== TRUE) {
      throw new \RuntimeException('Remote stop-model transport failed: ' . trim((string) ($stopResult['exception'] ?? 'unknown error')));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function waitForWorkloadReady(string $contractId, int $timeoutSeconds = 900): array {
    return $this->vastClient->waitForRunningAndSsh($contractId, 'vllm', $timeoutSeconds);
  }

  /**
   * Builds an SSH connection context from instance metadata.
   *
   * @param array<string,mixed> $instanceInfo
   *   Vast instance metadata.
   */
  private function buildSshContext(array $instanceInfo): SshConnectionContext {
    $context = new SshConnectionContext(
      (string) ($instanceInfo['ssh_host'] ?? ''),
      (int) ($instanceInfo['ssh_port'] ?? 0),
      (string) ($instanceInfo['ssh_user'] ?? 'root'),
      $this->resolveSshKeyPath(),
    );

    if ($context->host === '' || $context->port === 0) {
      throw new \RuntimeException('Instance info did not contain SSH host/port.');
    }

    return $context;
  }

  /**
   * Detects bootstrap messages that indicate container creation failure.
   */
  private function isBootstrapFailureMessage(string $statusMessage): bool {
    return $statusMessage !== '' && (
      stripos($statusMessage, 'OCI runtime create failed') !== FALSE ||
      stripos($statusMessage, 'failed to create task for container') !== FALSE ||
      stripos($statusMessage, 'Error response from daemon') !== FALSE ||
      stripos($statusMessage, 'no such container') !== FALSE
    );
  }

  /**
   * Detects a stale Vast status mismatch during SSH bootstrap.
   *
   * This covers the case where the instance reports failure while also
   * reporting a successful SSH runtime startup.
   *
   * @param array<string,string> $snapshot
   *   Normalized instance snapshot.
   */
  private function isBootstrapStatusMismatch(array $snapshot): bool {
    if (($snapshot['cur_state'] ?? '') !== 'running') {
      return FALSE;
    }

    if (($snapshot['ssh_host'] ?? '') === '' || ($snapshot['ssh_port'] ?? '') === '') {
      return FALSE;
    }

    $statusMessage = strtolower((string) ($snapshot['status_msg'] ?? ''));
    if ($statusMessage === '') {
      return FALSE;
    }

    return str_contains($statusMessage, 'success, running')
      && str_contains($statusMessage, '/ssh');
  }

  /**
   * Resolves the SSH private key used for Vast instance control.
   */
  private function resolveSshKeyPath(): string {
    $candidate = getenv('VAST_SSH_PRIVATE_KEY_CONTAINER_PATH') ?: '';
    if ($candidate === '') {
      $home = getenv('HOME') ?: '';
      if ($home !== '') {
        $candidate = rtrim($home, '/') . '/.ssh/id_rsa_vastai';
      }
    }

    if ($candidate === '' || !file_exists($candidate)) {
      throw new \RuntimeException('VAST_SSH_PRIVATE_KEY_CONTAINER_PATH is not set to a readable private key.');
    }

    return $candidate;
  }

}
