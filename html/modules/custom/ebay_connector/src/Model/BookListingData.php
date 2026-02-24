<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Model;

final class BookListingData {

  public function __construct(
    public readonly string $sku,
    public readonly string $title,
    public readonly string $description,
    public readonly string $author,
    public readonly string $price,
    public readonly string $imageUrl,
    public readonly int $quantity,
    public readonly string $conditionId,
  ) {}
}
