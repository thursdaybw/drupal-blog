<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\ai_listing_inference\Service\BookExtractionService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;

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

    $metadata = $result['metadata'] ?? [];
    $condition = $result['condition'] ?? ['issues' => []];

    $listing->set('metadata_json', json_encode($metadata, JSON_PRETTY_PRINT));
    $listing->set('condition_json', json_encode($condition, JSON_PRETTY_PRINT));

    $listing->set('title', (string) ($metadata['title'] ?? ''));
    $listing->set('subtitle', (string) ($metadata['subtitle'] ?? ''));
    $listing->set('full_title', (string) ($metadata['full_title'] ?? ''));
    $listing->set('author', (string) ($metadata['author'] ?? ''));
    $listing->set('isbn', (string) ($metadata['isbn'] ?? ''));
    $listing->set('publisher', (string) ($metadata['publisher'] ?? ''));
    $listing->set('publication_year', (string) ($metadata['publication_year'] ?? ''));
    $listing->set('format', (string) ($metadata['format'] ?? ''));
    $listing->set('language', (string) ($metadata['language'] ?? ''));
    $listing->set('genre', (string) ($metadata['genre'] ?? ''));
    $listing->set('narrative_type', (string) ($metadata['narrative_type'] ?? ''));
    $listing->set('country_printed', (string) ($metadata['country_printed'] ?? ''));
    $listing->set('edition', (string) ($metadata['edition'] ?? ''));
    $listing->set('series', (string) ($metadata['series'] ?? ''));
    $listing->set('features', is_array($metadata['features'] ?? null) ? array_values(array_map('strval', $metadata['features'])) : []);
    $listing->set('ebay_title', (string) ($metadata['ebay_title'] ?? ''));
    $listing->set('description', [
      'value' => (string) ($metadata['description'] ?? ''),
      'format' => 'basic_html',
    ]);

    $listing->set('condition_issues', is_array($condition['issues'] ?? null) ? array_values(array_map('strval', $condition['issues'])) : []);
    $listing->set('condition_grade', (string) ($condition['grade'] ?? 'good'));

    $listing->set('status', 'ready_for_review');

    $listing->save();
  }

}
