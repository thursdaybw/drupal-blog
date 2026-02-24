<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Service;

use Drupal\listing_publishing\Contract\MarketplacePublisherInterface;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\listing_publishing\Model\MarketplacePublishResult;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use Drupal\ebay_connector\Service\ConditionMapper;

final class EbayMarketplacePublisher implements MarketplacePublisherInterface {

  private const DEFAULT_CATEGORY_ID = '261186';
  private const DEFAULT_MERCHANT_LOCATION_KEY = 'PRIMARY-AU';
  private const DEFAULT_PAYMENT_POLICY_ID = '240514406026';
  private const DEFAULT_FULFILLMENT_POLICY_ID = '244519897026';
  private const DEFAULT_RETURN_POLICY_ID = '240513136026';

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly ConditionMapper $conditionMapper,
  ) {}

  public function publish(ListingPublishRequest $request): MarketplacePublishResult {
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

    $this->sellApiClient->updateOffer($offerId, [
      'listingPolicies' => [
        'paymentPolicyId' => self::DEFAULT_PAYMENT_POLICY_ID,
        'fulfillmentPolicyId' => self::DEFAULT_FULFILLMENT_POLICY_ID,
        'returnPolicyId' => self::DEFAULT_RETURN_POLICY_ID,
      ],
    ]);

    $publish = $this->sellApiClient->publishOffer($offerId);

    return new MarketplacePublishResult(true, 'Published', $publish['listingId']);
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
    }

    // This comment is a smell indicator: if more product types arrive, this
    // conditional will grow. When that happens we must introduce a dedicated
    // boundary rather than extending this if chain.

    return $aspects;
  }

}
