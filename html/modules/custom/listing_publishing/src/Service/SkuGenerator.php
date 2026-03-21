<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Service;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\listing_publishing\Contract\SkuGeneratorInterface;

final class SkuGenerator implements SkuGeneratorInterface {

  public function generate(BbAiListing $listing, string $uniqueSuffix, ?\DateTimeInterface $when = null): string {
    $date = $when ?? new \DateTimeImmutable();
    $parts = [
      $date->format('Y'),
      $date->format('M'),
    ];

    if ($uniqueSuffix !== '') {
      $parts[] = $uniqueSuffix;
    }

    $filtered = array_filter($parts, fn($value) => $value !== '');
    return implode(' ', $filtered);
  }

}
