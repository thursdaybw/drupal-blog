<?php

declare(strict_types=1);

namespace Drupal\listing_publishing\Contract;

use Drupal\ai_listing\Entity\BbAiListing;

interface SkuGeneratorInterface {

  public function generate(BbAiListing $listing, string $uniqueSuffix, ?\DateTimeInterface $when = null): string;

}
