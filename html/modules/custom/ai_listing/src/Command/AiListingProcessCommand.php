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

    $ids = $this->batchProcessor->getNewListingIds();
    $total = count($ids);

    if ($total === 0) {
      $output->writeln('Nothing to process.');
      return self::SUCCESS;
    }

    $output->writeln(sprintf('Processing %d new listing(s)...', $total));
    $startTime = microtime(true);
    $processed = 0;
    $failed = 0;
    $durations = [];

    foreach (array_values($ids) as $index => $id) {
      $listing = $this->batchProcessor->loadListing($id);
      if (!$listing) {
        $output->writeln(sprintf('Book %d/%d (ID %d) not found, skipping.', $index + 1, $total, $id));
        continue;
      }

      $output->writeln(sprintf('Book %d/%d (ID %d): processing...', $index + 1, $total, $id));
      $itemStart = microtime(true);

      try {
        $this->batchProcessor->processListing($listing);
        $processed++;
      }
      catch (\Throwable $e) {
        $failed++;
        $output->writeln(sprintf('  Failed: %s', $e->getMessage()));
      }
      finally {
        $duration = microtime(true) - $itemStart;
        $durations[] = $duration;
        $title = (string) $listing->get('title')->value;
        $edition = (string) $listing->get('edition')->value;
        $output->writeln(sprintf('  Title: %s', $title ?: '<unknown>'));
        $output->writeln(sprintf('  Edition: %s', $edition ?: '<n/a>'));
        $output->writeln(sprintf('  Took %0.2fs', $duration));
      }
    }

    $totalTime = microtime(true) - $startTime;
    $output->writeln('Summary:');
    $output->writeln(sprintf('  Success: %d', $processed));
    $output->writeln(sprintf('  Failed: %d', $failed));
    $output->writeln(sprintf('  Total time: %0.2fs', $totalTime));
    if (!empty($durations)) {
      $average = array_sum($durations) / count($durations);
      $output->writeln(sprintf('  Average per book: %0.2fs', $average));
    }

    return self::SUCCESS;
  }
}
