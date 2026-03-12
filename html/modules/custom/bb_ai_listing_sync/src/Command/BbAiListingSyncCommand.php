<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Command;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\bb_ai_listing_sync\Contract\ListingSyncGraphBuilderInterface;
use Drupal\bb_ai_listing_sync\Service\ListingSyncGraphFingerprintService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\Process\Process;

final class BbAiListingSyncCommand extends DrushCommands {

  public function __construct(
    private readonly ListingSyncGraphBuilderInterface $graphBuilder,
    private readonly ListingSyncGraphFingerprintService $graphFingerprintService,
    private readonly EntityTypeManagerInterface $entityTypeManager,
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
    $graph = $this->graphBuilder->loadAndBuildForListingId($listing_id);
    if ($graph === NULL) {
      $this->output()->writeln(sprintf('<error>Listing %d not found.</error>', $listing_id));
      return;
    }

    $uuids = $graph->uuids();
    if ($uuids === []) {
      $this->output()->writeln('<error>No UUIDs were discovered for export.</error>');
      return;
    }

    $uuidCsv = $graph->uuidsCsv();

    $this->output()->writeln('Computed export graph:');
    $this->output()->writeln('- root_listing_id: ' . $graph->rootListingId());
    $this->output()->writeln('- root_listing_uuid: ' . $graph->rootListingUuid());
    $this->writeCounts($graph->counts());
    $this->output()->writeln('- total_entities: ' . $graph->totalEntities());
    $this->output()->writeln('- total_uuids: ' . $graph->totalUuids());
    $this->output()->writeln('- native_command: ' . $graph->nativeExportCommandPreview());

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

  /**
   * Output deterministic listing graph fingerprints.
   *
   * @command bb-ai-listing-sync:fingerprint-map
   * @aliases bblsfm
   *
   * @option listing-ids
   *   Comma-separated bb_ai_listing IDs to include.
   * @option status
   *   Optional status filter when listing-ids is not provided.
   * @option limit
   *   Optional max number of listings to output.
   * @option format
   *   Output format: tsv or json. Defaults to tsv.
   */
  public function fingerprintMap(array $options = [
    'listing-ids' => '',
    'status' => '',
    'limit' => '0',
    'format' => 'tsv',
  ]): void {
    $format = strtolower((string) ($options['format'] ?? 'tsv'));
    if (!in_array($format, ['tsv', 'json'], TRUE)) {
      $this->output()->writeln('<error>Invalid --format. Use tsv or json.</error>');
      return;
    }

    $listingIds = $this->resolveListingIds($options);
    if ($listingIds === []) {
      return;
    }

    $listingStorage = $this->entityTypeManager->getStorage('bb_ai_listing');
    $loadedListings = $listingStorage->loadMultiple($listingIds);

    $rows = [];
    foreach ($listingIds as $listingId) {
      $listing = $loadedListings[$listingId] ?? NULL;
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $graph = $this->graphBuilder->buildForListing($listing);

      $rows[] = [
        'id' => $listingId,
        'uuid' => $graph->rootListingUuid(),
        'status' => (string) ($listing->get('status')->value ?? ''),
        'changed' => (int) ($listing->get('changed')->value ?? 0),
        'title' => $this->sanitizeTsvCell((string) ($listing->label() ?? '')),
        'fingerprint' => $this->graphFingerprintService->fingerprintGraph($graph),
      ];
    }

    usort($rows, static function (array $left, array $right): int {
      return strcmp((string) $left['uuid'], (string) $right['uuid']);
    });

    if ($format === 'json') {
      $this->output()->writeln((string) json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
      return;
    }

    foreach ($rows as $row) {
      $this->output()->writeln(sprintf(
        '%d' . "\t" . '%s' . "\t" . '%s' . "\t" . '%d' . "\t" . '%s' . "\t" . '%s',
        (int) $row['id'],
        (string) $row['uuid'],
        (string) $row['status'],
        (int) $row['changed'],
        (string) $row['title'],
        (string) $row['fingerprint']
      ));
    }
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

  /**
   * @param array<string, mixed> $options
   *
   * @return array<int, int>
   */
  private function resolveListingIds(array $options): array {
    $listingIdsOption = trim((string) ($options['listing-ids'] ?? ''));
    if ($listingIdsOption !== '') {
      $parts = array_filter(array_map('trim', explode(',', $listingIdsOption)));
      $ids = [];
      foreach ($parts as $part) {
        if (ctype_digit($part)) {
          $ids[] = (int) $part;
        }
      }
      return array_values(array_unique($ids));
    }

    $status = trim((string) ($options['status'] ?? ''));
    $limitValue = (string) ($options['limit'] ?? '0');
    $limit = ctype_digit($limitValue) ? (int) $limitValue : 0;

    $query = $this->entityTypeManager->getStorage('bb_ai_listing')->getQuery();
    $query->accessCheck(FALSE);
    if ($status !== '') {
      $query->condition('status', $status);
    }
    $query->sort('id', 'ASC');
    if ($limit > 0) {
      $query->range(0, $limit);
    }

    $ids = $query->execute();
    return array_map('intval', array_values($ids));
  }

  private function sanitizeTsvCell(string $value): string {
    return str_replace(["\t", "\n", "\r"], ' ', $value);
  }

}
