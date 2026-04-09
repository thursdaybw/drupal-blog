<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\Core\State\StateInterface;
use Drupal\compute_orchestrator\Service\SshConnectionContext;
use Drupal\compute_orchestrator\Service\SshProbeExecutor;
use Drupal\compute_orchestrator\Service\SshProbeRequest;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'compute:provision-vllm-generic',
  description: 'Provision a generic vLLM Vast node, start a selected workload over SSH, and wait for readiness.',
)]
final class VllmGenericProvisionCommand extends Command {

  private const DEFAULT_GENERIC_IMAGE = 'thursdaybw/vllm-generic:2026-04-generic-node';

  public function __construct(
    private readonly VastRestClientInterface $vastClient,
    private readonly SshProbeExecutor $sshProbeExecutor,
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption(
      'preserve',
      null,
      InputOption::VALUE_NONE,
      'Preserve the instance after success or failure.'
    );
    $this->addOption(
      'workload',
      null,
      InputOption::VALUE_REQUIRED,
      'Workload to start on the generic node (qwen-vl | whisper).',
      'qwen-vl'
    );
    $this->addOption(
      'image',
      null,
      InputOption::VALUE_REQUIRED,
      'Docker image to provision.',
      self::DEFAULT_GENERIC_IMAGE
    );
    $this->addOption(
      'model',
      null,
      InputOption::VALUE_REQUIRED,
      'Optional model override. Defaults to the workload model.'
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln('Starting compute:provision-vllm-generic...');

    $preserve = (bool) $input->getOption('preserve');
    $workloadDefinition = $this->resolveWorkloadDefinition(trim((string) $input->getOption('workload')));
    $image = trim((string) $input->getOption('image'));
    $model = trim((string) $input->getOption('model')) ?: $workloadDefinition['model'];

    $filters = [
      'reliability' => ['gte' => 0.98],
      'gpu_ram' => ['gte' => $workloadDefinition['gpu_ram_gte'] * 1024],
      'num_gpus' => ['eq' => 1],
      'rentable' => ['eq' => true],
      'verification' => ['eq' => 'verified'],
      'direct_port_count' => ['gte' => 8],
    ];

    $contractId = '';

    try {
      $result = $this->vastClient->provisionInstanceFromOffers(
        $filters,
        ['RU', 'CN', 'IR', 'KP', 'SY'],
        20,
        1.0,
        0.20,
        [
          'workload' => 'ssh-bootstrap',
          'preserve_on_failure' => $preserve,
          'image' => $image,
          'options' => [
            'disk' => 40,
            'runtype' => 'ssh',
            'target_state' => 'running',
            'env' => [
              '-p 8000:8000' => '1',
            ],
          ],
        ],
        5,
        600
      );

      $contractId = (string) ($result['contract_id'] ?? '');
      $instanceInfo = (array) ($result['instance_info'] ?? []);
      if ($contractId === '' || $instanceInfo === []) {
        throw new \RuntimeException('Provisioning returned no contract or bootstrap instance info.');
      }

      $output->writeln('Provisioned contract: ' . $contractId);
      $output->writeln('Image: ' . $image);
      $output->writeln('Bootstrap SSH: ' . ($instanceInfo['ssh_host'] ?? '') . ':' . (string) ($instanceInfo['ssh_port'] ?? ''));

      $this->startWorkloadOnGenericNode($instanceInfo, $workloadDefinition, $model, $output);

      $readyInfo = $this->vastClient->waitForRunningAndSsh($contractId, 'vllm', 900);

      $publicHost = (string) ($readyInfo['public_ipaddr'] ?? '');
      $publicPort = $this->extractPublicPort($readyInfo);

      $this->state->set('compute.vllm_contract_id', $contractId);
      $this->state->set('compute.vllm_image', $image);
      $this->state->set('compute.vllm_model', $model);
      $this->state->set('compute.vllm_workload_mode', $workloadDefinition['mode']);

      if ($publicHost !== '' && $publicPort !== '') {
        $this->state->set('compute.vllm_host', $publicHost);
        $this->state->set('compute.vllm_port', $publicPort);
        $this->state->set('compute.vllm_url', 'http://' . $publicHost . ':' . $publicPort);
        $this->state->set('compute.vllm_set_at', time());
        $output->writeln('Public VLM Host: ' . $publicHost);
        $output->writeln('Public VLM Port: ' . $publicPort);
      }
      else {
        $output->writeln('WARNING: Public VLM port mapping not detected.');
      }

      $output->writeln('Workload mode: ' . $workloadDefinition['mode']);
      $output->writeln('Model: ' . $model);
      $output->writeln('State: ' . ($readyInfo['cur_state'] ?? 'unknown'));

      if (!$preserve) {
        $this->vastClient->destroyInstance($contractId);
        $output->writeln('Destroyed.');
      }
      else {
        $output->writeln('Instance preserved for testing.');
      }

      return self::SUCCESS;
    }
    catch (\Throwable $e) {
      $output->writeln($e->getMessage());
      if ($preserve && $contractId !== '') {
        $output->writeln('Instance preserved for debugging: ' . $contractId);
      }
      return self::FAILURE;
    }
  }

  private function resolveWorkloadDefinition(string $requestedWorkload): array {
    $workloadMap = [
      'qwen-vl' => [
        'mode' => 'qwen-vl',
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        // Preserve the previously working Qwen context window until the
        // generic image is rebuilt with the same sane default. The current
        // image fallback of 4096 is too small for the real listing workflow.
        'max_model_len' => 16384,
      ],
      'whisper' => [
        'mode' => 'whisper',
        'model' => 'openai/whisper-large-v3-turbo',
        'gpu_ram_gte' => 16,
      ],
    ];

    if (!isset($workloadMap[$requestedWorkload])) {
      throw new \InvalidArgumentException('Unsupported workload "' . $requestedWorkload . '". Expected qwen-vl or whisper.');
    }

    return $workloadMap[$requestedWorkload];
  }

  private function startWorkloadOnGenericNode(array $instanceInfo, array $workloadDefinition, string $model, OutputInterface $output): void {
    $context = new SshConnectionContext(
      (string) ($instanceInfo['ssh_host'] ?? ''),
      (int) ($instanceInfo['ssh_port'] ?? 0),
      (string) ($instanceInfo['ssh_user'] ?? 'root'),
      $this->resolveSshKeyPath()
    );

    if ($context->host === '' || $context->port === 0) {
      throw new \RuntimeException('Instance info did not contain SSH host/port.');
    }

    $mode = (string) ($workloadDefinition['mode'] ?? '');
    $output->writeln('Starting workload over SSH: ' . $mode . ' -> ' . $model);

    $statusResult = $this->sshProbeExecutor->run($context, new SshProbeRequest(
      'status_before_start',
      '/opt/vllm/bin/status.sh || true',
      30
    ));
    if (($statusResult['stdout'] ?? '') !== '') {
      $output->writeln('Remote status before start: ' . trim((string) $statusResult['stdout']));
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
      180
    ));

    if (($startResult['ok'] ?? false) !== true) {
      throw new \RuntimeException('Remote start-model failed: ' . trim((string) ($startResult['stderr'] ?? $startResult['stdout'] ?? 'unknown error')));
    }

    $output->writeln(trim((string) ($startResult['stdout'] ?? 'Started remote workload.')));
  }

  private function extractPublicPort(array $info): string {
    if (empty($info['ports']) || !is_array($info['ports'])) {
      return '';
    }

    foreach ($info['ports'] as $key => $value) {
      if (str_contains((string) $key, '8000') && is_array($value) && isset($value[0]['HostPort'])) {
        return (string) $value[0]['HostPort'];
      }
    }

    return '';
  }

  private function resolveSshKeyPath(): string {
    $candidate = getenv('VAST_SSH_KEY_PATH') ?: '';
    if ($candidate === '') {
      $home = getenv('HOME') ?: '';
      if ($home !== '') {
        $candidate = rtrim($home, '/') . '/.ssh/id_rsa_vastai';
      }
    }

    if ($candidate === '' || !file_exists($candidate)) {
      throw new \RuntimeException('VAST_SSH_KEY_PATH is not set to a readable private key.');
    }

    return $candidate;
  }

}
