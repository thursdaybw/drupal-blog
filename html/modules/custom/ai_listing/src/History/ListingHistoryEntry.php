<?php

declare(strict_types=1);

namespace Drupal\ai_listing\History;

/**
 * Immutable read model for one listing history entry.
 */
final class ListingHistoryEntry {

  public function __construct(
    public readonly int $id,
    public readonly int $listingId,
    public readonly int $createdAt,
    public readonly ?int $actorUid,
    public readonly string $eventType,
    public readonly ?string $reasonCode,
    public readonly string $message,
    public readonly array $context,
  ) {}

}
