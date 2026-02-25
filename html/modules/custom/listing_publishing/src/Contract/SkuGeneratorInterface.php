<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Contract;

use Drupal\ai_listing\Entity\AiBookListing;

interface SkuGeneratorInterface {

  public function generate(AiBookListing $listing, string $uniqueSuffix, ?\DateTimeInterface $when = null): string;

}
