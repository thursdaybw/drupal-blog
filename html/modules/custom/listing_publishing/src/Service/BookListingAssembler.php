<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\file\Entity\File;
use Drupal\listing_publishing\Model\ListingImageSource;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Contract\SkuGeneratorInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;

final class BookListingAssembler {

  private const FALLBACK_IMAGE = 'https://via.placeholder.com/1024';
  private const DEFAULT_PRICE = '29.95';
  private const DEFAULT_QUANTITY = 1;
  private const DEFAULT_CONDITION = 'good';
  private const DEFAULT_AUTHOR = 'Unknown';

  public function __construct(
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly SkuGeneratorInterface $skuGenerator,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public function assemble(BbAiListing $listing): ListingPublishRequest {
    $title = $this->resolveEbayListingTitle($listing);
    $bookTitle = $this->resolveBookTitle($listing);
    $description = $this->resolveDescription($listing, $title);
    $author = $this->resolveAuthor($listing);
    $skuSuffix = 'ai-book-' . $listing->id();
    $sku = $this->skuGenerator->generate($listing, $skuSuffix);
    $files = $this->loadImageFiles($listing);
    $imageSources = $this->collectImageSources($files);
    $imageUrls = $this->collectImageUrls($files);
    $condition = (string) ($listing->get('condition_grade')->value ?? self::DEFAULT_CONDITION);
    $price = $this->resolvePrice($listing);
    $attributes = [
      'product_type' => 'book',
      'book_title' => $bookTitle,
      'author' => $author,
      'language' => $this->resolveStringField($listing, 'field_language') ?: 'English',
      'isbn' => $this->resolveStringField($listing, 'field_isbn'),
      'publisher' => $this->resolveStringField($listing, 'field_publisher'),
      'publication_year' => $this->resolveStringField($listing, 'field_publication_year'),
      'format' => $this->resolveStringField($listing, 'field_format'),
      'genre' => $this->resolveStringField($listing, 'field_genre'),
      'topic' => $this->resolveStringField($listing, 'field_narrative_type'),
      'country_of_origin' => $this->resolveStringField($listing, 'field_country_printed'),
      'series' => $this->resolveStringField($listing, 'field_series'),
      'bargain_bin' => (bool) $listing->get('bargain_bin')->value,
    ];

    return new ListingPublishRequest(
        $sku,
        $title,
        $description,
        $author,
        $price,
        $imageSources,
        $imageUrls,
        self::DEFAULT_QUANTITY,
        $condition,
      $attributes
    );
  }

  private function resolveEbayListingTitle(BbAiListing $listing): string {
    $ebayTitle = trim((string) ($listing->get('ebay_title')->value ?? ''));
    if ($ebayTitle !== '') {
      return $this->truncateEbayTitle($ebayTitle);
    }

    $title = $this->resolveStringField($listing, 'field_title');
    if ($title !== '') {
      return $this->truncateEbayTitle($title);
    }

    $fullTitle = $this->resolveStringField($listing, 'field_full_title');
    if ($fullTitle !== '') {
      return $this->truncateEbayTitle($fullTitle);
    }

    return $this->truncateEbayTitle('Untitled AI Listing');
  }

  private function truncateEbayTitle(string $title): string {
    $title = preg_replace('/\s+/', ' ', trim($title));
    return (string) mb_substr($title, 0, 80);
  }

  private function resolveBookTitle(BbAiListing $listing): string {
    $title = $this->resolveStringField($listing, 'field_title');
    if ($title !== '') {
      return $title;
    }

    $fullTitle = $this->resolveStringField($listing, 'field_full_title');
    if ($fullTitle !== '') {
      return $fullTitle;
    }

    return 'Untitled AI Listing';
  }

  private function resolveDescription(BbAiListing $listing, string $title): string {
    $description = (string) $listing->get('description')->value;
    if ($description !== '') {
      return $description;
    }

    return "AI-assisted metadata for {$title}.";
  }

  private function resolveAuthor(BbAiListing $listing): string {
    $author = trim($this->resolveStringField($listing, 'field_author'));
    if ($author !== '') {
      return $author;
    }

    if ($listing->bundle() !== 'book_bundle') {
      return self::DEFAULT_AUTHOR;
    }

    $metadataJson = (string) ($listing->get('metadata_json')->value ?? '');
    if ($metadataJson === '') {
      return self::DEFAULT_AUTHOR;
    }

    $decoded = json_decode($metadataJson, true);
    if (!is_array($decoded)) {
      return self::DEFAULT_AUTHOR;
    }

    $bundleItems = $decoded['bundle_items'] ?? null;
    if (!is_array($bundleItems)) {
      return self::DEFAULT_AUTHOR;
    }

    $authors = [];
    foreach ($bundleItems as $item) {
      if (!is_array($item)) {
        continue;
      }

      $metadata = $item['metadata'] ?? null;
      if (!is_array($metadata)) {
        continue;
      }

      $itemAuthor = trim((string) ($metadata['author'] ?? ''));
      if ($itemAuthor === '') {
        continue;
      }

      if (!in_array($itemAuthor, $authors, TRUE)) {
        $authors[] = $itemAuthor;
      }
    }

    if ($authors === []) {
      return self::DEFAULT_AUTHOR;
    }

    return implode(', ', $authors);
  }

  private function resolvePrice(BbAiListing $listing): string {
    $value = $listing->get('price')->value ?? '';
    $resolved = is_numeric($value) ? (string) $value : '';
    return $resolved !== '' ? $resolved : self::DEFAULT_PRICE;
  }

  /**
   * @param iterable<\Drupal\file\Entity\File|mixed> $files
   *
   * @return ListingImageSource[]
   */
  private function collectImageSources(iterable $files): array {
    $sources = [];

    foreach ($files as $file) {
      if ($file instanceof File) {
        $sources[] = ListingImageSource::fromFile($file);
      }
    }

    return $sources;
  }

  /**
   * @param iterable<\Drupal\file\Entity\File|mixed> $files
   */
  private function collectImageUrls(iterable $files): array {
    $urls = [];

    foreach ($files as $file) {
      if ($file instanceof File) {
        $urls[] = $this->fileUrlGenerator->generateAbsoluteString($file->getFileUri());
      }
    }

    if ([] === $urls) {
      $urls[] = self::FALLBACK_IMAGE;
    }

    return $urls;
  }

  private function resolveStringField(BbAiListing $listing, string $field): string {
    if ($listing->hasField($field)) {
      $value = $listing->get($field)->value ?? '';
      $resolved = is_string($value) ? trim($value) : '';
      if ($resolved !== '') {
        return $resolved;
      }
    }

    if ($listing->bundle() === 'book_bundle') {
      $fallback = $this->resolveBundleMetadataFallback($listing, $field);
      if ($fallback !== '') {
        return $fallback;
      }
    }

    return '';
  }

  private function resolveBundleMetadataFallback(BbAiListing $listing, string $field): string {
    $metadataJson = (string) ($listing->get('metadata_json')->value ?? '');
    if ($metadataJson === '') {
      return '';
    }

    $decoded = json_decode($metadataJson, true);
    if (!is_array($decoded)) {
      return '';
    }

    $bundleItems = $decoded['bundle_items'] ?? null;
    if (!is_array($bundleItems)) {
      return '';
    }

    $firstItem = $bundleItems[0] ?? null;
    if (!is_array($firstItem)) {
      return '';
    }

    $metadata = $firstItem['metadata'] ?? null;
    if (!is_array($metadata)) {
      return '';
    }

    $fieldMap = [
      'field_title' => 'title',
      'field_subtitle' => 'subtitle',
      'field_full_title' => 'full_title',
      'field_author' => 'author',
      'field_isbn' => 'isbn',
      'field_publisher' => 'publisher',
      'field_publication_year' => 'publication_year',
      'field_series' => 'series',
      'field_format' => 'format',
      'field_language' => 'language',
      'field_genre' => 'genre',
      'field_narrative_type' => 'narrative_type',
      'field_country_printed' => 'country_printed',
      'field_edition' => 'edition',
    ];

    $metadataKey = $fieldMap[$field] ?? null;
    if ($metadataKey === null) {
      return '';
    }

    return trim((string) ($metadata[$metadataKey] ?? ''));
  }

  /**
   * @return File[]
   */
  private function loadImageFiles(BbAiListing $listing): array {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    if ($listing->bundle() === 'book_bundle') {
      return $this->loadBookBundleImageFiles($listing);
    }

    return $this->loadBookListingImageFiles($listing);
  }

  /**
   * @return File[]
   */
  private function loadBookListingImageFiles(BbAiListing $listing): array {
    $listingImageStorage = $this->entityTypeManager->getStorage('listing_image');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $ids = $listingImageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', 'bb_ai_listing')
      ->condition('owner.target_id', (int) $listing->id())
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->range(0, 24)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $listingImages = $listingImageStorage->loadMultiple($ids);
    $files = [];
    foreach ($listingImages as $listingImage) {
      $fileId = (int) ($listingImage->get('file')->target_id ?? 0);
      if ($fileId <= 0) {
        continue;
      }
      $file = $fileStorage->load($fileId);
      if ($file instanceof File) {
        $files[] = $file;
      }
    }

    return $files;
  }

  /**
   * @return File[]
   */
  private function loadBookBundleImageFiles(BbAiListing $listing): array {
    $listingImageStorage = $this->entityTypeManager->getStorage('listing_image');
    $fileStorage = $this->entityTypeManager->getStorage('file');
    $files = [];
    $seenFileIds = [];
    $remaining = 24;

    // 1) Bundle-level listing images first.
    $bundleLevelIds = $listingImageStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', 'bb_ai_listing')
      ->condition('owner.target_id', (int) $listing->id())
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    $bundleLevelImages = $bundleLevelIds === [] ? [] : $listingImageStorage->loadMultiple($bundleLevelIds);
    foreach ($bundleLevelImages as $listingImage) {
      if ($remaining <= 0) {
        break;
      }

      $fileId = (int) ($listingImage->get('file')->target_id ?? 0);
      if ($fileId <= 0 || isset($seenFileIds[$fileId])) {
        continue;
      }

      $file = $fileStorage->load($fileId);
      if (!$file instanceof File) {
        continue;
      }

      $files[] = $file;
      $seenFileIds[$fileId] = true;
      $remaining--;
    }

    if ($remaining <= 0 || !$this->entityTypeManager->hasDefinition('ai_book_bundle_item')) {
      return $files;
    }

    // 2) Then per-item images ordered by bundle item weight.
    $bundleItemStorage = $this->entityTypeManager->getStorage('ai_book_bundle_item');
    $bundleItemIds = $bundleItemStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle_listing', (int) $listing->id())
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    foreach (array_values($bundleItemIds) as $bundleItemId) {
      if ($remaining <= 0) {
        break;
      }

      $itemImageIds = $listingImageStorage->getQuery()
        ->accessCheck(FALSE)
        ->condition('owner.target_type', 'ai_book_bundle_item')
        ->condition('owner.target_id', (int) $bundleItemId)
        ->sort('weight', 'ASC')
        ->sort('id', 'ASC')
        ->execute();

      $itemImages = $itemImageIds === [] ? [] : $listingImageStorage->loadMultiple($itemImageIds);
      foreach ($itemImages as $listingImage) {
        if ($remaining <= 0) {
          break;
        }

        $fileId = (int) ($listingImage->get('file')->target_id ?? 0);
        if ($fileId <= 0 || isset($seenFileIds[$fileId])) {
          continue;
        }

        $file = $fileStorage->load($fileId);
        if (!$file instanceof File) {
          continue;
        }

        $files[] = $file;
        $seenFileIds[$fileId] = true;
        $remaining--;
      }
    }

    return $files;
  }

}
