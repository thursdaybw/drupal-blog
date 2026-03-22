<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\EbaySkuRemovalService;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use Drupal\ebay_infrastructure\Service\StoreService;
use Drupal\ebay_connector\Service\ConditionMapper;
use Drupal\ebay_infrastructure\Exception\EbayInventoryItemMissingException;

final class EbayMarketplacePublisher implements MarketplacePublisherInterface {

  private const DEFAULT_CATEGORY_ID = '261186';
  private const DEFAULT_MERCHANT_LOCATION_KEY = '2478';
  private const DEFAULT_PAYMENT_POLICY_ID = '240514406026';
  private const DEFAULT_FULFILLMENT_POLICY_ID = '244080662026';
  private const DEFAULT_RETURN_POLICY_ID = '240513136026';
  private const BARGAIN_BIN_FULFILLMENT_POLICY_ID = '244519897026';
  private const BARGAIN_BIN_STORE_CATEGORY_ID = '85529649013';
  private const MAX_BOOK_TITLE_ASPECT_LENGTH = 65;

  private LoggerChannelInterface $logger;
  private ?EbaySkuRemovalService $skuRemovalService;

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly ConditionMapper $conditionMapper,
    private readonly ListingImageUploaderInterface $imageUploader,
    private readonly StoreService $storeService,
    private readonly ?EbayAccountManager $accountManager,
    private readonly ?EbayInventoryMirrorSyncService $inventoryMirrorSyncService,
    private readonly ?EbayOfferMirrorSyncService $offerMirrorSyncService,
    LoggerChannelFactoryInterface|EbaySkuRemovalService $loggerFactoryOrSkuRemovalService,
    ?LoggerChannelFactoryInterface $loggerFactory = null,
  ) {
    if ($loggerFactoryOrSkuRemovalService instanceof LoggerChannelFactoryInterface) {
      $this->skuRemovalService = null;
      $this->logger = $loggerFactoryOrSkuRemovalService->get('ebay_connector');
      return;
    }

    $this->skuRemovalService = $loggerFactoryOrSkuRemovalService;
    if (!$loggerFactory instanceof LoggerChannelFactoryInterface) {
      throw new \InvalidArgumentException('Logger factory is required when SKU removal service is injected.');
    }
    $this->logger = $loggerFactory->get('ebay_connector');
  }

  public function publish(ListingPublishRequest $request): MarketplacePublishResult {
    $request = $this->prepareRequest($request);

    $this->sellApiClient->createOrReplaceInventoryItem(
      $request->getSku(),
      $this->buildInventoryPayload($request)
    );

    $this->ensureMerchantLocation();

    $offerPayload = $this->buildOfferPayload($request, 'FIXED_PRICE', FALSE);

    $offerId = $this->resolveExistingOfferId($request->getSku());
    if ($offerId === null) {
      try {
        $offer = $this->sellApiClient->createOffer($offerPayload);
      }
      catch (\RuntimeException $exception) {
        $payload = $this->formatPayload($offerPayload);
        $this->logger->error('Offer creation failed: @message | payload: @payload', [
          '@message' => $exception->getMessage(),
          '@payload' => $payload,
        ]);
        throw $exception;
      }

      $offerId = (string) $offer['offerId'];
    }

    $this->sellApiClient->updateOffer(
      $offerId,
      $this->buildOfferPayload($request, 'FIXED_PRICE', TRUE)
    );

    $publish = $this->sellApiClient->publishOffer($offerId);
    $this->refreshMirrorForSku($request->getSku());

    return new MarketplacePublishResult(
      true,
      'Published',
      $publish['listingId'] ?? null,
      (string) $offerId,
      'FIXED_PRICE'
    );
  }

  public function updatePublication(
    string $marketplacePublicationId,
    ListingPublishRequest $request,
    ?string $publicationType = null,
  ): MarketplacePublishResult {
    $request = $this->prepareRequest($request);

    $this->sellApiClient->createOrReplaceInventoryItem(
      $request->getSku(),
      $this->buildInventoryPayload($request)
    );

    $this->ensureMerchantLocation();

    $updatedOffer = $this->sellApiClient->updateOffer(
      $marketplacePublicationId,
      $this->buildOfferPayload($request, $publicationType ?: 'FIXED_PRICE', TRUE)
    );
    $listingId = isset($updatedOffer['listing']['listingId']) ? (string) $updatedOffer['listing']['listingId'] : null;
    $this->refreshMirrorForSku($request->getSku());

    return new MarketplacePublishResult(
      true,
      'Updated',
      $listingId,
      $marketplacePublicationId,
      $publicationType ?: 'FIXED_PRICE'
    );
  }

  public function deleteSku(string $sku): void {
    if (!$this->skuRemovalService instanceof EbaySkuRemovalService) {
      throw new \RuntimeException('eBay SKU removal service is not available.');
    }

    try {
      $this->skuRemovalService->removeSku($sku);
    }
    catch (EbayInventoryItemMissingException) {
    }
  }

  public function getMarketplaceKey(): string {
    return 'ebay';
  }

  private function prepareRequest(ListingPublishRequest $request): ListingPublishRequest {
    $imageUrls = $this->imageUploader->upload($request->getImageSources())->getRemoteUrls();
    if ([] === $imageUrls) {
      $imageUrls = $request->getImageUrls();
    }

    return $request->withImageUrls($imageUrls);
  }

  private function buildInventoryPayload(ListingPublishRequest $request): array {
    return [
      'product' => [
        'title' => $request->getTitle(),
        'description' => $request->getDescription(),
        'aspects' => $this->buildAspects($request),
        'imageUrls' => $request->getImageUrls(),
      ],
      'condition' => $this->conditionMapper->toEbayCondition($request->getCondition()),
      'conditionDescription' => $request->getConditionDescription(),
      'availability' => [
        'shipToLocationAvailability' => [
          'quantity' => $request->getQuantity(),
        ],
      ],
    ];
  }

  private function buildOfferPayload(
    ListingPublishRequest $request,
    string $format,
    bool $includePolicies,
  ): array {
    $payload = [
      'sku' => $request->getSku(),
      'marketplaceId' => 'EBAY_AU',
      'format' => $format,
      'availableQuantity' => $request->getQuantity(),
      'listingDescription' => $request->getDescription(),
      'pricingSummary' => [
        'price' => [
          'value' => $request->getPrice(),
          'currency' => 'AUD',
        ],
      ],
      'categoryId' => $this->resolveCategoryId($request),
      'merchantLocationKey' => self::DEFAULT_MERCHANT_LOCATION_KEY,
    ];

    $storeCategoryNames = $this->resolveStoreCategoryNames($request);
    if ($storeCategoryNames !== []) {
      $payload['storeCategoryNames'] = $storeCategoryNames;
    }

    if ($includePolicies) {
      $payload['listingPolicies'] = [
        'paymentPolicyId' => self::DEFAULT_PAYMENT_POLICY_ID,
        'fulfillmentPolicyId' => $this->resolveFulfillmentPolicyId($request),
        'returnPolicyId' => self::DEFAULT_RETURN_POLICY_ID,
      ];
    }

    return $payload;
  }

  private function buildAspects(ListingPublishRequest $request): array {
    $attributes = $request->getAttributes();
    $aspects = [];

    if (($attributes['product_type'] ?? null) === 'book') {
      $bookTitle = (string) ($attributes['book_title'] ?? $request->getTitle());
      $aspects['Book Title'] = [$this->truncateBookTitleAspect($bookTitle)];
      $authorValues = $this->deriveAuthorAspectValues((string) ($attributes['author'] ?? $request->getAuthor()));
      if ($authorValues === []) {
        $authorValues = ['Various Authors'];
      }
      $aspects['Author'] = $authorValues;
      $aspects['Language'] = [$attributes['language'] ?? 'English'];
      $this->addAspectIfValue($aspects, 'ISBN', $attributes['isbn'] ?? '');
      $this->addAspectIfValue($aspects, 'Book Series', $attributes['series'] ?? '');
      $this->addAspectIfValue($aspects, 'Publisher', $attributes['publisher'] ?? '');
      $this->addAspectIfValue($aspects, 'Format', $attributes['format'] ?? '');
      $this->addAspectIfValue($aspects, 'Genre', $attributes['genre'] ?? '');
      $this->addAspectIfValue($aspects, 'Topic', $attributes['topic'] ?? '');
      $this->addAspectIfValue($aspects, 'Publication Year', $attributes['publication_year'] ?? '');
      $this->addAspectIfValue($aspects, 'Country of Origin', $attributes['country_of_origin'] ?? '');
    }

    // This comment is a smell indicator: if more product types arrive, this
    // conditional will grow. When that happens we must introduce a dedicated
    // boundary rather than extending this if chain.

    return $aspects;
  }

  private function addAspectIfValue(array &$aspects, string $name, ?string $value): void {
    if ($value === null) {
      return;
    }

    $normalized = trim($value);
    if ($normalized === '') {
      return;
    }

    $aspects[$name] = [$normalized];
  }

  private function resolveExistingOfferId(string $sku): ?string {
    $offers = $this->sellApiClient->listOffersBySku($sku);
    if (empty($offers['offers']) || !is_array($offers['offers'])) {
      return null;
    }

    foreach ($offers['offers'] as $offer) {
      $offerId = isset($offer['offerId']) ? trim((string) $offer['offerId']) : '';
      if ($offerId !== '') {
        return $offerId;
      }
    }

    return null;
  }

  private function refreshMirrorForSku(string $sku): void {
    if ($this->accountManager === null || $this->inventoryMirrorSyncService === null || $this->offerMirrorSyncService === null) {
      return;
    }

    $account = $this->accountManager->loadPrimaryAccount();
    $this->inventoryMirrorSyncService->syncSku($account, $sku);
    $this->offerMirrorSyncService->syncSku($account, $sku);
  }
  private function truncateBookTitleAspect(string $value): string {
    $normalized = preg_replace('/\s+/', ' ', trim($value));
    return (string) mb_substr((string) $normalized, 0, self::MAX_BOOK_TITLE_ASPECT_LENGTH);
  }

  /**
   * @return array<int,string>
   */
  private function deriveAuthorAspectValues(string $rawAuthor): array {
    $normalized = trim(preg_replace('/\s+/', ' ', $rawAuthor) ?? '');
    if ($normalized === '') {
      return [];
    }

    $lower = strtolower($normalized);
    if (in_array($lower, ['unknown', 'unbranded', 'n/a', 'na'], TRUE)) {
      return ['Various Authors'];
    }

    $commaSplit = preg_split('/\s*,\s*/', $normalized);
    if (is_array($commaSplit) && count($commaSplit) > 1) {
      return $this->normalizeAuthorValues($commaSplit);
    }

    $ampersandSplit = preg_split('/\s*&\s*/', $normalized);
    if (is_array($ampersandSplit) && count($ampersandSplit) > 1 && $this->allAuthorPartsLookComplete($ampersandSplit)) {
      return $this->normalizeAuthorValues($ampersandSplit);
    }

    $andSplit = preg_split('/\s+and\s+/i', $normalized);
    if (is_array($andSplit) && count($andSplit) > 1 && $this->allAuthorPartsLookComplete($andSplit)) {
      return $this->normalizeAuthorValues($andSplit);
    }

    return [$normalized];
  }

  /**
   * @param array<int,string> $parts
   */
  private function allAuthorPartsLookComplete(array $parts): bool {
    foreach ($parts as $part) {
      $tokens = preg_split('/\s+/', trim($part));
      if (!is_array($tokens)) {
        return FALSE;
      }

      $nonEmptyTokens = array_values(array_filter($tokens, static fn(string $token): bool => $token !== ''));
      if (count($nonEmptyTokens) < 2) {
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * @param array<int,string> $parts
   * @return array<int,string>
   */
  private function normalizeAuthorValues(array $parts): array {
    $values = [];
    $placeholderValues = ['unknown', 'unbranded', 'n/a', 'na', 'various authors', 'various'];
    foreach ($parts as $part) {
      $value = trim($part);
      if ($value === '') {
        continue;
      }

      $lower = strtolower($value);
      if (in_array($lower, $placeholderValues, TRUE)) {
        continue;
      }

      $values[] = $value;
    }

    if ($values === []) {
      return ['Various Authors'];
    }

    return array_values(array_unique($values));
  }

  private function ensureMerchantLocation(): void {
    if ($this->sellApiClient->locationExists(self::DEFAULT_MERCHANT_LOCATION_KEY)) {
      return;
    }

    $payload = $this->buildMerchantLocationPayload();
    unset($payload['merchantLocationKey']);
    $this->sellApiClient->createLocation(self::DEFAULT_MERCHANT_LOCATION_KEY, $payload);
  }

  private function buildMerchantLocationPayload(): array {
    return [
      'locationKey' => self::DEFAULT_MERCHANT_LOCATION_KEY,
      'name' => 'Bevan\'s Bench Ballina Fulfilment',
      'locationInstructions' => 'Ballina NSW 2478 fulfilment center for Bevan\'s Bench.',
      'locationTypes' => ['WAREHOUSE'],
      'merchantLocationStatus' => 'ENABLED',
      'location' => [
        'address' => [
          'addressLine1' => 'Ballina NSW 2478',
          'city' => 'Ballina',
          'stateOrProvince' => 'NSW',
          'postalCode' => '2478',
          'country' => 'AU',
        ],
      ],
    ];
  }

  private function resolveFulfillmentPolicyId(ListingPublishRequest $request): string {
    $attributes = $request->getAttributes();
    if (!empty($attributes['bargain_bin'])) {
      return self::BARGAIN_BIN_FULFILLMENT_POLICY_ID;
    }

    return self::DEFAULT_FULFILLMENT_POLICY_ID;
  }

  private function resolveCategoryId(ListingPublishRequest $request): string {
    return self::DEFAULT_CATEGORY_ID;
  }

  private function resolveStoreCategoryNames(ListingPublishRequest $request): array {
    $attributes = $request->getAttributes();
    if (empty($attributes['bargain_bin'])) {
      return [];
    }

    $path = $this->storeService->getStoreCategoryPath(self::BARGAIN_BIN_STORE_CATEGORY_ID);
    if ($path === null) {
      return [];
    }

    return [$path];
  }
}
