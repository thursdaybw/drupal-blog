<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\Core\State\StateInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'compute:known-good-hosts',
  description: 'List known-good hosts recorded by compute orchestrator.',
)]
final class KnownGoodHostsCommand extends Command {

  public function __construct(
    private readonly StateInterface $state,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this->addOption(
      'json',
      null,
      InputOption::VALUE_NONE,
      'Output known-good host data as JSON.'
    );
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $knownGoodHosts = $this->buildKnownGoodHosts();

    if ((bool) $input->getOption('json')) {
      $payload = [
        'generated_at' => date('c'),
        'count' => count($knownGoodHosts),
        'hosts' => $knownGoodHosts,
      ];
      $json = json_encode($payload, JSON_PRETTY_PRINT);

      if ($json === false) {
        $output->writeln('<error>Failed to encode known-good hosts as JSON.</error>');
        return self::FAILURE;
      }

      $output->writeln($json);
      return self::SUCCESS;
    }

    $output->writeln('Known-good hosts (' . count($knownGoodHosts) . '):');
    if (empty($knownGoodHosts)) {
      $output->writeln('- (none)');
      return self::SUCCESS;
    }

    foreach ($knownGoodHosts as $entry) {
      $lastSuccessText = $entry['last_success'] > 0
        ? date('Y-m-d H:i:s', (int) $entry['last_success'])
        : '(never)';

      $output->writeln(sprintf(
        '- %s success=%d infra_fail=%d last_success=%s',
        (string) $entry['host_id'],
        (int) $entry['success'],
        (int) $entry['infra_fail'],
        $lastSuccessText
      ));
    }

    return self::SUCCESS;
  }

  private function buildKnownGoodHosts(): array {
    $stats = $this->state->get('compute_orchestrator.host_stats', []);
    if (!is_array($stats)) {
      return [];
    }

    $knownGoodHosts = [];

    foreach ($stats as $hostId => $hostStat) {
      if (!is_array($hostStat)) {
        continue;
      }

      $normalizedHostId = $this->normalizeHostId($hostId);
      if ($normalizedHostId === '') {
        continue;
      }

      $successCount = (int) ($hostStat['success'] ?? 0);
      if ($successCount <= 0) {
        continue;
      }

      $knownGoodHosts[] = [
        'host_id' => $normalizedHostId,
        'success' => $successCount,
        'infra_fail' => (int) ($hostStat['infra_fail'] ?? 0),
        'last_success' => (int) ($hostStat['last_success'] ?? 0),
      ];
    }

    usort($knownGoodHosts, static function (array $a, array $b): int {
      if ((int) $a['success'] !== (int) $b['success']) {
        return ((int) $b['success']) <=> ((int) $a['success']);
      }

      if ((int) $a['last_success'] !== (int) $b['last_success']) {
        return ((int) $b['last_success']) <=> ((int) $a['last_success']);
      }

      return ((string) $a['host_id']) <=> ((string) $b['host_id']);
    });

    return $knownGoodHosts;
  }

  private function normalizeHostId(mixed $rawHostId): string {
    if (!is_scalar($rawHostId)) {
      return '';
    }

    $hostId = trim((string) $rawHostId);
    if ($hostId === '') {
      return '';
    }

    if (preg_match('/^[0-9]+(?:\\.0+)?$/', $hostId) === 1) {
      $hostId = (string) ((int) (float) $hostId);
    }

    return $hostId;
  }

}
