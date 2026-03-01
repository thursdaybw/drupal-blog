<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Refreshes derived eBay titles for stored book bundle listings.
 */
final class BundleEbayTitleRefreshService {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly BundleEbayTitleBuilder $bundleEbayTitleBuilder,
  ) {}

  /**
   * @return int[]
   */
  public function getBundleListingIdsByStatus(string $status): array {
    $storage = $this->entityTypeManager->getStorage('bb_ai_listing');

    return $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing_type', 'book_bundle')
      ->condition('status', $status)
      ->sort('id', 'ASC')
      ->execute();
  }

  public function loadListing(int $listingId): ?BbAiListing {
    $listing = $this->entityTypeManager->getStorage('bb_ai_listing')->load($listingId);
    if (!$listing instanceof BbAiListing) {
      return NULL;
    }

    if ($listing->bundle() !== 'book_bundle') {
      return NULL;
    }

    return $listing;
  }

  public function refreshListing(BbAiListing $listing): bool {
    $bundleItems = $this->loadBundleItems($listing);
    $titleItems = $this->buildTitleItems($bundleItems);
    $derivedTitle = $this->bundleEbayTitleBuilder->deriveTitle($titleItems);
    $currentTitle = trim((string) ($listing->get('ebay_title')->value ?? ''));

    if ($currentTitle === $derivedTitle) {
      return FALSE;
    }

    $listing->set('ebay_title', $derivedTitle);
    $listing->save();

    return TRUE;
  }

  /**
   * @return \Drupal\Core\Entity\EntityInterface[]
   */
  private function loadBundleItems(BbAiListing $listing): array {
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
   * @param \Drupal\Core\Entity\EntityInterface[] $bundleItems
   * @return array<int,array{title:string,author:string,genre:string}>
   */
  private function buildTitleItems(array $bundleItems): array {
    $items = [];

    foreach ($bundleItems as $bundleItem) {
      $metadata = $this->decodeMetadata((string) ($bundleItem->get('metadata_json')->value ?? ''));
      $items[] = [
        'title' => trim((string) ($bundleItem->get('title')->value ?? '')),
        'author' => trim((string) ($bundleItem->get('author')->value ?? '')),
        'genre' => trim((string) ($metadata['genre'] ?? '')),
      ];
    }

    return $items;
  }

  /**
   * @return array<string,mixed>
   */
  private function decodeMetadata(string $metadataJson): array {
    if ($metadataJson === '') {
      return [];
    }

    $decoded = json_decode($metadataJson, TRUE);
    if (!is_array($decoded)) {
      return [];
    }

    return $decoded;
  }

}
