<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\listing_publishing\Contract\SkuGeneratorInterface;

final class SkuGenerator implements SkuGeneratorInterface {

  public function generate(AiBookListing $listing, string $uniqueSuffix, ?\DateTimeInterface $when = null): string {
    $date = $when ?? new \DateTimeImmutable();
    $parts = [
      $date->format('Y'),
      $date->format('M'),
    ];

    $location = trim((string) $listing->get('storage_location')->value ?? '');
    if ($location !== '') {
      $parts[] = $location;
    }

    if ($uniqueSuffix !== '') {
      $parts[] = $uniqueSuffix;
    }

    $filtered = array_filter($parts, fn($value) => $value !== '');
    return implode(' ', $filtered);
  }

}
