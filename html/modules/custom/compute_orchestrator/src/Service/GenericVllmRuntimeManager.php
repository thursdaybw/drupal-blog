<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\compute_orchestrator\Exception\WorkloadReadinessException;
use Drupal\compute_orchestrator\Service\Workload\FailureClass;
use Drupal\Core\Config\ConfigFactoryInterface;
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
    private readonly SshProbeExecutorInterface $sshProbeExecutor,
    private readonly SshKeyPathResolverInterface $sshKeyPathResolver,
    private readonly ConfigFactoryInterface $configFactory,
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

    $maxHourlyPrice = (float) ($this->configFactory->get('compute_orchestrator.settings')->get('max_hourly_price') ?? 0.5);

    $createOptions = [
      'workload' => (string) ($workloadDefinition['mode'] ?? 'generic'),
      'image' => $image,
      'bootstrap_only' => TRUE,
      'options' => [
        'disk' => 40,
        'runtype' => 'ssh',
        'target_state' => 'running',
        'env' => [
          '-p 8000:8000' => '1',
        ],
      ],
    ];

    if (isset($workloadDefinition['on_contract_created']) && is_callable($workloadDefinition['on_contract_created'])) {
      $createOptions['on_contract_created'] = $workloadDefinition['on_contract_created'];
    }

    return $this->vastClient->provisionInstanceFromOffers(
      $filters,
      ['RU', 'CN', 'IR', 'KP', 'SY'],
      20,
      $maxHourlyPrice,
      NULL,
      $createOptions,
      5,
      600,
    );
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

    $sliceTimeoutSeconds = max(5, $timeoutSeconds);

    while (TRUE) {
      if ((time() - $start) > $sliceTimeoutSeconds) {
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
          $this->sshKeyPathResolver->resolveRequiredPath(),
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
    $statusOutput = trim((string) ($statusResult['stdout'] ?? ''));
    if ($statusOutput !== '') {
      $this->logger->info('Remote status before start: {status}', ['status' => $statusOutput]);
    }

    if ($this->shouldProbeForMatchingWarmupProcess($statusOutput)) {
      $processResult = $this->sshProbeExecutor->run($context, new SshProbeRequest(
        'processes_before_start',
        "ps -ef | grep -E 'vllm|api_server|openai' | grep -v grep || true",
        30,
      ));
      $processOutput = (string) ($processResult['stdout'] ?? '');
      if ($this->processOutputContainsRequestedModel($processOutput, $model)) {
        $this->logger->info(
          'Skipping start-model because requested model is already warming.',
          [
            'mode' => $mode,
            'model' => $model,
          ],
        );
        return;
      }

      if (!empty($workloadDefinition['fail_stale_without_process_after_warmup'])) {
        throw new WorkloadReadinessException(
          FailureClass::RUNTIME_LOST,
          'Runtime lost: status.sh reported stale and no matching vLLM process exists after warmup progress was observed.',
        );
      }
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
      throw new \RuntimeException($this->buildStartWorkloadFailureMessage($context, $startCommand, $startResult));
    }
  }

  /**
   * Determines whether remote status should be checked for warming processes.
   */
  private function shouldProbeForMatchingWarmupProcess(string $statusOutput): bool {
    if ($statusOutput === '') {
      return FALSE;
    }
    return preg_match('/(^|\s)state=stale(\s|$)/', $statusOutput) === 1;
  }

  /**
   * Checks whether a process listing contains the requested vLLM model.
   */
  private function processOutputContainsRequestedModel(string $output, string $model): bool {
    if (trim($output) === '' || trim($model) === '') {
      return FALSE;
    }

    foreach (preg_split('/\R/', $output) as $line) {
      if (stripos($line, 'vllm') === FALSE && stripos($line, 'api_server') === FALSE) {
        continue;
      }
      if (str_contains($line, '--model ' . $model) || str_contains($line, '--model=' . $model)) {
        return TRUE;
      }
    }

    return FALSE;
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
   * Builds an actionable failure message for the remote start-model probe.
   *
   * The SSH process can fail with an empty stderr/stdout pair, especially when
   * a remote port flakes during Vast warmup. Operators still need the probe
   * name, exit code, SSH target, and command to decide whether this was network
   * flakiness, an image bug, or a bad workload command.
   *
   * @param \Drupal\compute_orchestrator\Service\SshConnectionContext $context
   *   SSH target used for the failed probe.
   * @param string $startCommand
   *   Remote start command sent to the instance.
   * @param array<string,mixed> $startResult
   *   Normalized SSH probe result.
   */
  private function buildStartWorkloadFailureMessage(
    SshConnectionContext $context,
    string $startCommand,
    array $startResult,
  ): string {
    $details = [
      'probe=start_model',
      'host=' . $context->host,
      'port=' . (string) $context->port,
      'user=' . $context->user,
      'transport_ok=' . $this->formatProbeBoolean($startResult['transport_ok'] ?? NULL),
      'failure_kind=' . (string) ($startResult['failure_kind'] ?? 'unknown'),
      'exit_code=' . $this->formatProbeValue($startResult['exit_code'] ?? NULL),
      'stderr=' . $this->formatProbeValue($startResult['stderr'] ?? ''),
      'stdout=' . $this->formatProbeValue($startResult['stdout'] ?? ''),
      'exception=' . $this->formatProbeValue($startResult['exception'] ?? ''),
      'command=' . $startCommand,
    ];
    return 'Remote start-model failed: ' . implode(' ', $details);
  }

  /**
   * Formats nullable probe values without losing that they were empty.
   */
  private function formatProbeValue(mixed $value): string {
    if ($value === NULL || $value === '') {
      return '(empty)';
    }
    return str_replace(["\n", "\r"], ' ', trim((string) $value));
  }

  /**
   * Formats a normalized SSH probe boolean field.
   */
  private function formatProbeBoolean(mixed $value): string {
    if ($value === TRUE) {
      return 'true';
    }
    if ($value === FALSE) {
      return 'false';
    }
    return '(unknown)';
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
      $this->sshKeyPathResolver->resolveRequiredPath(),
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

}
