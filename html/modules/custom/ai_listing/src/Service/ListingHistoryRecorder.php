<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Writes append-only listing history events.
 */
final class ListingHistoryRecorder {

  public function __construct(
    private readonly Connection $database,
    private readonly TimeInterface $time,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  /**
   * @param array<string,mixed> $context
   */
  public function record(
    int $listingId,
    string $eventType,
    string $message,
    ?string $reasonCode = NULL,
    array $context = [],
  ): void {
    if ($listingId <= 0) {
      throw new \InvalidArgumentException('Listing history requires a positive listing ID.');
    }

    $eventType = trim($eventType);
    if ($eventType === '') {
      throw new \InvalidArgumentException('Listing history requires a non-empty event type.');
    }

    $this->database->insert('bb_ai_listing_history')
      ->fields([
        'listing_id' => $listingId,
        'created_at' => $this->time->getRequestTime(),
        'actor_uid' => $this->currentUser->id() !== '0' ? (int) $this->currentUser->id() : NULL,
        'event_type' => $eventType,
        'reason_code' => $reasonCode !== NULL && trim($reasonCode) !== '' ? trim($reasonCode) : NULL,
        'message' => $message,
        'context_json' => $context !== [] ? json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : NULL,
      ])
      ->execute();
  }

}
