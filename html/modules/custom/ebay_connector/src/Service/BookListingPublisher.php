<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Service;

use Drupal\ebay_connector\Model\BookListingData;
use Drupal\ebay_connector\Service\ConditionMapper;

final class BookListingPublisher {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly ConditionMapper $conditionMapper,
  ) {}

  public function publish(BookListingData $data): string {

    // 1. Create or replace inventory item

    $this->sellApiClient->replaceInventoryItem(
      $data->sku,
      [
        'product' => [
          'title' => $data->title,
          'description' => $data->description,
          'aspects' => [
            'Brand' => ['Unbranded'],
          ],
          'imageUrls' => [$data->imageUrl],
        ],
        'condition' => $this->conditionMapper->toEbayCondition($data->conditionId),
        'availability' => [
          'shipToLocationAvailability' => [
            'quantity' => $data->quantity,
          ],
        ],
      ]
    );

    // 2. Create offer

    $offer = $this->sellApiClient->createOffer(
      [
        'sku' => $data->sku,
        'marketplaceId' => 'EBAY_AU',
        'format' => 'FIXED_PRICE',
        'availableQuantity' => $data->quantity,
        'pricingSummary' => [
          'price' => [
            'value' => $data->price,
            'currency' => 'AUD',
          ],
        ],
        'categoryId' => '88433',
        'merchantLocationKey' => 'PRIMARY-AU',
      ]
    );

    $offerId = $offer['offerId'];

    // 3. Attach policies

    $this->sellApiClient->updateOffer(
      $offerId,
      [
        'listingPolicies' => [
          'paymentPolicyId' => '240514406026',
          'fulfillmentPolicyId' => '244519897026',
          'returnPolicyId' => '240513136026',
        ],
      ]
    );

    // 4. Publish

    $publish = $this->sellApiClient->publishOffer($offerId);

    return $publish['listingId'];
  }
}
