<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Command;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\bb_ai_listing_sync\Service\ListingSyncExportGraphBuilder;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drush\Commands\DrushCommands;

final class BbAiListingSyncCommand extends DrushCommands {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly TimeInterface $time,
    private readonly ListingSyncExportGraphBuilder $graphBuilder,
  ) {
    parent::__construct();
  }

  /**
   * Export one listing with architecture-aware related records.
   *
   * @command bb-ai-listing-sync:export
   * @aliases bblse
   *
   * @param int $listing_id
   *   The bb_ai_listing entity ID.
   * @option output-dir
   *   Output directory for JSON package.
   */
  public function export(int $listing_id, array $options = ['output-dir' => '']): void {
    $listing = $this->loadListing($listing_id);
    if ($listing === NULL) {
      $this->output()->writeln(sprintf('<error>Listing %d not found.</error>', $listing_id));
      return;
    }

    $exportGraph = $this->graphBuilder->buildForListing($listing);
    $outputDir = $this->resolveOutputDir((string) ($options['output-dir'] ?? ''));
    if (!$this->prepareOutputDirectory($outputDir)) {
      $this->output()->writeln('<error>Unable to prepare output directory: ' . $outputDir . '</error>');
      return;
    }

    $outputPath = $this->buildOutputPath($outputDir, $listing);
    $json = json_encode($exportGraph, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === FALSE) {
      $this->output()->writeln('<error>Failed to encode export package.</error>');
      return;
    }

    file_put_contents($outputPath, $json . PHP_EOL);

    $this->output()->writeln('Exported listing package:');
    $this->output()->writeln('- listing_id: ' . $listing->id());
    $this->output()->writeln('- listing_uuid: ' . $listing->uuid());
    $this->output()->writeln('- output: ' . $outputPath);
  }

  private function loadListing(int $listingId): ?BbAiListing {
    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');
    $entity = $storage->load($listingId);
    return $entity instanceof BbAiListing ? $entity : NULL;
  }

  private function resolveOutputDir(string $rawOutputDir): string {
    if ($rawOutputDir !== '') {
      return $rawOutputDir;
    }
    return DRUPAL_ROOT . '/../content/sync/packages';
  }

  private function prepareOutputDirectory(string $outputDir): bool {
    return $this->fileSystem->prepareDirectory(
      $outputDir,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS
    );
  }

  private function buildOutputPath(string $outputDir, BbAiListing $listing): string {
    $timestamp = gmdate('Ymd_His', $this->time->getCurrentTime());
    $fileName = sprintf(
      'bb_ai_listing_%d_%s_%s.json',
      (int) $listing->id(),
      $listing->uuid(),
      $timestamp
    );

    return rtrim($outputDir, '/') . '/' . $fileName;
  }

}
