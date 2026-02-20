<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\compute_orchestrator\Service\BadHostRegistry;
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
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    if ((bool) $input->getOption('clear')) {
      $count = count($this->badHostRegistry->all());
      $this->badHostRegistry->clear();
      $output->writeln('Cleared bad host registry (' . $count . ' entries removed).');
      return self::SUCCESS;
    }

    $hosts = $this->badHostRegistry->all();
    if (empty($hosts)) {
      $output->writeln('No bad hosts currently stored.');
      return self::SUCCESS;
    }

    $output->writeln('Bad hosts (' . count($hosts) . '):');
    foreach ($hosts as $hostId) {
      $output->writeln('- ' . $hostId);
    }

    return self::SUCCESS;
  }

}
