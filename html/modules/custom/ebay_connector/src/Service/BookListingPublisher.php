<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Service;

use Drupal\ebay_connector\Model\BookListingData;
use Drupal\ebay_connector\Service\ConditionMapper;
use Drupal\ebay_infrastructure\Service\SellApiClient;

final class BookListingPublisher {

  private const BOOKS_CATEGORY_ID = '261186'; // books
  private const DEFAULT_CATEGORY_ID = self::BOOKS_CATEGORY_ID;
  private const DEFAULT_MERCHANT_LOCATION_KEY = 'PRIMARY-AU';

  private const DEFAULT_PAYMENT_POLICY_ID = '240514406026';
  private const DEFAULT_FULFILLMENT_POLICY_ID = '244519897026';
  private const DEFAULT_RETURN_POLICY_ID = '240513136026';


  public function __construct( private readonly SellApiClient $sellApiClient, private readonly ConditionMapper $conditionMapper,) {
  }

  public function publish(BookListingData $data): string {

    // 1. Create or replace inventory item

    $this->sellApiClient->replaceInventoryItem(
      $data->sku,
      [
        'product' => [
          'title' => $data->title,
          'description' => $data->description,
          'aspects' => [
            'Book Title' => [$data->title],
            'Author' => [$data->author],
            'Language' => ['English'],
          ],
          'imageUrls' => [$data->imageUrl],
        ],
        'condition' => $this->conditionMapper->toEbayCondition($data->conditionId),
        'conditionDescription' => $this->buildConditionDescription($data),
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
        'categoryId' => self::DEFAULT_CATEGORY_ID,
        'merchantLocationKey' => self::DEFAULT_MERCHANT_LOCATION_KEY,
      ]
    );

    $offerId = $offer['offerId'];

    // 3. Attach policies

    $this->sellApiClient->updateOffer(
      $offerId,
      [
        'listingPolicies' => [
          'paymentPolicyId' => self::DEFAULT_PAYMENT_POLICY_ID,
          'fulfillmentPolicyId' => self::DEFAULT_FULFILLMENT_POLICY_ID,
          'returnPolicyId' => self::DEFAULT_RETURN_POLICY_ID,
        ],
      ]
    );

    // 4. Publish

    $publish = $this->sellApiClient->publishOffer($offerId);

    return $publish['listingId'];
  }

  private function buildConditionDescription(BookListingData $data): string {

    // Prototype implementation.
    // In future this will use structured condition data.
    return 'Used book in good condition. Light shelf wear. See photos.';
  }

}
