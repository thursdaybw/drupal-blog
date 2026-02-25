<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\BadHostRegistry;
use Drupal\Core\State\StateInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'compute:bad-hosts',
  description: 'List persisted bad hosts used by compute orchestrator.',
)]
final class BadHostsCommand extends Command {

  public function __construct(
    private readonly BadHostRegistry $badHostRegistry,
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption(
      'clear',
      null,
      InputOption::VALUE_NONE,
      'Clear the persisted bad-host registry.'
    );
    $this->addOption(
      'export-path',
      null,
      InputOption::VALUE_REQUIRED,
      'Write the global/workload bad-host data plus CDI history as JSON.'
    );
    $this->addOption(
      'import-path',
      null,
      InputOption::VALUE_REQUIRED,
      'Read previously exported bad-host data (global/workload/CDI) and restore state.'
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if ((bool) $input->getOption('clear')) {
      $count = count($this->badHostRegistry->all());
      $this->badHostRegistry->clear();
      $output->writeln('Cleared bad host registry (' . $count . ' entries removed).');
      return self::SUCCESS;
    }

    $importPath = (string) $input->getOption('import-path');
    if ($importPath !== '') {
      $this->importBadHostData($importPath, $output);
    }

    $globalHosts = $this->badHostRegistry->all();
    $workloadHosts = $this->state->get('compute_orchestrator.workload_bad_hosts', []);
    if (!is_array($workloadHosts)) {
      $workloadHosts = [];
    }
    $cdiFailures = $this->state->get('compute_orchestrator.host_cdi_failures', []);
    if (!is_array($cdiFailures)) {
      $cdiFailures = [];
    }
    $output->writeln('Global bad hosts (' . count($globalHosts) . '):');
    if (empty($globalHosts)) {
      $output->writeln('- (none)');
    }
    else {
      foreach ($globalHosts as $hostId) {
        $output->writeln('- ' . $hostId);
        $this->renderCdiFailures($output, $hostId, $cdiFailures[$hostId] ?? []);
      }
    }

    if (is_array($workloadHosts) && !empty($workloadHosts)) {
      foreach ($workloadHosts as $workload => $list) {
        if (!is_array($list)) {
          continue;
        }
        $unique = array_values(array_unique(array_map('strval', $list)));
        $output->writeln('Workload ' . $workload . ' bad hosts (' . count($unique) . '):');
        foreach ($unique as $hostId) {
          $output->writeln('- ' . $hostId);
        }
      }
    }
    else {
      $output->writeln('Per-workload bad hosts: (none)');
    }

    $exportPath = (string) $input->getOption('export-path');
    if ($exportPath !== '') {
      $data = $this->gatherBadHostData($globalHosts, $workloadHosts ?? [], $cdiFailures);
      $this->exportBadHosts($exportPath, $data, $output);
    }

    return self::SUCCESS;
  }

  private function exportBadHosts(string $path, array $data, OutputInterface $output): void {
    $json = json_encode($data, JSON_PRETTY_PRINT);
    if ($json === false) {
      $output->writeln('<error>Failed to encode bad host data as JSON.</error>');
      return;
    }

    try {
      file_put_contents($path, $json);
      $output->writeln('Exported bad host data to ' . $path);
    }
    catch (\Throwable $e) {
      $output->writeln('<error>Unable to write to ' . $path . ': ' . $e->getMessage() . '</error>');
    }
  }

  private function gatherBadHostData(array $globalHosts, array $workloadHosts, array $cdiFailures): array {
    return [
      'generated_at' => date('c'),
      'global' => array_values(array_map('strval', $globalHosts)),
      'workloads' => array_map(static fn ($list) => array_values(array_map('strval', (array) $list)), $workloadHosts),
      'cdi_failures' => $cdiFailures,
    ];
  }

  private function importBadHostData(string $path, OutputInterface $output): void {
    if (!file_exists($path)) {
      $output->writeln('<error>Import path does not exist: ' . $path . '</error>');
      return;
    }

    $content = @file_get_contents($path);
    if ($content === false) {
      $output->writeln('<error>Unable to read import path: ' . $path . '</error>');
      return;
    }

    $payload = json_decode($content, true);
    if (!is_array($payload)) {
      $output->writeln('<error>Invalid JSON in import file: ' . $path . '</error>');
      return;
    }

    $global = array_values(array_filter(array_map('strval', (array) ($payload['global'] ?? []))));
    $workloads = [];
    if (isset($payload['workloads']) && is_array($payload['workloads'])) {
      foreach ($payload['workloads'] as $workload => $list) {
        if (!is_array($list)) {
          continue;
        }
        $workloads[(string) $workload] = array_values(array_unique(array_filter(array_map('strval', $list))));
      }
    }
    $cdiFailures = is_array($payload['cdi_failures']) ? $payload['cdi_failures'] : [];

    $this->badHostRegistry->clear();
    foreach ($global as $hostId) {
      $this->badHostRegistry->add($hostId);
    }

    $this->state->set('compute_orchestrator.workload_bad_hosts', $workloads);
    $this->state->set('compute_orchestrator.host_cdi_failures', $cdiFailures);

    $output->writeln('Imported bad host data from ' . $path . ' (global ' . count($global) . ', workloads ' . count($workloads) . ').');
  }

  private function renderCdiFailures(OutputInterface $output, string $hostId, array $failures): void {
    if (empty($failures)) {
      return;
    }

    foreach ($failures as $entry) {
      $device = $entry['device'] ?? '(unknown)';
      $when = isset($entry['when']) ? date('Y-m-d H:i:s', (int) $entry['when']) : '(unknown)';
      $output->writeln('    * CDI failure: ' . $device . ' @ ' . $when);
    }
  }

}
