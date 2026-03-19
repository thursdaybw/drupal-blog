<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\ai_listing\History\ListingHistoryEntry;
use Drupal\Core\Database\Connection;

/**
 * Read model query service for listing history.
 */
final class ListingHistoryQuery {

  public function __construct(
    private readonly Connection $database,
  ) {}

  /**
   * @return \Drupal\ai_listing\History\ListingHistoryEntry[]
   */
  public function fetchByListingId(int $listingId, int $limit = 50): array {
    if ($listingId <= 0) {
      return [];
    }

    $query = $this->database->select('bb_ai_listing_history', 'history');
    $query->fields('history', [
      'id',
      'listing_id',
      'created_at',
      'actor_uid',
      'event_type',
      'reason_code',
      'message',
      'context_json',
    ]);
    $query->condition('listing_id', $listingId);
    $query->orderBy('created_at', 'DESC');
    $query->orderBy('id', 'DESC');
    $query->range(0, $limit);

    $entries = [];
    foreach ($query->execute() as $record) {
      $decoded = [];
      if (is_string($record->context_json) && $record->context_json !== '') {
        $decoded = json_decode($record->context_json, TRUE);
        $decoded = is_array($decoded) ? $decoded : [];
      }

      $entries[] = new ListingHistoryEntry(
        (int) $record->id,
        (int) $record->listing_id,
        (int) $record->created_at,
        $record->actor_uid !== NULL ? (int) $record->actor_uid : NULL,
        (string) $record->event_type,
        $record->reason_code !== NULL ? (string) $record->reason_code : NULL,
        (string) $record->message,
        $decoded,
      );
    }

    return $entries;
  }

}
