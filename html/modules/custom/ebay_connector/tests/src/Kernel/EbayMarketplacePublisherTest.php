<?php

declare(strict_types=1);

namespace Drupal\Tests\ebay_connector\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_connector\Service\ConditionMapper;
use Drupal\ebay_connector\Service\EbayMarketplacePublisher;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use Drupal\ebay_infrastructure\Service\StoreService;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Model\ListingImageUploadResult;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Response;

/**
 * Tests the eBay-specific payload rules in EbayMarketplacePublisher.
 *
 * What this is testing:
 * this class is the eBay adapter. It takes one generic
 * `ListingPublishRequest` and turns it into eBay's two-part model:
 * - inventory item payload
 * - offer payload
 *
 * Why this is a kernel test:
 * we want to run the real eBay adapter and the real sell API client, but with
 * a fake HTTP client underneath. That lets us capture the exact outbound
 * payloads without talking to the real eBay API.
 */
final class EbayMarketplacePublisherTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'options',
    'bb_platform',
    'ai_listing',
    'listing_publishing',
    'ebay_infrastructure',
    'ebay_connector',
  ];

  private array $httpRequests = [];
  private array $httpResponses = [];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ebay_account');

    $user = User::create([
      'name' => 'ebay-test-user',
    ]);
    $user->save();

    EbayAccount::create([
      'label' => 'Primary Test Account',
      'uid' => $user->id(),
      'environment' => 'production',
      'access_token' => 'test-access-token',
      'refresh_token' => 'test-refresh-token',
      'expires_at' => time() + 3600,
    ])->save();
  }

  public function testPublishAndUpdateShareTheSameInventoryPayload(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    // First run the publish path.
    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['offers' => []]),
      $this->jsonResponse(['offerId' => 'offer-1']),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listingId' => 'listing-1']),
    ];
    $publisher->publish($request);

    $publishInventoryPayload = $this->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');
    $this->assertNotNull($publishInventoryPayload);

    // Reset the captured HTTP traffic and run the update path.
    $this->httpRequests = [];
    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listing' => ['listingId' => 'listing-1']]),
    ];
    $publisher->updatePublication('offer-1', $request, 'FIXED_PRICE');

    $updateInventoryPayload = $this->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');
    $this->assertNotNull($updateInventoryPayload);

    $this->assertSame($publishInventoryPayload, $updateInventoryPayload);
  }

  public function testPublishAndUpdateShareTheSameOfferUpdatePayload(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['offers' => []]),
      $this->jsonResponse(['offerId' => 'offer-1']),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listingId' => 'listing-1']),
    ];
    $publisher->publish($request);

    $publishOfferUpdatePayload = $this->findJsonPayload('PUT', '/sell/inventory/v1/offer/offer-1');
    $this->assertNotNull($publishOfferUpdatePayload);

    $this->httpRequests = [];
    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listing' => ['listingId' => 'listing-1']]),
    ];
    $publisher->updatePublication('offer-1', $request, 'FIXED_PRICE');

    $updateOfferPayload = $this->findJsonPayload('PUT', '/sell/inventory/v1/offer/offer-1');
    $this->assertNotNull($updateOfferPayload);

    $this->assertSame($publishOfferUpdatePayload, $updateOfferPayload);
  }

  public function testOfferPayloadIncludesListingDescription(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['offers' => []]),
      $this->jsonResponse(['offerId' => 'offer-1']),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listingId' => 'listing-1']),
    ];
    $publisher->publish($request);

    $createOfferPayload = $this->findJsonPayload('POST', '/sell/inventory/v1/offer');
    $updateOfferPayload = $this->findJsonPayload('PUT', '/sell/inventory/v1/offer/offer-1');

    $this->assertSame('A short listing description.', $createOfferPayload['listingDescription']);
    $this->assertSame('A short listing description.', $updateOfferPayload['listingDescription']);
  }

  public function testInventoryPayloadIncludesConditionDescriptionAndBookTitleAspect(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
      bookTitle: 'Birdy',
    );

    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['offers' => []]),
      $this->jsonResponse(['offerId' => 'offer-1']),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listingId' => 'listing-1']),
    ];
    $publisher->publish($request);

    $inventoryPayload = $this->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');

    $this->assertSame('Clean copy with light edge wear.', $inventoryPayload['conditionDescription']);
    $this->assertSame('Birdy by William Wharton Paperback Book', $inventoryPayload['product']['title']);
    $this->assertSame(['Birdy'], $inventoryPayload['product']['aspects']['Book Title']);
  }

  public function testAdapterDoesNotInventAConditionDescription(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: '',
      bookTitle: 'Birdy',
    );

    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['offers' => []]),
      $this->jsonResponse(['offerId' => 'offer-1']),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listingId' => 'listing-1']),
    ];
    $publisher->publish($request);

    $inventoryPayload = $this->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');

    $this->assertSame('', $inventoryPayload['conditionDescription']);
  }

  public function testPublishCreatesANewOfferWhenNoExistingOfferIsFound(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['offers' => []]),
      $this->jsonResponse(['offerId' => 'offer-1']),
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listingId' => 'listing-1']),
    ];
    $publisher->publish($request);

    $this->assertNotNull($this->findRequestByPath('POST', '/sell/inventory/v1/offer'));
    $this->assertNotNull($this->findRequest('POST', '/sell/inventory/v1/offer/offer-1/publish'));
  }

  public function testPublishReusesAnExistingOfferWhenOneAlreadyExists(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    $this->httpResponses = [
      $this->jsonResponse([]),
      $this->jsonResponse([]),
      $this->jsonResponse(['offers' => [['offerId' => 'offer-9']]]),
      $this->jsonResponse([]),
      $this->jsonResponse(['listingId' => 'listing-1']),
      $this->jsonResponse(['listingId' => 'listing-1']),
    ];
    $publisher->publish($request);

    $this->assertNull($this->findRequestByPath('POST', '/sell/inventory/v1/offer'));
    $this->assertNotNull($this->findRequest('PUT', '/sell/inventory/v1/offer/offer-9'));
    $this->assertNotNull($this->findRequest('POST', '/sell/inventory/v1/offer/offer-9/publish'));
  }

  private function createPublisher(array $uploadedUrls): EbayMarketplacePublisher {
    $httpClient = $this->createMock(ClientInterface::class);
    $httpClient->method('request')
      ->willReturnCallback(function (string $method, string $url, array $options = []): Response {
        $this->httpRequests[] = [
          'method' => $method,
          'url' => $url,
          'options' => $options,
        ];

        $response = array_shift($this->httpResponses);
        if (!$response instanceof Response) {
          throw new \RuntimeException('No fake HTTP response was queued for ' . $method . ' ' . $url);
        }

        return $response;
      });

    $accountManager = new EbayAccountManager(
      $this->container->get('entity_type.manager'),
      new OAuthTokenService(
        $this->createMock(ClientInterface::class),
        $this->createMock(ConfigFactoryInterface::class),
      ),
    );

    $sellApiClient = new SellApiClient($httpClient, $accountManager);
    $storeService = new StoreService($sellApiClient);

    return new EbayMarketplacePublisher(
      $sellApiClient,
      new ConditionMapper(),
      new FixedImageUploader($uploadedUrls),
      $storeService,
      $this->container->get('logger.factory'),
    );
  }

  private function createRequest(
    string $title,
    string $description,
    string $conditionDescription,
    string $bookTitle = 'Birdy',
  ): ListingPublishRequest {
    return new ListingPublishRequest(
      'sku-1',
      $title,
      $description,
      'William Wharton',
      '29.95',
      [],
      [],
      1,
      'good',
      $conditionDescription,
      [
        'product_type' => 'book',
        'book_title' => $bookTitle,
        'author' => 'William Wharton',
        'language' => 'English',
        'isbn' => '9780141184201',
        'publisher' => 'Vintage',
        'publication_year' => '1980',
        'format' => 'Paperback',
        'genre' => 'Fiction',
        'topic' => 'War',
        'country_of_origin' => 'Australia',
        'series' => '',
        'bargain_bin' => FALSE,
      ],
    );
  }

  private function jsonResponse(array $data, int $statusCode = 200): Response {
    return new Response($statusCode, ['Content-Type' => 'application/json'], json_encode($data));
  }

  private function findRequest(string $method, string $pathFragment): ?array {
    foreach ($this->httpRequests as $request) {
      if ($request['method'] !== $method) {
        continue;
      }

      if (!str_contains($request['url'], $pathFragment)) {
        continue;
      }

      return $request;
    }

    return null;
  }

  private function findJsonPayload(string $method, string $pathFragment): ?array {
    $request = $this->findRequest($method, $pathFragment);
    if ($request === null) {
      return null;
    }

    return $request['options']['json'] ?? null;
  }

  private function findRequestByPath(string $method, string $exactPath): ?array {
    foreach ($this->httpRequests as $request) {
      if ($request['method'] !== $method) {
        continue;
      }

      $path = parse_url($request['url'], PHP_URL_PATH);
      if ($path !== $exactPath) {
        continue;
      }

      return $request;
    }

    return null;
  }

}

final class FixedImageUploader implements ListingImageUploaderInterface {

  public function __construct(
    private readonly array $remoteUrls,
  ) {}

  public function upload(array $sources): ListingImageUploadResult {
    return new ListingImageUploadResult($this->remoteUrls);
  }

}
