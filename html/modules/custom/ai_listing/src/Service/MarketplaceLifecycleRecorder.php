<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;

/**
 * Persists durable marketplace lifecycle facts across relists.
 */
final class MarketplaceLifecycleRecorder {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
  ) {}

  public function recordPublished(
    int $listingId,
    string $marketplaceKey,
    ?int $publishedAt = NULL,
    ?string $marketplaceListingId = NULL,
  ): void {
    if ($listingId <= 0) {
      throw new \InvalidArgumentException('Marketplace lifecycle publish requires a positive listing ID.');
    }

    $marketplaceKey = trim($marketplaceKey);
    if ($marketplaceKey === '') {
      throw new \InvalidArgumentException('Marketplace lifecycle publish requires a marketplace key.');
    }

    $publishedAt = ($publishedAt !== NULL && $publishedAt > 0)
      ? $publishedAt
      : $this->time->getRequestTime();

    $row = $this->loadRow($listingId, $marketplaceKey);
    $changedAt = $this->time->getRequestTime();
    $normalizedListingId = $marketplaceListingId !== NULL && trim($marketplaceListingId) !== ''
      ? trim($marketplaceListingId)
      : NULL;

    if ($row === NULL) {
      $this->database->insert('bb_ai_listing_marketplace_lifecycle')
        ->fields([
          'listing_id' => $listingId,
          'marketplace_key' => $marketplaceKey,
          'first_published_at' => $publishedAt,
          'last_published_at' => $publishedAt,
          'last_unpublished_at' => NULL,
          'last_marketplace_listing_id' => $normalizedListingId,
          'relist_count' => 0,
          'created_at' => $changedAt,
          'changed_at' => $changedAt,
        ])
        ->execute();
      return;
    }

    $relistCount = (int) ($row->relist_count ?? 0);
    $lastPublishedAt = isset($row->last_published_at) ? (int) $row->last_published_at : 0;
    $lastUnpublishedAt = isset($row->last_unpublished_at) ? (int) $row->last_unpublished_at : 0;
    if ($lastUnpublishedAt > 0 && $lastUnpublishedAt >= $lastPublishedAt) {
      $relistCount++;
    }

    $fields = [
      'last_published_at' => $publishedAt,
      'last_marketplace_listing_id' => $normalizedListingId,
      'relist_count' => $relistCount,
      'changed_at' => $changedAt,
    ];

    if (empty($row->first_published_at)) {
      $fields['first_published_at'] = $publishedAt;
    }

    $this->database->update('bb_ai_listing_marketplace_lifecycle')
      ->fields($fields)
      ->condition('id', (int) $row->id)
      ->execute();
  }

  public function recordUnpublished(int $listingId, string $marketplaceKey, ?int $unpublishedAt = NULL): void {
    if ($listingId <= 0) {
      throw new \InvalidArgumentException('Marketplace lifecycle unpublish requires a positive listing ID.');
    }

    $marketplaceKey = trim($marketplaceKey);
    if ($marketplaceKey === '') {
      throw new \InvalidArgumentException('Marketplace lifecycle unpublish requires a marketplace key.');
    }

    $unpublishedAt = ($unpublishedAt !== NULL && $unpublishedAt > 0)
      ? $unpublishedAt
      : $this->time->getRequestTime();

    $row = $this->loadRow($listingId, $marketplaceKey);
    $changedAt = $this->time->getRequestTime();

    if ($row === NULL) {
      $this->database->insert('bb_ai_listing_marketplace_lifecycle')
        ->fields([
          'listing_id' => $listingId,
          'marketplace_key' => $marketplaceKey,
          'first_published_at' => NULL,
          'last_published_at' => NULL,
          'last_unpublished_at' => $unpublishedAt,
          'last_marketplace_listing_id' => NULL,
          'relist_count' => 0,
          'created_at' => $changedAt,
          'changed_at' => $changedAt,
        ])
        ->execute();
      return;
    }

    $this->database->update('bb_ai_listing_marketplace_lifecycle')
      ->fields([
        'last_unpublished_at' => $unpublishedAt,
        'changed_at' => $changedAt,
      ])
      ->condition('id', (int) $row->id)
      ->execute();
  }

  private function loadRow(int $listingId, string $marketplaceKey): ?object {
    $row = $this->database->select('bb_ai_listing_marketplace_lifecycle', 'l')
      ->fields('l')
      ->condition('listing_id', $listingId)
      ->condition('marketplace_key', $marketplaceKey)
      ->execute()
      ->fetchObject();

    return $row !== FALSE ? $row : NULL;
  }

}
