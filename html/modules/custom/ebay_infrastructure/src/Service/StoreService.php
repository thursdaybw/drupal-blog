<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\ebay_infrastructure\Service\SellApiClient;

final class StoreService {

  private bool $categoriesLoaded = FALSE;
  private array $categoryPaths = [];

  public function __construct(
    private readonly SellApiClient $sellApiClient,
  ) {}

  public function getStore(): array {
    return $this->sellApiClient->getStore();
  }

  public function getStoreCategoryPath(string $categoryId): ?string {
    $this->ensureCategoriesLoaded();
    return $this->categoryPaths[$categoryId] ?? NULL;
  }

  private function ensureCategoriesLoaded(): void {
    if ($this->categoriesLoaded) {
      return;
    }

    $data = $this->sellApiClient->listStoreCategories(1000, 0);
    $categories = $data['storeCategories'] ?? [];
    $this->buildPaths($categories, '');
    $this->categoriesLoaded = TRUE;
  }

  private function buildPaths(array $categories, string $parentPath): void {
    foreach ($categories as $category) {
      $name = $category['categoryName'] ?? '';
      if ($name === '') {
        continue;
      }

      $path = $parentPath === '' ? '/' . $name : $parentPath . '/' . $name;
      $categoryId = (string) ($category['categoryId'] ?? '');
      if ($categoryId !== '') {
        $this->categoryPaths[$categoryId] = $path;
      }

      $children = $category['childrenCategories'] ?? [];
      if (!empty($children)) {
        $this->buildPaths($children, $path);
      }
    }
  }
}
