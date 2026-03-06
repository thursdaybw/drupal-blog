<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Service;

use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use GuzzleHttp\ClientInterface;
use RuntimeException;
use SimpleXMLElement;

final class EbayTradingLegacyClient {

  private const ENDPOINT = 'https://api.ebay.com/ws/api.dll';
  private const XML_NAMESPACE = 'urn:ebay:apis:eBLBaseComponents';
  private const SITE_ID_AU = '15';
  private const COMPATIBILITY_LEVEL = '1231';

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly EbayAccountManager $accountManager,
  ) {}

  public function listActiveListingsForAccount(
    EbayAccount $account,
    int $pageNumber = 1,
    int $entriesPerPage = 200,
  ): array {
    $requestBody = $this->buildGetMyeBaySellingRequest($pageNumber, $entriesPerPage);

    $responseBody = $this->requestXml(
      $account,
      'GetMyeBaySelling',
      $requestBody,
    );

    return $this->parseActiveListingsResponse($responseBody);
  }

  public function reviseFixedPriceItemSkuForAccount(
    EbayAccount $account,
    string $itemId,
    string $sku,
  ): void {
    $requestBody = $this->buildReviseFixedPriceItemSkuRequest($itemId, $sku);
    $responseBody = $this->requestXml(
      $account,
      'ReviseFixedPriceItem',
      $requestBody,
    );

    $this->assertSuccessfulAckResponse($responseBody);
  }

  private function buildGetMyeBaySellingRequest(int $pageNumber, int $entriesPerPage): string {
    return '<?xml version="1.0" encoding="utf-8"?>'
      . '<GetMyeBaySellingRequest xmlns="' . self::XML_NAMESPACE . '">'
      . '<ActiveList>'
      . '<Include>true</Include>'
      . '<Pagination>'
      . '<EntriesPerPage>' . $entriesPerPage . '</EntriesPerPage>'
      . '<PageNumber>' . $pageNumber . '</PageNumber>'
      . '</Pagination>'
      . '</ActiveList>'
      . '</GetMyeBaySellingRequest>';
  }

  private function buildReviseFixedPriceItemSkuRequest(string $itemId, string $sku): string {
    return '<?xml version="1.0" encoding="utf-8"?>'
      . '<ReviseFixedPriceItemRequest xmlns="' . self::XML_NAMESPACE . '">'
      . '<Item>'
      . '<ItemID>' . htmlspecialchars($itemId, ENT_XML1) . '</ItemID>'
      . '<SKU>' . htmlspecialchars($sku, ENT_XML1) . '</SKU>'
      . '</Item>'
      . '</ReviseFixedPriceItemRequest>';
  }

  private function requestXml(EbayAccount $account, string $callName, string $body): string {
    $accessToken = $this->accountManager->getValidAccessTokenForAccount($account);

    $response = $this->httpClient->request('POST', self::ENDPOINT, [
      'headers' => [
        'Content-Type' => 'text/xml',
        'X-EBAY-API-CALL-NAME' => $callName,
        'X-EBAY-API-SITEID' => self::SITE_ID_AU,
        'X-EBAY-API-COMPATIBILITY-LEVEL' => self::COMPATIBILITY_LEVEL,
        'X-EBAY-API-IAF-TOKEN' => $accessToken,
      ],
      'body' => $body,
      'http_errors' => FALSE,
    ]);

    $responseBody = (string) $response->getBody();
    if ($response->getStatusCode() >= 400) {
      throw new RuntimeException('eBay Trading API error: ' . $responseBody);
    }

    return $responseBody;
  }

  private function parseActiveListingsResponse(string $responseBody): array {
    $xml = @simplexml_load_string($responseBody);
    if (!$xml instanceof SimpleXMLElement) {
      throw new RuntimeException('Unexpected XML response from eBay Trading API.');
    }

    $namespaced = $xml->children(self::XML_NAMESPACE);
    $this->assertSuccessOrWarningAck($namespaced);

    $activeList = $namespaced->ActiveList;
    $totalPages = (int) ($activeList->PaginationResult->TotalNumberOfPages ?? 1);

    $items = [];
    foreach ($activeList->ItemArray->Item ?? [] as $item) {
      $items[] = $this->parseListingItem($item);
    }

    return [
      'total_pages' => max(1, $totalPages),
      'items' => $items,
    ];
  }

  private function parseListingItem(SimpleXMLElement $item): array {
    $namespaced = $item->children(self::XML_NAMESPACE);
    $listingStatus = $this->normalizeNullableString((string) $namespaced->SellingStatus->ListingStatus);

    return [
      'ebay_listing_id' => trim((string) $namespaced->ItemID),
      'sku' => $this->normalizeNullableString((string) $namespaced->SKU),
      'title' => $this->normalizeNullableString((string) $namespaced->Title),
      'ebay_listing_started_at' => $this->parseDateTime((string) $namespaced->ListingDetails->StartTime),
      // GetMyeBaySelling ActiveList rows are active even when ListingStatus is
      // omitted in the item payload.
      'listing_status' => strtoupper((string) ($listingStatus ?? 'ACTIVE')),
      'primary_category_id' => $this->normalizeNullableString((string) $namespaced->PrimaryCategory->CategoryID),
      'raw_xml' => $item->asXML() ?: NULL,
    ];
  }

  private function parseDateTime(string $value): ?int {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return NULL;
    }

    $timestamp = strtotime($trimmed);
    return $timestamp === FALSE ? NULL : $timestamp;
  }

  private function normalizeNullableString(string $value): ?string {
    $trimmed = trim($value);
    return $trimmed === '' ? NULL : $trimmed;
  }

  private function assertSuccessfulAckResponse(string $responseBody): void {
    $xml = @simplexml_load_string($responseBody);
    if (!$xml instanceof SimpleXMLElement) {
      throw new RuntimeException('Unexpected XML response from eBay Trading API.');
    }

    $namespaced = $xml->children(self::XML_NAMESPACE);
    $this->assertSuccessOrWarningAck($namespaced);
  }

  private function assertSuccessOrWarningAck(SimpleXMLElement $namespaced): void {
    $ack = trim((string) $namespaced->Ack);
    if ($ack === 'Success' || $ack === 'Warning') {
      return;
    }

    $errorMessage = trim((string) $namespaced->Errors->LongMessage);
    if ($errorMessage === '') {
      $errorMessage = 'Unknown Trading API error';
    }

    throw new RuntimeException('eBay Trading API error: ' . $errorMessage);
  }

}
