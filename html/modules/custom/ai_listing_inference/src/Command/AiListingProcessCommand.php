<?php

declare(strict_types=1);

namespace Drupal\ai_listing_inference\Command;

use Drupal\ai_listing_inference\Service\AiBookListingBatchDataExtractionProcessor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'ai:process-new',
  description: 'Process all AI Book Listings with status=ready_for_inference'
)]
final class AiListingProcessCommand extends Command {

  public function __construct(
    private readonly AiBookListingBatchDataExtractionProcessor $batchProcessor,
  ) {
    parent::__construct();
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    $ids = $this->batchProcessor->getReadyForInferenceListingIds();
    $total = count($ids);

    if ($total === 0) {
      $output->writeln('Nothing to process.');
      return self::SUCCESS;
    }

    $output->writeln(sprintf('Processing %d ready-for-inference listing(s)...', $total));
    $startTime = microtime(TRUE);
    $processed = 0;
    $failed = 0;
    $durations = [];

    foreach (array_values($ids) as $index => $id) {
      $listing = $this->batchProcessor->loadListing($id);
      if (!$listing) {
        $output->writeln(sprintf('Listing %d/%d (ID %d) not found, skipping.', $index + 1, $total, $id));
        continue;
      }

      $listingLabel = $listing->bundle() === 'book_bundle' ? 'Bundle' : 'Book';
      $output->writeln(sprintf('%s %d/%d (ID %d): processing...', $listingLabel, $index + 1, $total, $id));
      $itemStart = microtime(TRUE);

      try {
        $this->batchProcessor->processListing($listing);
        $processed++;
      }
      catch (\Throwable $e) {
        $failed++;
        $output->writeln(sprintf('  Failed: %s', $e->getMessage()));
        if ($this->isConnectivityFailure($e)) {
          $output->writeln('  Aborting run: VLM connectivity failure detected.');
          $output->writeln('  Action: re-provision VLM and rerun ai:process-new.');
          return self::FAILURE;
        }
      }
      finally {
        $duration = microtime(TRUE) - $itemStart;
        $durations[] = $duration;
        $title = $listing->hasField('field_title') ? (string) ($listing->get('field_title')->value ?? '') : '';
        $edition = $listing->hasField('field_edition') ? (string) ($listing->get('field_edition')->value ?? '') : '';
        $output->writeln(sprintf('  Title: %s', $title ?: '<unknown>'));
        $output->writeln(sprintf('  Edition: %s', $edition ?: '<n/a>'));
        $output->writeln(sprintf('  Took %0.2fs', $duration));
      }
    }

    $totalTime = microtime(TRUE) - $startTime;
    $output->writeln('Summary:');
    $output->writeln(sprintf('  Success: %d', $processed));
    $output->writeln(sprintf('  Failed: %d', $failed));
    $output->writeln(sprintf('  Total time: %0.2fs', $totalTime));
    if (!empty($durations)) {
      $average = array_sum($durations) / count($durations);
      $output->writeln(sprintf('  Average per listing: %0.2fs', $average));
    }

    return self::SUCCESS;
  }

  private function isConnectivityFailure(\Throwable $error): bool {
    $message = strtolower($error->getMessage());
    $patterns = [
      'curl error 28',
      'failed to connect',
      'connection refused',
      'could not resolve host',
      'operation timed out',
      'vllm not configured',
      '/v1/chat/completions',
    ];

    foreach ($patterns as $pattern) {
      if (str_contains($message, $pattern)) {
        return true;
      }
    }

    return false;
  }

}
