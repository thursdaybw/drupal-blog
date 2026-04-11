<?php

declare(strict_types=1);

namespace Drupal\ai_listing_inference\Command;

use Drupal\ai_listing_inference\Service\ReadyInferenceRunService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
  name: 'ai:process-new',
  description: 'Process all AI Book Listings with status=ready_for_inference'
)]
/**
 * Drush command entrypoint for ready-for-inference processing.
 */
final class AiListingProcessCommand extends Command {

  public function __construct(
    private readonly ReadyInferenceRunService $readyInferenceRunner,
  ) {
    parent::__construct();
  }

  /**
   * Executes one pooled inference run over all ready listings.
   */
  protected function execute(InputInterface $input, OutputInterface $output): int {
    $startTime = microtime(TRUE);
    $result = $this->readyInferenceRunner->runReadyListings(function (string $message) use ($output): void {
      $output->writeln($message);
    });
    if ((int) ($result['total'] ?? 0) === 0) {
      $output->writeln('Nothing to process.');
      return self::SUCCESS;
    }

    foreach (($result['items'] ?? []) as $item) {
      if (!is_array($item)) {
        continue;
      }
      $listingId = (int) ($item['listing_id'] ?? 0);
      $title = (string) ($item['title'] ?? '');
      $edition = (string) ($item['edition'] ?? '');
      $duration = (float) ($item['duration'] ?? 0.0);
      $success = (bool) ($item['success'] ?? FALSE);
      if (!$success) {
        $output->writeln(sprintf('  Failed (ID %d): %s', $listingId, (string) ($item['error'] ?? 'Unknown error')));
      }
      $output->writeln(sprintf('  Title: %s', $title ?: '<unknown>'));
      $output->writeln(sprintf('  Edition: %s', $edition ?: '<n/a>'));
      $output->writeln(sprintf('  Took %0.2fs', $duration));
    }

    $processed = (int) ($result['processed'] ?? 0);
    $failed = (int) ($result['failed'] ?? 0);
    $durations = array_filter(array_map(
      static fn($item): float => is_array($item) ? (float) ($item['duration'] ?? 0.0) : 0.0,
      (array) ($result['items'] ?? [])
    ), static fn(float $seconds): bool => $seconds > 0.0);
    $totalTime = microtime(TRUE) - $startTime;
    $output->writeln('Summary:');
    $output->writeln(sprintf('  Success: %d', $processed));
    $output->writeln(sprintf('  Failed: %d', $failed));
    $output->writeln(sprintf('  Total time: %0.2fs', $totalTime));
    if (!empty($durations)) {
      $average = array_sum($durations) / count($durations);
      $output->writeln(sprintf('  Average per listing: %0.2fs', $average));
    }

    return ((bool) ($result['aborted_early'] ?? FALSE) || (bool) ($result['acquire_failed'] ?? FALSE))
      ? self::FAILURE
      : self::SUCCESS;
  }

}
