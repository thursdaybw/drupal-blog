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
    $title = $this->resolveTitle($listing);
    $description = $this->resolveDescription($listing, $title);
    $author = $this->resolveStringField($listing, 'field_author') ?: self::DEFAULT_AUTHOR;
    $skuSuffix = 'ai-book-' . $listing->id();
    $sku = $this->skuGenerator->generate($listing, $skuSuffix);
    $files = $this->loadImageFiles($listing);
    $imageSources = $this->collectImageSources($files);
    $imageUrls = $this->collectImageUrls($files);
    $condition = (string) ($listing->get('condition_grade')->value ?? self::DEFAULT_CONDITION);
    $price = $this->resolvePrice($listing);
    $attributes = [
      'product_type' => 'book',
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

  private function resolveTitle(BbAiListing $listing): string {
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
    $value = $listing->get($field)->value ?? '';
    return is_string($value) ? $value : '';
  }

  /**
   * @return File[]
   */
  private function loadImageFiles(BbAiListing $listing): array {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

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

}
