<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

#[AsCommand(
  name: 'compute:test-vast',
  description: 'Test Vast REST search',
  aliases: ['test-vast'],
)]
final class VastTestCommand extends Command {

  private VastRestClientInterface $vastClient;

  public function __construct(VastRestClientInterface $vastClient) {
    parent::__construct();
    $this->vastClient = $vastClient;
  }

  protected function configure(): void {
    $this->addOption(
      'preserve',
      null,
      InputOption::VALUE_NONE,
      'Preserve failed instances for investigation.'
    );

    $this->addOption(
      'workload',
      null,
      InputOption::VALUE_REQUIRED,
      'Workload to run (tinyllama | qwen-vl).',
      'tinyllama'
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {

    $output->writeln('Starting compute:test-vast...');

    $strictness = (string) \Drupal::state()->get('compute_orchestrator.strictness', 'strict');
    $preserve = (bool) $input->getOption('preserve');

    $workload = (string) $input->getOption('workload');

    $image = 'vllm/vllm-openai:v0.12.0';
    $model = '';
    $gpuRamGte = 8;

    $workloadMap = [
      'tinyllama' => [
        'model' => 'TinyLlama/TinyLlama-1.1B-Chat-v1.0',
        'gpu_ram_gte' => 8,
      ],
      'qwen-vl' => [
        'model' => 'Qwen/Qwen2-VL-7B-Instruct',
        'gpu_ram_gte' => 16,
      ],
    ];

    if ($workloadMap[$workload]) {
      $selected = $workloadMap[$workload];
    }
    else {
        $workloadMap['tinyllama'];
    }

    $model = $selected['model'];
    $gpuRamGte = $selected['gpu_ram_gte'];

    $policy = $this->resolveStrictnessPolicy($strictness);

    $filters = [
      'reliability' => ['gte' => $policy['reliability_gte']],
      'gpu_ram' => ['gte' => $gpuRamGte],
      'num_gpus' => ['eq' => 1],
      'rentable' => ['eq' => true],
      'verification' => ['eq' => 'verified'],
    ];
    if ($policy['direct_port_count_gte'] !== null) {
      $filters['direct_port_count'] = ['gte' => $policy['direct_port_count_gte']];
    }

    try {

      $result = $this->vastClient->provisionInstanceFromOffers(
        $filters,
        [],
        ['RU', 'CN', 'IR', 'KP', 'SY'],
        20,
        1.0,
        [
          'workload' => 'vllm',
          'prefer_success_hosts' => $policy['prefer_success_hosts'],
          'preserve_on_failure' => $preserve,
          'image' => $image,
          'options' => [
            'disk' => 40,
            'runtype' => 'ssh_direct',
            'target_state' => 'running',
            'onstart_cmd' => "bash -lc 'if command -v vllm >/dev/null 2>&1; then vllm serve {$model} --dtype float16 --max-model-len 2048 --tensor-parallel-size 1 --trust-remote-code --host 0.0.0.0 --port 8000 > /tmp/vllm.log 2>&1; else python3 -m vllm.entrypoints.openai.api_server --model {$model} --dtype float16 --max-model-len 2048 --tensor-parallel-size 1 --trust-remote-code --host 0.0.0.0 --port 8000 > /tmp/vllm.log 2>&1; fi'",
            'onstart' => "bash -lc 'if command -v vllm >/dev/null 2>&1; then vllm serve {$model} --dtype float16 --max-model-len 2048 --tensor-parallel-size 1 --trust-remote-code --host 0.0.0.0 --port 8000 > /tmp/vllm.log 2>&1; else python3 -m vllm.entrypoints.openai.api_server --model {$model} --dtype float16 --max-model-len 2048 --tensor-parallel-size 1 --trust-remote-code --host 0.0.0.0 --port 8000 > /tmp/vllm.log 2>&1; fi'",
            'args_str' => "--model {$model} --dtype float16 --max-model-len 2048 --tensor-parallel-size 1 --trust-remote-code",
          ],
        ],
        3,
        600
      );

      $contractId = (string) ($result['contract_id'] ?? '');
      $info = (array) ($result['instance_info'] ?? []);

      if ($contractId === '' || empty($info)) {
        $output->writeln('Provisioning returned no contract or instance info.');
        return self::FAILURE;
      }

      $output->writeln('Provisioned contract: ' . $contractId);
      $output->writeln('State: ' . ($info['cur_state'] ?? 'unknown'));
      $output->writeln('SSH Host: ' . ($info['ssh_host'] ?? ''));
      $output->writeln('SSH Port: ' . (string) ($info['ssh_port'] ?? ''));

      $this->vastClient->destroyInstance($contractId);
      $output->writeln('Destroyed.');

      return self::SUCCESS;

    }
    catch (\Throwable $e) {
      $output->writeln($e->getMessage());
      return self::FAILURE;
    }
  }

  private function resolveStrictnessPolicy(string $strictness): array {
    switch ($strictness) {
    case 'aggressive':
      return [
        'reliability_gte' => 0.95,
        'direct_port_count_gte' => 4,
        'prefer_success_hosts' => false,
      ];

    case 'balanced':
      return [
        'reliability_gte' => 0.98,
        'direct_port_count_gte' => 8,
        'prefer_success_hosts' => true,
      ];

    case 'strict':
    default:
    return [
      'reliability_gte' => 0.995,
      'direct_port_count_gte' => 16,
      'prefer_success_hosts' => true,
    ];
    }
  }

}
