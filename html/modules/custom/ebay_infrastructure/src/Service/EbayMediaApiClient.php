<?php

declare(strict_types=1);

namespace Drupal\ebay_infrastructure\Service;

use GuzzleHttp\ClientInterface;

final class EbayMediaApiClient {

  private const BASE_URI = 'https://apim.ebay.com';
  private const CREATE_IMAGE_ENDPOINT = '/commerce/media/v1_beta/image/create_image_from_file';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EbayAccountManager $accountManager,
  ) {}


  public function createImageFromStream($handle, string $filename): string {
    if (!is_resource($handle)) {
      throw new \RuntimeException('The provided image stream is not a valid resource.');
    }

    $accessToken = $this->accountManager->getValidAccessToken();
    $multipart = [
      [
        'name' => 'image',
        'contents' => $handle,
        'filename' => $filename,
      ],
    ];

    $response = $this->httpClient->request(
      'POST',
      self::BASE_URI . self::CREATE_IMAGE_ENDPOINT,
      [
        'headers' => [
          'Authorization' => 'Bearer ' . $accessToken,
          'Accept' => 'application/json',
          'Content-Language' => 'en-AU',
          'X-EBAY-C-MARKETPLACE-ID' => 'EBAY_AU',
        ],
        'multipart' => $multipart,
        'http_errors' => false,
        'connect_timeout' => 15,
      ]
    );

    $status = $response->getStatusCode();
    if ($status < 200 || $status >= 300) {
      throw new \RuntimeException('eBay Media API error: ' . (string) $response->getBody());
    }

    $payload = json_decode((string) $response->getBody(), true);
    if (!is_array($payload) || empty($payload['imageUrl'])) {
      throw new \RuntimeException('eBay Media API returned an unexpected payload.');
    }

    return (string) $payload['imageUrl'];
  }

}
