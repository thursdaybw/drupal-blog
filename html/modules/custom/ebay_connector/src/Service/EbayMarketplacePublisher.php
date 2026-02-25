<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Service;

use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use Drupal\ebay_connector\Service\ConditionMapper;

final class EbayMarketplacePublisher implements MarketplacePublisherInterface {

  private const DEFAULT_CATEGORY_ID = '261186';
  private const DEFAULT_MERCHANT_LOCATION_KEY = '2478';
  private const DEFAULT_PAYMENT_POLICY_ID = '240514406026';
  private const DEFAULT_FULFILLMENT_POLICY_ID = '244080662026';
  private const DEFAULT_RETURN_POLICY_ID = '240513136026';
  private const BARGAIN_BIN_FULFILLMENT_POLICY_ID = '244519897026';

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly ConditionMapper $conditionMapper,
    private readonly ListingImageUploaderInterface $imageUploader,
  ) {}

  public function publish(ListingPublishRequest $request): MarketplacePublishResult {
    $imageUrls = $this->imageUploader->upload($request->getImageSources())->getRemoteUrls();
    if ([] === $imageUrls) {
      $imageUrls = $request->getImageUrls();
    }

    $request = $request->withImageUrls($imageUrls);

    $aspects = $this->buildAspects($request);

    $this->sellApiClient->replaceInventoryItem(
      $request->getSku(),
      [
        'product' => [
          'title' => $request->getTitle(),
          'description' => $request->getDescription(),
          'aspects' => $aspects,
          'imageUrls' => $request->getImageUrls(),
        ],
        'condition' => $this->conditionMapper->toEbayCondition($request->getCondition()),
        'conditionDescription' => 'Used book in good condition. Light shelf wear.',
        'availability' => [
          'shipToLocationAvailability' => [
            'quantity' => $request->getQuantity(),
          ],
        ],
      ]
    );

    $this->ensureMerchantLocation();

    $offer = $this->sellApiClient->createOffer([
      'sku' => $request->getSku(),
      'marketplaceId' => 'EBAY_AU',
      'format' => 'FIXED_PRICE',
      'availableQuantity' => $request->getQuantity(),
      'pricingSummary' => [
        'price' => [
          'value' => $request->getPrice(),
          'currency' => 'AUD',
        ],
      ],
      'categoryId' => self::DEFAULT_CATEGORY_ID,
      'merchantLocationKey' => self::DEFAULT_MERCHANT_LOCATION_KEY,
    ]);

    $offerId = $offer['offerId'];
    $fulfillmentPolicyId = $this->resolveFulfillmentPolicyId($request);

    $this->sellApiClient->updateOffer($offerId, [
      'listingPolicies' => [
        'paymentPolicyId' => self::DEFAULT_PAYMENT_POLICY_ID,
        'fulfillmentPolicyId' => $fulfillmentPolicyId,
        'returnPolicyId' => self::DEFAULT_RETURN_POLICY_ID,
      ],
    ]);

    $publish = $this->sellApiClient->publishOffer($offerId);

    return new MarketplacePublishResult(true, 'Published', $publish['listingId']);
  }

  public function deleteSku(string $sku): void {
    $offers = $this->sellApiClient->listOffersBySku($sku);
    foreach ($offers as $offer) {
      if (empty($offer['offerId'])) {
        continue;
      }

      try {
        $this->sellApiClient->deleteOffer((string) $offer['offerId']);
      }
      catch (\RuntimeException $e) {
        // Swallow not found errors so retries clean up whatever exists.
        if (!str_contains($e->getMessage(), '404')) {
          throw $e;
        }
      }
    }

    try {
      $this->sellApiClient->deleteInventoryItem($sku);
    }
    catch (\RuntimeException $e) {
      if (!str_contains($e->getMessage(), '404')) {
        throw $e;
      }
    }
  }

  public function getMarketplaceKey(): string {
    return 'ebay';
  }

  private function buildAspects(ListingPublishRequest $request): array {
    $attributes = $request->getAttributes();
    $aspects = [];

    if (($attributes['product_type'] ?? null) === 'book') {
      $aspects['Book Title'] = [$request->getTitle()];
      $aspects['Author'] = [$attributes['author'] ?? $request->getAuthor()];
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

}
