<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Command;

use Drupal\ai_listing\Service\AiBookListingBatchDataExtractionProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;


#[AsCommand(
  name: 'ai:process-new',
  description: 'Process all AI Book Listings with status=new'
)]
final class AiListingProcessCommand extends Command {

  public function __construct(
    private readonly AiBookListingBatchDataExtractionProcessor $batchProcessor,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {

    $output->writeln('Processing new listings...');

    $count = $this->batchProcessor->processAllNew();

    $output->writeln("Processed {$count} listing(s).");

    return self::SUCCESS;
  }
}
