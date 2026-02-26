<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use GuzzleHttp\ClientInterface;

final class SellApiClient {

  private ?string $categoryTreeId = null;

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EbayAccountManager $accountManager,
  ) {}

  public function getInventoryItem(string $sku): array {

    return $this->request(
      'GET',
      '/sell/inventory/v1/inventory_item/' . $this->encodeSku($sku)
    );
  }

  public function updateInventoryQuantity(string $sku, int $quantity): array {

    $current = $this->request(
      'GET',
      '/sell/inventory/v1/inventory_item/' . $this->encodeSku($sku)
    );

    $merged = array_replace_recursive(
      $current,
      [
        'availability' => [
          'shipToLocationAvailability' => [
            'quantity' => $quantity,
          ],
        ],
      ]
    );

    return $this->request(
      'PUT',
      '/sell/inventory/v1/inventory_item/' . $this->encodeSku($sku),
      $merged
    );
  }

  public function updateInventoryImages(string $sku, array $imageUrls): array {

    $current = $this->request(
      'GET',
      '/sell/inventory/v1/inventory_item/' . $this->encodeSku($sku)
    );

    $merged = array_replace_recursive(
      $current,
      [
        'product' => [
          'imageUrls' => $imageUrls,
        ],
      ]
    );

    return $this->request(
      'PUT',
      '/sell/inventory/v1/inventory_item/' . $this->encodeSku($sku),
      $merged
    );
  }

  public function getOffer(string $offerId): array {
    return $this->request(
      'GET',
      '/sell/inventory/v1/offer/' . $offerId
    );
  }

  public function createOffer(array $payload): array {

    return $this->request(
      'POST',
      '/sell/inventory/v1/offer',
      $payload
    );
  }

  public function publishOffer(string $offerId): array {
    return $this->request(
      'POST',
      '/sell/inventory/v1/offer/' . $offerId . '/publish'
    );
  }

  public function updateOffer(string $offerId, array $payload): array {

    $current = $this->getOffer($offerId);

    $merged = array_replace_recursive($current, $payload);

    return $this->request(
      'PUT',
      '/sell/inventory/v1/offer/' . $offerId,
      $merged
    );
  }

  public function listOffers(
    int $limit = 25,
    int $offset = 0
  ): array {

    $inventory = $this->listInventoryItems($limit, $offset);

    if (empty($inventory['inventoryItems'])) {
      return [];
    }

    $offers = [];

    foreach ($inventory['inventoryItems'] as $item) {

      if (empty($item['sku'])) {
        continue;
      }

      try {

        $skuOffers = $this->requestWithQuery(
          'GET',
          '/sell/inventory/v1/offer',
          [
            'sku' => $item['sku'],
          ]
        );

      } catch (\RuntimeException $e) {

        // If offer does not exist for this SKU,
        // that is a normal state in our domain.
        if (str_contains($e->getMessage(), '"errorId":25713')) {
          continue;
        }

        // Any other error is real and should bubble.
        throw $e;
      }

      if (!empty($skuOffers['offers'])) {
        foreach ($skuOffers['offers'] as $offer) {
          $offers[] = $offer;
        }
      }
    }

    return $offers;
  }

  public function listInventoryItems(
    int $limit = 25,
    int $offset = 0
  ): array {

    return $this->requestWithQuery(
      'GET',
      '/sell/inventory/v1/inventory_item',
      [
        'limit' => $limit,
        'offset' => $offset,
      ]
    );
  }

  public function replaceInventoryItem(string $sku, array $payload): array {

    return $this->request(
      'PUT',
      '/sell/inventory/v1/inventory_item/' . $this->encodeSku($sku),
      $payload
    );
  }

  public function deleteInventoryItem(string $sku): array {

    return $this->request(
      'DELETE',
      '/sell/inventory/v1/inventory_item/' . $this->encodeSku($sku)
    );
  }

  public function deleteOffer(string $offerId): array {

    return $this->request(
      'DELETE',
      '/sell/inventory/v1/offer/' . $offerId
    );
  }

  public function listOffersBySku(string $sku): array {
    return $this->requestWithQuery(
      'GET',
      '/sell/inventory/v1/offer',
      ['sku' => $sku]
    );
  }

  public function listLocations(): array {
    return $this->request('GET', '/sell/inventory/v1/location');
  }

  public function getLocation(string $locationKey): array {
    return $this->request('GET', '/sell/inventory/v1/location/' . $locationKey);
  }

  public function locationExists(string $locationKey): bool {
    try {
      $this->getLocation($locationKey);
      return true;
    }
    catch (\RuntimeException $e) {
      return false;
    }
  }

  public function createLocation(string $merchantLocationKey, array $payload): array {
    return $this->request(
      'POST',
      '/sell/inventory/v1/location/' . $merchantLocationKey,
      $payload
    );
  }

  private function request(string $method, string $path, array $json = []): array {

    $accessToken = $this->accountManager->getValidAccessToken();

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'Content-Language' => 'en-AU',
        'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_AU',
      ],
      'http_errors' => false,
    ];

    if (in_array($method, ['POST', 'PUT'], true)) {
      $options['json'] = $json;
    }

    $response = $this->httpClient->request(
      $method,
      'https://api.ebay.com' . $path,
      $options
    );

    $body = (string) $response->getBody();
    $data = json_decode($body, true);

    if ($response->getStatusCode() >= 400) {
      throw new \RuntimeException('eBay Sell API error: ' . $body);
    }

    return is_array($data) ? $data : [];
  }

  private function requestWithQuery(
    string $method,
    string $path,
    array $query
  ): array {

    $accessToken = $this->accountManager->getValidAccessToken();

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'Content-Language' => 'en-AU',
        'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_AU',
      ],
      'query' => $query,
      'http_errors' => false,
    ];

    $response = $this->httpClient->request(
      $method,
      'https://api.ebay.com' . $path,
      $options
    );

    $body = (string) $response->getBody();
    $data = json_decode($body, true);

    if ($response->getStatusCode() >= 400) {
      throw new \RuntimeException('eBay Sell API error: ' . $body);
    }

    return is_array($data) ? $data : [];
  }

  public function getPaymentPolicies(): array {

    return $this->requestWithQuery(
      'GET',
      '/sell/account/v1/payment_policy',
      [
        'marketplace_id' => 'EBAY_AU',
      ]
    );
  }

  public function getFulfillmentPolicies(): array {

    return $this->requestWithQuery(
      'GET',
      '/sell/account/v1/fulfillment_policy',
      [
        'marketplace_id' => 'EBAY_AU',
      ]
    );
  }

  public function getReturnPolicies(): array {

    return $this->requestWithQuery(
      'GET',
      '/sell/account/v1/return_policy',
      [
        'marketplace_id' => 'EBAY_AU',
      ]
    );
  }

  public function getStore(): array {
    return $this->request('GET', '/sell/stores/v1/store');
  }

  public function listStoreCategories(int $limit = 25, int $offset = 0): array {
    return $this->requestWithQuery(
      'GET',
      '/sell/stores/v1/store/categories',
      [
        'limit' => $limit,
        'offset' => $offset,
      ]
    );
  }

  public function suggestCategory(string $query): array {

    $treeId = $this->resolveCategoryTreeId();

    return $this->requestWithQuery(
      'GET',
      '/commerce/taxonomy/v1/category_tree/' . $treeId . '/get_category_suggestions',
      [
        'q' => $query,
      ]
    );
  }

  public function getDefaultCategoryTreeId(string $marketplaceId): array {

    return $this->requestWithQuery(
      'GET',
      '/commerce/taxonomy/v1/get_default_category_tree_id',
      [
        'marketplace_id' => $marketplaceId,
      ]
    );
  }

  public function getItemAspects(string $categoryId): array {

    $treeId = $this->resolveCategoryTreeId();

    return $this->requestWithQuery(
      'GET',
      '/commerce/taxonomy/v1/category_tree/' . $treeId . '/get_item_aspects_for_category',
      [
        'category_id' => $categoryId,
      ]
    );
  }

  public function getCategorySubtree(string $categoryId): array {

    $treeId = $this->resolveCategoryTreeId();

    return $this->requestWithQuery(
      'GET',
      '/commerce/taxonomy/v1/category_tree/' . $treeId . '/get_category_subtree',
      [
        'category_id' => $categoryId,
      ]
    );
  }

  private function resolveCategoryTreeId(string $marketplaceId = 'EBAY_AU'): string {

    if ($this->categoryTreeId !== null) {
      return $this->categoryTreeId;
    }

    $data = $this->getDefaultCategoryTreeId($marketplaceId);

    $this->categoryTreeId = $data['categoryTreeId'];

    return $this->categoryTreeId;
  }

  private function encodeSku(string $sku): string {
    return rawurlencode($sku);
  }

}
