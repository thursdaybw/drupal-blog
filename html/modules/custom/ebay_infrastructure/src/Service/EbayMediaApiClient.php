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

  public function createImageFromFile(string $filePath, string $filename): string {
    if (!is_file($filePath)) {
      throw new \RuntimeException(sprintf('Unable to read the image at %s.', $filePath));
    }

    $accessToken = $this->accountManager->getValidAccessToken();
    $handle = fopen($filePath, 'rb');
    if ($handle === false) {
      throw new \RuntimeException(sprintf('Failed to open %s for reading.', $filePath));
    }

    try {
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
    } finally {
      if (is_resource($handle)) {
        fclose($handle);
      }
    }

    $status = $response->getStatusCode();
    if ($status !== 201) {
      throw new \RuntimeException('eBay Media API error: ' . (string) $response->getBody());
    }

    $payload = json_decode((string) $response->getBody(), true);
    if (!is_array($payload) || empty($payload['imageUrl'])) {
      throw new \RuntimeException('eBay Media API returned an unexpected payload.');
    }

    return (string) $payload['imageUrl'];
  }

}
