<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
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

  public function process(BbAiListing $listing): void {
    if ($listing->bundle() === 'book_bundle') {
      $this->processBookBundleListing($listing);
      return;
    }

    $this->processSingleBookListing($listing);
  }

  private function processSingleBookListing(BbAiListing $listing): void {
    $imagePaths = $this->loadOwnerImagePaths('bb_ai_listing', (int) $listing->id(), FALSE);

    if (empty($imagePaths)) {
      throw new \RuntimeException('No images attached.');
    }

    $metadataImagePaths = $this->loadOwnerImagePaths('bb_ai_listing', (int) $listing->id(), TRUE);

    if (empty($metadataImagePaths)) {
      throw new \RuntimeException('No metadata source images selected.');
    }

    $result = $this->bookExtraction->extract($imagePaths, $metadataImagePaths);

    $metadata = $result['metadata'] ?? [];
    $condition = $result['condition'] ?? ['issues' => []];

    $listing->set('metadata_json', json_encode($metadata, JSON_PRETTY_PRINT));
    $listing->set('condition_json', json_encode($condition, JSON_PRETTY_PRINT));

    $this->setListingFieldIfExists($listing, 'field_title', (string) ($metadata['title'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_subtitle', (string) ($metadata['subtitle'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_full_title', (string) ($metadata['full_title'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_author', (string) ($metadata['author'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_isbn', (string) ($metadata['isbn'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_publisher', (string) ($metadata['publisher'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_publication_year', (string) ($metadata['publication_year'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_format', (string) ($metadata['format'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_language', (string) ($metadata['language'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_genre', (string) ($metadata['genre'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_narrative_type', (string) ($metadata['narrative_type'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_country_printed', $this->normalizeCountry((string) ($metadata['country_printed'] ?? '')));
    $this->setListingFieldIfExists($listing, 'field_edition', (string) ($metadata['edition'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_series', (string) ($metadata['series'] ?? ''));
    $this->setListingFieldIfExists($listing, 'field_features', $this->normalizeStringList($metadata['features'] ?? null));
    $listing->set('ebay_title', (string) ($metadata['ebay_title'] ?? ''));
    $listing->set('description', [
      'value' => (string) ($metadata['description'] ?? ''),
      'format' => 'basic_html',
    ]);

    $this->setListingFieldIfExists($listing, 'field_condition_issues', $this->normalizeStringList($condition['issues'] ?? null));
    $listing->set('condition_grade', (string) ($condition['grade'] ?? 'good'));

    $listing->set('status', 'ready_for_review');

    $listing->save();
  }

  private function processBookBundleListing(BbAiListing $listing): void {
    $bundleItems = $this->loadBundleItemsForInference($listing);
    if ($bundleItems === []) {
      throw new \RuntimeException('No bundle items found.');
    }

    $processedItems = [];

    foreach ($bundleItems as $bundleItem) {
      $bundleItemId = (int) $bundleItem->id();
      $allImagePaths = $this->loadOwnerImagePaths('ai_book_bundle_item', $bundleItemId, FALSE);
      if ($allImagePaths === []) {
        throw new \RuntimeException(sprintf('Bundle item %d has no images attached.', $bundleItemId));
      }

      $metadataImagePaths = $this->loadOwnerImagePaths('ai_book_bundle_item', $bundleItemId, TRUE);
      if ($metadataImagePaths === []) {
        throw new \RuntimeException(sprintf('Bundle item %d has no metadata source images selected.', $bundleItemId));
      }

      $result = $this->bookExtraction->extract($allImagePaths, $metadataImagePaths);
      $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
      $condition = is_array($result['condition'] ?? null) ? $result['condition'] : ['issues' => []];
      $conditionIssues = $this->normalizeStringList($condition['issues'] ?? null);
      $conditionGrade = (string) ($condition['grade'] ?? 'good');
      $conditionNote = (string) ($condition['note'] ?? '');

      $bundleItem->set('title', (string) ($metadata['full_title'] ?? $metadata['title'] ?? ''));
      $bundleItem->set('author', (string) ($metadata['author'] ?? ''));
      $bundleItem->set('isbn', (string) ($metadata['isbn'] ?? ''));
      $bundleItem->set('metadata_json', json_encode($metadata, JSON_PRETTY_PRINT));
      $bundleItem->set('condition_json', json_encode($condition, JSON_PRETTY_PRINT));
      $bundleItem->set('condition_issues', $conditionIssues);
      $bundleItem->set('condition_grade', $conditionGrade);
      $bundleItem->set('notes', $conditionNote);
      $bundleItem->save();

      $processedItems[] = [
        'id' => $bundleItemId,
        'metadata' => $metadata,
        'condition' => $condition,
      ];
    }

    if ($processedItems === []) {
      throw new \RuntimeException('No bundle items could be inferred.');
    }

    $aggregate = $this->aggregateBundleResults($processedItems);

    $this->setListingFieldIfExists($listing, 'field_title', $aggregate['field_title']);
    $this->setListingFieldIfExists($listing, 'field_subtitle', $aggregate['field_subtitle']);
    $this->setListingFieldIfExists($listing, 'field_full_title', $aggregate['field_full_title']);
    $this->setListingFieldIfExists($listing, 'field_author', $aggregate['field_author']);
    $this->setListingFieldIfExists($listing, 'field_isbn', $aggregate['field_isbn']);
    $this->setListingFieldIfExists($listing, 'field_publisher', $aggregate['field_publisher']);
    $this->setListingFieldIfExists($listing, 'field_publication_year', $aggregate['field_publication_year']);
    $this->setListingFieldIfExists($listing, 'field_series', $aggregate['field_series']);
    $this->setListingFieldIfExists($listing, 'field_language', $aggregate['field_language']);
    $this->setListingFieldIfExists($listing, 'field_narrative_type', $aggregate['field_narrative_type']);
    $this->setListingFieldIfExists($listing, 'field_country_printed', $aggregate['field_country_printed']);
    $this->setListingFieldIfExists($listing, 'field_genre', $aggregate['field_genre']);
    $this->setListingFieldIfExists($listing, 'field_features', ['bundle']);
    $this->setListingFieldIfExists($listing, 'field_format', $aggregate['field_format']);
    $this->setListingFieldIfExists($listing, 'field_edition', $aggregate['field_edition']);
    $this->setListingFieldIfExists($listing, 'field_condition_issues', $aggregate['condition_issues']);
    $listing->set('condition_grade', $aggregate['condition_grade']);
    $listing->set('metadata_json', json_encode(['bundle_items' => $processedItems], JSON_PRETTY_PRINT));
    $listing->set('condition_json', json_encode([
      'issues' => $aggregate['condition_issues'],
      'grade' => $aggregate['condition_grade'],
    ], JSON_PRETTY_PRINT));

    $existingEbayTitle = trim((string) ($listing->get('ebay_title')->value ?? ''));
    if ($existingEbayTitle === '') {
      $listing->set('ebay_title', $aggregate['ebay_title']);
    }

    $listing->set('description', [
      'value' => $aggregate['description'],
      'format' => 'basic_html',
    ]);
    $listing->set('status', 'ready_for_review');
    $listing->save();
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  private function loadBundleItemsForInference(BbAiListing $listing): array {
    if (!$this->entityTypeManager->hasDefinition('ai_book_bundle_item')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('ai_book_bundle_item');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle_listing', (int) $listing->id())
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if ($ids === []) {
      return [];
    }

    return $storage->loadMultiple($ids);
  }

  /**
   * @param array<int,array{id:int,metadata:array<string,mixed>,condition:array<string,mixed>}> $processedItems
   * @return array{
   *   field_title:string,
   *   field_subtitle:string,
   *   field_full_title:string,
   *   field_author:string,
   *   field_isbn:string,
   *   field_publisher:string,
   *   field_publication_year:string,
   *   field_series:string,
   *   field_language:string,
   *   field_narrative_type:string,
   *   field_country_printed:string,
   *   field_format:string,
   *   field_edition:string,
   *   field_genre:string,
   *   condition_issues:array<int,string>,
   *   condition_grade:string,
   *   ebay_title:string,
   *   description:string
   * }
   */
  private function aggregateBundleResults(array $processedItems): array {
    $firstTitle = '';
    $firstSubtitle = '';
    $firstFullTitle = '';
    $firstIsbn = '';
    $firstPublisher = '';
    $firstPublicationYear = '';
    $firstSeries = '';
    $firstLanguage = '';
    $firstNarrativeType = '';
    $firstCountryPrinted = '';
    $firstFormat = '';
    $firstEdition = '';
    $authors = [];
    $genreCounts = [];
    $allIssues = [];
    $conditionGrades = [];
    $descriptionBlocks = [];

    foreach ($processedItems as $index => $itemData) {
      $metadata = $itemData['metadata'];
      $condition = $itemData['condition'];
      $title = trim((string) ($metadata['full_title'] ?? $metadata['title'] ?? ''));
      $author = trim((string) ($metadata['author'] ?? ''));
      $genre = trim((string) ($metadata['genre'] ?? ''));
      $issues = $this->normalizeStringList($condition['issues'] ?? null);
      $grade = trim((string) ($condition['grade'] ?? 'good'));

      if ($firstTitle === '' && $title !== '') {
        $firstTitle = $title;
      }
      if ($firstSubtitle === '') {
        $firstSubtitle = trim((string) ($metadata['subtitle'] ?? ''));
      }
      if ($firstFullTitle === '') {
        $firstFullTitle = trim((string) ($metadata['full_title'] ?? ''));
      }
      if ($firstIsbn === '') {
        $firstIsbn = trim((string) ($metadata['isbn'] ?? ''));
      }
      if ($firstPublisher === '') {
        $firstPublisher = trim((string) ($metadata['publisher'] ?? ''));
      }
      if ($firstPublicationYear === '') {
        $firstPublicationYear = trim((string) ($metadata['publication_year'] ?? ''));
      }
      if ($firstSeries === '') {
        $firstSeries = trim((string) ($metadata['series'] ?? ''));
      }
      if ($firstLanguage === '') {
        $firstLanguage = trim((string) ($metadata['language'] ?? ''));
      }
      if ($firstNarrativeType === '') {
        $firstNarrativeType = trim((string) ($metadata['narrative_type'] ?? ''));
      }
      if ($firstCountryPrinted === '') {
        $firstCountryPrinted = trim((string) ($metadata['country_printed'] ?? ''));
      }
      if ($firstFormat === '') {
        $firstFormat = trim((string) ($metadata['format'] ?? ''));
      }
      if ($firstEdition === '') {
        $firstEdition = trim((string) ($metadata['edition'] ?? ''));
      }

      if ($author !== '' && !in_array($author, $authors, TRUE)) {
        $authors[] = $author;
      }

      if ($genre !== '') {
        if (!isset($genreCounts[$genre])) {
          $genreCounts[$genre] = 0;
        }
        $genreCounts[$genre]++;
      }

      $allIssues = array_values(array_unique(array_merge($allIssues, $issues)));
      $conditionGrades[] = $grade;

      $itemNumber = $index + 1;
      $labelTitle = $title !== '' ? $title : sprintf('Book %d', $itemNumber);
      $line = $itemNumber . '. <strong>' . htmlspecialchars($labelTitle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</strong>';
      if ($author !== '') {
        $line .= ' — ' . htmlspecialchars($author, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      }

      $descriptionLineParts = [$line];

      $isbn = trim((string) ($metadata['isbn'] ?? ''));
      if ($isbn !== '') {
        $descriptionLineParts[] = 'ISBN: ' . htmlspecialchars($isbn, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      }

      if ($issues !== []) {
        $descriptionLineParts[] = 'Condition: ' . htmlspecialchars(implode(', ', $issues), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
      }

      $descriptionBlocks[] = implode('<br>', $descriptionLineParts);
    }

    if ($firstTitle === '') {
      $firstTitle = 'Book Bundle';
    }

    arsort($genreCounts);
    $topGenre = $genreCounts === [] ? '' : (string) array_key_first($genreCounts);

    $authorsText = $authors === [] ? '' : implode(', ', $authors);
    $worstGrade = $this->selectWorstConditionGrade($conditionGrades);

    $description = '<p>Bundle includes ' . count($processedItems) . ' books.</p>';
    $description .= '<p>' . implode('</p><p>', $descriptionBlocks) . '</p>';
    $description .= '<p>Please refer to photos for full details.</p>';

    return [
      'field_title' => $firstTitle,
      'field_subtitle' => $firstSubtitle,
      'field_full_title' => $firstFullTitle !== '' ? $firstFullTitle : $firstTitle,
      'field_author' => $authorsText,
      'field_isbn' => $firstIsbn,
      'field_publisher' => $firstPublisher,
      'field_publication_year' => $firstPublicationYear,
      'field_series' => $firstSeries,
      'field_language' => $firstLanguage,
      'field_narrative_type' => $firstNarrativeType,
      'field_country_printed' => $this->normalizeCountry($firstCountryPrinted),
      'field_format' => $firstFormat,
      'field_edition' => $firstEdition,
      'field_genre' => $topGenre,
      'condition_issues' => $allIssues,
      'condition_grade' => $worstGrade,
      'ebay_title' => 'Book Bundle - ' . $firstTitle,
      'description' => $description,
    ];
  }

  /**
   * @param array<int,string> $grades
   */
  private function selectWorstConditionGrade(array $grades): string {
    $rank = [
      'acceptable' => 1,
      'good' => 2,
      'very_good' => 3,
      'like_new' => 4,
    ];

    $worst = 'good';
    $worstRank = $rank[$worst];

    foreach ($grades as $grade) {
      $normalized = trim((string) $grade);
      if (!isset($rank[$normalized])) {
        continue;
      }

      if ($rank[$normalized] < $worstRank) {
        $worst = $normalized;
        $worstRank = $rank[$normalized];
      }
    }

    return $worst;
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
  private function loadOwnerImagePaths(string $ownerTargetType, int $ownerTargetId, bool $metadataOnly): array {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    $listingImageStorage = $this->entityTypeManager->getStorage('listing_image');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $imagePaths = [];

    $ids = $listingImageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', $ownerTargetType)
      ->condition('owner.target_id', $ownerTargetId)
      ->condition('is_metadata_source', $metadataOnly ? 1 : [0, 1], $metadataOnly ? '=' : 'IN')
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $listingImages = $listingImageStorage->loadMultiple($ids);
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

  /**
   * @return array<int,string>
   */
  private function normalizeStringList(mixed $value): array {
    if (!is_array($value)) {
      return [];
    }

    $normalized = [];
    foreach ($value as $item) {
      $text = trim((string) $item);
      if ($text !== '') {
        $normalized[] = $text;
      }
    }

    return array_values(array_unique($normalized));
  }

  private function setListingFieldIfExists(BbAiListing $listing, string $fieldName, mixed $value): void {
    if (!$listing->hasField($fieldName)) {
      return;
    }

    $listing->set($fieldName, $value);
  }

  private function resolveExistingFilePath(string $uri): string {
    $realPath = $this->fileSystem->realpath($uri);

    if (!$realPath || !file_exists($realPath)) {
      throw new \RuntimeException("File not found: {$uri}");
    }

    return $realPath;
  }
}
