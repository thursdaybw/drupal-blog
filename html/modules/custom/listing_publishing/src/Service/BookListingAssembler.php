<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\file\Entity\File;
use Drupal\listing_publishing\Model\ListingImageSource;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\Core\File\FileUrlGeneratorInterface;

final class BookListingAssembler {

  private const FALLBACK_IMAGE = 'https://via.placeholder.com/1024';
  private const DEFAULT_PRICE = '29.95';
  private const DEFAULT_QUANTITY = 1;
  private const DEFAULT_CONDITION = 'good';
  private const DEFAULT_AUTHOR = 'Unknown';

  public function __construct(private readonly FileUrlGeneratorInterface $fileUrlGenerator) {}

  public function assemble(AiBookListing $listing): ListingPublishRequest {
    $title = $this->resolveTitle($listing);
    $description = $this->resolveDescription($listing, $title);
    $author = (string) ($listing->get('author')->value ?: self::DEFAULT_AUTHOR);
    $sku = 'ai-book-' . $listing->id();
    $files = $listing->get('images')->referencedEntities();
    $imageSources = $this->collectImageSources($files);
    $imageUrls = $this->collectImageUrls($files);
    $condition = (string) ($listing->get('condition_grade')->value ?? self::DEFAULT_CONDITION);
    $price = $this->resolvePrice($listing);
    $attributes = [
      'product_type' => 'book',
      'author' => $author,
      'language' => $this->resolveStringField($listing, 'language') ?: 'English',
      'isbn' => $this->resolveStringField($listing, 'isbn'),
      'publisher' => $this->resolveStringField($listing, 'publisher'),
      'publication_year' => $this->resolveStringField($listing, 'publication_year'),
      'format' => $this->resolveStringField($listing, 'format'),
      'genre' => $this->resolveStringField($listing, 'genre'),
      'topic' => $this->resolveStringField($listing, 'narrative_type'),
      'country_of_origin' => $this->resolveStringField($listing, 'country_printed'),
      'series' => $this->resolveStringField($listing, 'series'),
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

  private function resolveTitle(AiBookListing $listing): string {
    $title = (string) $listing->get('title')->value;
    if ($title !== '') {
      return $title;
    }

    $fullTitle = (string) $listing->get('full_title')->value;
    if ($fullTitle !== '') {
      return $fullTitle;
    }

    return 'Untitled AI Listing';
  }

  private function resolveDescription(AiBookListing $listing, string $title): string {
    $description = (string) $listing->get('description')->value;
    if ($description !== '') {
      return $description;
    }

    return "AI-assisted metadata for {$title}.";
  }

  private function resolvePrice(AiBookListing $listing): string {
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

  private function resolveStringField(AiBookListing $listing, string $field): string {
    $value = $listing->get($field)->value ?? '';
    return is_string($value) ? $value : '';
  }

}
