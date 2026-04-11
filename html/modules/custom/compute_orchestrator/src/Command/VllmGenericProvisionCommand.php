<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\GenericVllmRuntimeManagerInterface;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drupal\compute_orchestrator\Service\VllmWorkloadCatalogInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'compute:provision-vllm-generic',
  description: 'Provision a generic vLLM Vast node, start a selected workload over SSH, and wait for readiness.',
)]
/**
 * Provisions a fresh generic vLLM instance for operator-driven validation.
 */
final class VllmGenericProvisionCommand extends Command {

  public function __construct(
    private readonly VllmWorkloadCatalogInterface $workloadCatalog,
    private readonly GenericVllmRuntimeManagerInterface $runtimeManager,
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
      'Preserve the instance after success or failure.',
    );
    $this->addOption(
      'workload',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Workload to start on the generic node (qwen-vl | whisper).',
      'qwen-vl',
    );
    $this->addOption(
      'image',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Docker image to provision.',
      $this->workloadCatalog->getDefaultGenericImage(),
    );
    $this->addOption(
      'model',
      NULL,
      InputOption::VALUE_REQUIRED,
      'Optional model override. Defaults to the workload model.',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $output->writeln('Starting compute:provision-vllm-generic...');

    $preserve = (bool) $input->getOption('preserve');
    $modelOverride = trim((string) ($input->getOption('model') ?? ''));
    $workloadDefinition = $this->workloadCatalog->getDefinition(
      trim((string) $input->getOption('workload')),
      $modelOverride !== '' ? $modelOverride : NULL,
    );
    $image = trim((string) $input->getOption('image'));
    $contractId = '';

    try {
      $fresh = $this->runtimeManager->provisionFresh($workloadDefinition, $image);
      $contractId = (string) ($fresh['contract_id'] ?? '');
      $instanceInfo = (array) ($fresh['instance_info'] ?? []);
      if ($contractId === '' || $instanceInfo === []) {
        throw new \RuntimeException('Provisioning returned no contract or bootstrap instance info.');
      }

      $output->writeln('Provisioned contract: ' . $contractId);
      $output->writeln('Image: ' . $image);
      $output->writeln('Bootstrap SSH: ' . ($instanceInfo['ssh_host'] ?? '') . ':' . (string) ($instanceInfo['ssh_port'] ?? ''));

      $this->runtimeManager->startWorkload($instanceInfo, $workloadDefinition);
      $readyInfo = $this->runtimeManager->waitForWorkloadReady($contractId);

      $publicHost = trim((string) ($readyInfo['public_ipaddr'] ?? ''));
      $publicPort = $this->extractPublicPort($readyInfo);
      if ($publicHost !== '' && $publicPort !== '') {
        $output->writeln('Public VLM Host: ' . $publicHost);
        $output->writeln('Public VLM Port: ' . $publicPort);
      }
      else {
        $output->writeln('WARNING: Public VLM port mapping not detected.');
      }

      $output->writeln('Workload mode: ' . (string) $workloadDefinition['mode']);
      $output->writeln('Model: ' . (string) $workloadDefinition['model']);
      $output->writeln('State: ' . (string) ($readyInfo['cur_state'] ?? 'unknown'));

      if (!$preserve) {
        $this->vastClient->destroyInstance($contractId);
        $output->writeln('Destroyed.');
      }
      else {
        $output->writeln('Instance preserved for testing.');
      }

      return self::SUCCESS;
    }
    catch (\Throwable $exception) {
      $output->writeln($exception->getMessage());
      if ($preserve && $contractId !== '') {
        $output->writeln('Instance preserved for debugging: ' . $contractId);
      }
      return self::FAILURE;
    }
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

}
