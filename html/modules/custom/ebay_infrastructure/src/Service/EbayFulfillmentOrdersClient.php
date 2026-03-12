<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use Drupal\ebay_connector\Entity\EbayAccount;
use GuzzleHttp\ClientInterface;

/**
 * Focused eBay Fulfillment API client for order retrieval concerns only.
 */
final class EbayFulfillmentOrdersClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EbayAccountManager $accountManager,
  ) {}

  public function listOrders(
    int $limit = 50,
    int $offset = 0,
    ?int $createdSinceTimestamp = NULL,
    ?EbayAccount $account = NULL,
  ): array {
    $query = [
      'limit' => $limit,
      'offset' => $offset,
    ];

    if ($createdSinceTimestamp !== NULL && $createdSinceTimestamp > 0) {
      $query['filter'] = $this->buildOrderCreatedDateFilter($createdSinceTimestamp);
    }

    return $this->requestWithQuery(
      'GET',
      '/sell/fulfillment/v1/order',
      $query,
      $account,
    );
  }

  /**
   * @param array<string, mixed> $query
   */
  private function requestWithQuery(
    string $method,
    string $path,
    array $query,
    ?EbayAccount $account = NULL,
  ): array {
    $accessToken = $account instanceof EbayAccount
      ? $this->accountManager->getValidAccessTokenForAccount($account)
      : $this->accountManager->getValidAccessToken();

    $options = [
      'headers' => [
        'Authorization' => 'Bearer ' . $accessToken,
        'Content-Type' => 'application/json',
        'Content-Language' => 'en-AU',
        'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_AU',
      ],
      'query' => $query,
      'http_errors' => FALSE,
    ];

    $response = $this->httpClient->request(
      $method,
      'https://api.ebay.com' . $path,
      $options,
    );

    $body = (string) $response->getBody();
    $data = json_decode($body, TRUE);

    if ($response->getStatusCode() >= 400) {
      throw new \RuntimeException('eBay Fulfillment API error: ' . $body);
    }

    return is_array($data) ? $data : [];
  }

  private function buildOrderCreatedDateFilter(int $createdSinceTimestamp): string {
    $createdSinceIso = gmdate('Y-m-d\\TH:i:s\\Z', $createdSinceTimestamp);
    return 'creationdate:[' . $createdSinceIso . '..]';
  }

}
