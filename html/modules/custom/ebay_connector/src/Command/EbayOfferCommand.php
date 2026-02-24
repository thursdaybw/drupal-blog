<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Command;

use Drupal\ebay_connector\Service\SellApiClient;
use Drush\Commands\DrushCommands;

final class EbayOfferCommand extends DrushCommands {

  public function __construct(
    private readonly SellApiClient $sellApiClient,
    private readonly \Drupal\ebay_connector\Service\OAuthTokenService $oauthTokenService,
  ) {
    parent::__construct();
  }



  /**
   * Fetch an offer from eBay.
   *
   * @command ebay-connector:get-offer
   */
  public function getOffer(string $offerId): void {

    try {
      $data = $this->sellApiClient->getOffer($offerId);
      $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Publish an offer.
   *
   * @command ebay-connector:publish-offer
   */
  public function publishOffer(string $offerId): void {

    try {
      $data = $this->sellApiClient->publishOffer($offerId);
      $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * List recent offers.
   *
   * @command ebay-connector:list-offers
   */
  public function listOffers(): void {

    $data = $this->sellApiClient->listOffers(10);

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Attach listing policies to an offer.
   *
   * @command ebay-connector:set-policies
   */
  public function setPolicies(
    string $offerId,
    string $paymentPolicyId,
    string $fulfillmentPolicyId,
    string $returnPolicyId
  ): void {

    $payload = [
      'listingPolicies' => [
        'paymentPolicyId' => $paymentPolicyId,
        'fulfillmentPolicyId' => $fulfillmentPolicyId,
        'returnPolicyId' => $returnPolicyId,
      ],
    ];

    $data = $this->sellApiClient->updateOffer($offerId, $payload);

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * List payment policies.
   *
   * @command ebay-connector:payment-policies
   */
  public function paymentPolicies(): void {
    $data = $this->sellApiClient->getPaymentPolicies();
    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * List fulfillment policies.
   *
   * @command ebay-connector:fulfillment-policies
   */
  public function fulfillmentPolicies(): void {
    $data = $this->sellApiClient->getFulfillmentPolicies();
    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * List return policies.
   *
   * @command ebay-connector:return-policies
   */
  public function returnPolicies(): void {
    $data = $this->sellApiClient->getReturnPolicies();
    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Revoke current refresh token.
   *
   * @command ebay-connector:revoke
   */
  public function revoke(): void {

    $storage = \Drupal::entityTypeManager()->getStorage('ebay_account');
    $account = reset($storage->loadMultiple());

    if (!$account) {
      $this->output()->writeln('No account stored.');
      return;
    }

    $refreshToken = $account->get('refresh_token')->value;

    $this->oauthTokenService->revokeToken($refreshToken);

    $this->output()->writeln('Token revoked on eBay side.');
  }

  /**
   * Repair an offer by writing required fields back.
   *
   * @command ebay-connector:repair-offer
   */
  public function repairOffer(string $offerId): void {

    $payload = [
      'sku' => 'DRUPAL-TEST-001',
      'marketplaceId' => 'EBAY_AU',
      'format' => 'FIXED_PRICE',
      'availableQuantity' => 1,
      'categoryId' => '88433',
      'merchantLocationKey' => 'PRIMARY-AU',
      'listingDescription' => 'Test listing from Drupal connector',
      'pricingSummary' => [
        'price' => [
          'value' => '9.99',
          'currency' => 'AUD',
        ],
      ],
      'listingPolicies' => [
        'paymentPolicyId' => '240514406026',
        'fulfillmentPolicyId' => '244519897026',
        'returnPolicyId' => '240513136026',
      ],
    ];

    $data = $this->sellApiClient->updateOffer($offerId, $payload);

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Repair inventory item.
   *
   * @command ebay-connector:repair-inventory
   */
  public function repairInventory(string $sku): void {

    $payload = [
      'product' => [
        'title' => 'Drupal API Test Item',
        'description' => 'This is a test inventory item created via API.',
        'aspects' => [
          'Brand' => ['Unbranded'],
        ],
        'imageUrls' => [
          'https://dev.bevansbench.com/sites/default/files/ai-listings/e84531a1-083e-4941-b1bd-7dad8e1d38ae/20260222_172824.jpg',
        ],
      ],
      'condition' => 'NEW',
      'availability' => [
        'shipToLocationAvailability' => [
          'quantity' => 1,
        ],
      ],
    ];

    $data = $this->sellApiClient->replaceInventoryItem(
      $sku,
      $payload
    );

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Inspect inventory item.
   *
   * @command ebay-connector:get-inventory
   */
  public function getInventory(string $sku): void {

    $data = $this->sellApiClient->getInventoryItem($sku);

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * List inventory items.
   *
   * @command ebay-connector:list-inventory
   */
  public function listInventory(int $limit = 25, int $offset = 0): void {

    $data = $this->sellApiClient->listInventoryItems($limit, $offset);

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Set inventory quantity.
   *
   * @command ebay-connector:set-quantity
   */
  public function setQuantity(string $sku, int $quantity): void {

    $data = $this->sellApiClient->updateInventoryQuantity($sku, $quantity);

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Set inventory images.
   *
   * @command ebay-connector:set-images
   */
  public function setImages(string $sku, string $imageUrl): void {

    $data = $this->sellApiClient->updateInventoryImages($sku, [$imageUrl]);

    $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
  }

  /**
   * Publish test book in one shot.
   *
   * @command ebay-connector:publish-test-book
   */
  public function publishTestBook(): void {

    $data = new \Drupal\ebay_connector\Model\BookListingData(
      sku: 'BOOK-TEST-' . time(),
      title: 'Drupal API Test Item',
      description: 'This is a test inventory item created via API.',
      author: 'Unbranded',
      price: '9.99',
      imageUrl: 'https://dev.bevansbench.com/sites/default/files/ai-listings/e84531a1-083e-4941-b1bd-7dad8e1d38ae/20260222_172824.jpg',
      quantity: 1,
      conditionId: 'NEW',
    );

    $publisher = \Drupal::service('drupal.ebay_connector.book_publisher');

    $listingId = $publisher->publish($data);

    $this->output()->writeln('Published listing: ' . $listingId);
  }

  /**
   * Get default category tree id for marketplace.
   *
   * @command ebay-connector:get-category-tree
   */
  public function getCategoryTree(string $marketplaceId = 'EBAY_AU'): void {

    try {
      $data = $this->sellApiClient
                   ->getDefaultCategoryTreeId($marketplaceId);

      $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Suggest category by keyword.
   *
   * @command ebay-connector:suggest-category
   */
  public function suggestCategory(string $query): void {

    try {
      $data = $this->sellApiClient->suggestCategory($query);
      $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Inspect item aspects for category.
   *
   * @command ebay-connector:get-aspects
   */
  public function getAspects(string $categoryId): void {

    try {
      $data = $this->sellApiClient->getItemAspects($categoryId);
      $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>' . $e->getMessage() . '</error>');
    }
  }

  /**
   * Get category subtree.
   *
   * @command ebay-connector:get-category-subtree
   */
  public function getCategorySubtree(string $categoryId): void {

    try {
      $data = $this->sellApiClient
                   ->getCategorySubtree($categoryId);

      $this->output()->writeln(json_encode($data, JSON_PRETTY_PRINT));
    }
    catch (\Throwable $e) {
      $this->output()->writeln('<error>' . $e->getMessage() . '</error>');
    }
  }



}
