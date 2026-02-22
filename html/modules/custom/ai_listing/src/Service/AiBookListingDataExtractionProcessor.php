<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\compute_orchestrator\Service\VlmClient;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\ai_listing\Service\BookExtractionService;

final class AiBookListingDataExtractionProcessor {

  public function __construct(
    private readonly BookExtractionService $bookExtraction,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function process(AiBookListing $listing): void {

    $fileStorage = $this->entityTypeManager->getStorage('file');

    $imagePaths = [];

    foreach ($listing->get('images') as $item) {
      $file = $fileStorage->load($item->target_id);
      if ($file) {
        $uri = $file->getFileUri();
        $realPath = $this->fileSystem->realpath($uri);

        if (!$realPath || !file_exists($realPath)) {
          throw new \RuntimeException("File not found: {$uri}");
        }

        $imagePaths[] = $realPath;
      }
    }

    if (empty($imagePaths)) {
      throw new \RuntimeException('No images attached.');
    }

    $result = $this->bookExtraction->extract($imagePaths);

    // Store structured JSON, not raw markdown wrapped output.
    $listing->set('metadata_json', json_encode($result['metadata'], JSON_PRETTY_PRINT));
    $listing->set('condition_json', json_encode($result['condition'], JSON_PRETTY_PRINT));

    $listing->set('status', 'processed');

    $listing->save();
  }

}
