<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * Drush command for manually validating Vast offer selection and provisioning.
 */
#[AsCommand(
  name: 'compute:test-vast',
  description: 'Test Vast REST search',
  aliases: ['test-vast'],
)]
final class VastTestCommand extends Command {

  private const DEFAULT_QWEN_VL_IMAGE = 'thursdaybw/vllm-qwen-stable:dev';
  private const DEFAULT_TINYLLAMA_IMAGE = 'vllm/vllm-openai:v0.12.0';

  /**
   * Constructs the command.
   */
  public function __construct(
    private readonly VastRestClientInterface $vastClient,
  ) {
    parent::__construct();
  }

  /**
   * {@inheritdoc}
   */
  protected function configure(): void {
    $this->addOption(
      'preserve',
      NULL,
      InputOption::VALUE_NONE,
      'Preserve failed instances for investigation.'
    );

    $this->addOption(
      'workload',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Workload to run (tinyllama | qwen-vl).',
      'tinyllama'
    );

    $this->addOption(
      'image',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Docker image override. Defaults to the image mapped to the selected workload.'
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {

    $output->writeln('Starting compute:test-vast...');

    $strictness = 'strict';
    $preserve = (bool) $input->getOption('preserve');

    $requestedWorkload = trim((string) $input->getOption('workload'));
    $selectedWorkload = $this->resolveWorkloadDefinition($requestedWorkload);
    $workload = $selectedWorkload['workload'];
    $image = $this->resolveImageOverride((string) $input->getOption('image'), $selectedWorkload);
    $model = $selectedWorkload['model'];
    $gpuRamGteGb = $selectedWorkload['gpu_ram_gte'];
    $gpuRamGte = $gpuRamGteGb * 1024;
    $maxModelLen = $selectedWorkload['max_model_len'];

    $policy = $this->resolveStrictnessPolicy($strictness);

    $filters = [
      'reliability' => ['gte' => $policy['reliability_gte']],
      'gpu_ram' => ['gte' => $gpuRamGte],
      'num_gpus' => ['eq' => 1],
      'rentable' => ['eq' => TRUE],
      'verification' => ['eq' => 'verified'],
    ];
    if ($policy['direct_port_count_gte'] !== NULL) {
      $filters['direct_port_count'] = ['gte' => $policy['direct_port_count_gte']];
    }

    try {

      $result = $this->vastClient->provisionInstanceFromOffers(
        $filters,
        ['RU', 'CN', 'IR', 'KP', 'SY'],
        20,
        1.0,
        $policy['min_price'],
        [
          'workload' => 'vllm',
          'prefer_success_hosts' => $policy['prefer_success_hosts'],
          'preserve_on_failure' => $preserve,
          'image' => $image,
          'options' => [
            'disk' => 40,
            'runtype' => 'ssh',
            'target_state' => 'running',

            // DEV MODE: expose vLLM HTTP port publicly.
            // This is intentionally insecure for early-stage development.
            // Do NOT use this configuration in production.
            'env' => [
              '-p 8000:8000' => '1',
            ],

            'onstart' => "bash -lc 'if command -v vllm >/dev/null 2>&1; then vllm serve {$model} --dtype float16 --max-model-len {$maxModelLen} --tensor-parallel-size 1 --trust-remote-code --host 0.0.0.0 --port 8000 > /tmp/vllm.log 2>&1; else python3 -m vllm.entrypoints.openai.api_server --model {$model} --dtype float16 --max-model-len {$maxModelLen} --tensor-parallel-size 1 --trust-remote-code --host 0.0.0.0 --port 8000 > /tmp/vllm.log 2>&1; fi'",
            'args_str' => "--model {$model} --dtype float16 --max-model-len {$maxModelLen} --tensor-parallel-size 1 --trust-remote-code",
          ],
        ],
        5,
        600
      );

      $contractId = (string) ($result['contract_id'] ?? '');
      $info = (array) ($result['instance_info'] ?? []);

      // DEV MODE: capture public HTTP endpoint for vLLM.
      $publicHost = (string) ($info['public_ipaddr'] ?? '');
      $publicPort = '';

      if (!empty($info['ports']) && is_array($info['ports'])) {
        foreach ($info['ports'] as $key => $value) {
          if (str_contains((string) $key, '8000') && is_array($value) && isset($value[0]['HostPort'])) {
            $publicPort = (string) $value[0]['HostPort'];
            break;
          }
        }
      }

      if ($contractId === '' || empty($info)) {
        $output->writeln('Provisioning returned no contract or instance info.');
        return self::FAILURE;
      }

      $output->writeln('Provisioned contract: ' . $contractId);
      $output->writeln('Workload: ' . $workload);
      $output->writeln('Image: ' . $image);
      $output->writeln('Model: ' . $model);
      $output->writeln('State: ' . ($info['cur_state'] ?? 'unknown'));
      $output->writeln('SSH Host: ' . ($info['ssh_host'] ?? ''));
      $output->writeln('SSH Port: ' . (string) ($info['ssh_port'] ?? ''));

      if ($publicHost !== '' && $publicPort !== '') {
        $output->writeln('Public VLM Host: ' . $publicHost);
        $output->writeln('Public VLM Port: ' . $publicPort);
      }
      else {
        $output->writeln('WARNING: Public VLM port mapping not detected.');
      }

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
      return self::FAILURE;
    }
  }

  /**
   * Maps strictness levels to offer selection preferences.
   */
  private function resolveStrictnessPolicy(string $strictness): array {
    switch ($strictness) {
      case 'aggressive':
        return [
          'reliability_gte' => 0.95,
          'direct_port_count_gte' => 4,
          'prefer_success_hosts' => FALSE,
          'min_price' => 0.20,
        ];

      case 'balanced':
        return [
          'reliability_gte' => 0.98,
          'direct_port_count_gte' => 8,
          'prefer_success_hosts' => TRUE,
          'min_price' => 0.20,
        ];

      case 'strict':
      default:
        return [
          'reliability_gte' => 0.995,
          'direct_port_count_gte' => 16,
          'prefer_success_hosts' => TRUE,
          'min_price' => 0.20,
        ];
    }
  }

  /**
   * Resolves the workload definition used for provision + model selection.
   */
  private function resolveWorkloadDefinition(string $requestedWorkload): array {
    $workloadMap = [
      'tinyllama' => [
        'workload' => 'tinyllama',
        'image' => self::DEFAULT_TINYLLAMA_IMAGE,
        'model' => 'TinyLlama/TinyLlama-1.1B-Chat-v1.0',
        'gpu_ram_gte' => 8,
        'max_model_len' => 2048,
      ],
      'qwen-vl' => [
        'workload' => 'qwen-vl',
        'image' => self::DEFAULT_QWEN_VL_IMAGE,
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 20,
        'max_model_len' => 16384,
      ],
    ];

    if (isset($workloadMap[$requestedWorkload])) {
      return $workloadMap[$requestedWorkload];
    }

    return $workloadMap['tinyllama'];
  }

  /**
   * Resolves the Docker image to use for the selected workload.
   */
  private function resolveImageOverride(string $requestedImage, array $selectedWorkload): string {
    $normalizedImage = trim($requestedImage);
    if ($normalizedImage !== '') {
      return $normalizedImage;
    }

    return (string) $selectedWorkload['image'];
  }

}
