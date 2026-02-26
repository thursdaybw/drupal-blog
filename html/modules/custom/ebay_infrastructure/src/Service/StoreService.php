<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\ebay_infrastructure\Service\SellApiClient;

final class StoreService {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
  ) {}

  public function getStore(): array {
    return $this->sellApiClient->getStore();
  }

  public function listStoreCategories(int $limit = 25, int $offset = 0): array {
    return $this->sellApiClient->listStoreCategories($limit, $offset);
  }

}
