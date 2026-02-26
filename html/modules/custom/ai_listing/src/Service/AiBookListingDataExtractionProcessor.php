<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\ai_listing_inference\Service\BookExtractionService;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;

/**
 * Normalizes AI output for book listings.
 */
final class AiBookListingDataExtractionProcessor {

  public function __construct(
    private readonly BookExtractionService $bookExtraction,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public function process(AiBookListing $listing): void {
    $imagePaths = $this->loadAllImagePaths($listing);

    if (empty($imagePaths)) {
      throw new \RuntimeException('No images attached.');
    }

    $metadataImagePaths = $this->loadMetadataImagePaths($listing);

    if (empty($metadataImagePaths)) {
      return;
    }

    $result = $this->bookExtraction->extract($imagePaths, $metadataImagePaths);

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
    $listing->set('country_printed', $this->normalizeCountry((string) ($metadata['country_printed'] ?? '')));
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

  private function normalizeCountry(string $value): string {
    $value = trim($value);
    if ($value === '') {
      return '';
    }

    $map = [
      'UK' => 'United Kingdom',
      'U.K.' => 'United Kingdom',
      'England' => 'United Kingdom',
      'Scotland' => 'United Kingdom',
    ];

    return $map[$value] ?? $value;
  }

  /**
   * @return string[]
   */
  private function loadAllImagePaths(AiBookListing $listing): array {
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $imagePaths = [];

    foreach ($listing->get('images') as $item) {
      $fileId = (int) ($item->target_id ?? 0);
      if ($fileId === 0) {
        continue;
      }

      $file = $fileStorage->load($fileId);
      if (!$file) {
        continue;
      }

      $imagePaths[] = $this->resolveExistingFilePath($file->getFileUri());
    }

    return $imagePaths;
  }

  /**
   * @return string[]
   */
  private function loadMetadataImagePaths(AiBookListing $listing): array {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    $listingImageStorage = $this->entityTypeManager->getStorage('listing_image');
    $fileStorage = $this->entityTypeManager->getStorage('file');

    $ids = $listingImageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listing->id())
      ->condition('is_metadata_source', 1)
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $listingImages = $listingImageStorage->loadMultiple($ids);
    $imagePaths = [];

    foreach ($listingImages as $listingImage) {
      $fileId = (int) ($listingImage->get('file')->target_id ?? 0);
      if ($fileId === 0) {
        continue;
      }

      $file = $fileStorage->load($fileId);
      if (!$file) {
        continue;
      }

      $imagePaths[] = $this->resolveExistingFilePath($file->getFileUri());
    }

    return $imagePaths;
  }

  private function resolveExistingFilePath(string $uri): string {
    $realPath = $this->fileSystem->realpath($uri);

    if (!$realPath || !file_exists($realPath)) {
      throw new \RuntimeException("File not found: {$uri}");
    }

    return $realPath;
  }

}
