<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Command;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\bb_ai_listing_sync\Service\ListingSyncExportGraphBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Process\Process;

final class BbAiListingSyncCommand extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ListingSyncExportGraphBuilder $graphBuilder,
  ) {
    parent::__construct();
  }

  /**
   * Export one listing by computing UUID graph then using native content_sync.
   *
   * @command bb-ai-listing-sync:export
   * @aliases bblse
   *
   * @param int $listing_id
   *   The bb_ai_listing entity ID.
   * @option dry-run
   *   Print computed UUIDs and native command, but do not execute export.
   */
  public function export(int $listing_id, array $options = ['dry-run' => FALSE]): void {
    $listing = $this->loadListing($listing_id);
    if ($listing === NULL) {
      $this->output()->writeln(sprintf('<error>Listing %d not found.</error>', $listing_id));
      return;
    }

    $graph = $this->graphBuilder->buildForListing($listing);
    $uuids = $graph['uuids'];
    if ($uuids === []) {
      $this->output()->writeln('<error>No UUIDs were discovered for export.</error>');
      return;
    }

    $uuidCsv = implode(',', $uuids);

    $this->output()->writeln('Computed export graph:');
    $this->output()->writeln('- root_listing_id: ' . $graph['root_listing_id']);
    $this->output()->writeln('- root_listing_uuid: ' . $graph['root_listing_uuid']);
    $this->writeCounts($graph['counts']);
    $this->output()->writeln('- total_entities: ' . $graph['total_entities']);
    $this->output()->writeln('- total_uuids: ' . count($uuids));

    $nativeCommand = sprintf(
      'vendor/bin/drush content-sync:export sync --uuids=%s --skiplist -y',
      $uuidCsv
    );
    $this->output()->writeln('- native_command: ' . $nativeCommand);

    if (!empty($options['dry-run'])) {
      $this->output()->writeln('Dry run enabled. Skipping native export execution.');
      return;
    }

    $result = $this->runNativeContentSyncExport($uuidCsv);
    if ($result !== 0) {
      $this->output()->writeln('<error>Native content-sync export failed.</error>');
      return;
    }

    $this->output()->writeln('Export complete. Content YAML written to content/sync/entities.');
  }

  private function loadListing(int $listingId): ?BbAiListing {
    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');
    $entity = $storage->load($listingId);
    return $entity instanceof BbAiListing ? $entity : NULL;
  }

  /**
   * @param array<string, int> $counts
   */
  private function writeCounts(array $counts): void {
    foreach ($counts as $entityType => $count) {
      $this->output()->writeln(sprintf('- count[%s]: %d', $entityType, $count));
    }
  }

  private function runNativeContentSyncExport(string $uuidCsv): int {
    $drushBinary = DRUPAL_ROOT . '/../vendor/bin/drush';
    if (!is_file($drushBinary)) {
      $drushBinary = 'drush';
    }

    $process = new Process([
      $drushBinary,
      'content-sync:export',
      'sync',
      '--uuids=' . $uuidCsv,
      '--skiplist',
      '-y',
    ], DRUPAL_ROOT);

    $process->setTimeout(NULL);
    $process->run(function (string $type, string $buffer): void {
      $this->output()->write($buffer);
    });

    return $process->getExitCode() ?? 1;
  }

}
