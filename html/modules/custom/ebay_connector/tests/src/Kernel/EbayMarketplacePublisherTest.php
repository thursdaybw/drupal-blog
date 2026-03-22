<?php

declare(strict_types=1);

namespace Drupal\Tests\ebay_connector\Kernel;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\bb_ebay_mirror\Service\EbayInventoryMirrorSyncService;
use Drupal\bb_ebay_mirror\Service\EbayOfferMirrorSyncService;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\ebay_connector\Entity\EbayAccount;
use Drupal\ebay_connector\Service\ConditionMapper;
use Drupal\ebay_connector\Service\EbayMarketplacePublisher;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Drupal\ebay_infrastructure\Service\OAuthTokenService;
use Drupal\ebay_infrastructure\Service\EbaySkuRemovalService;
use Drupal\ebay_infrastructure\Service\SellApiClient;
use Drupal\ebay_infrastructure\Service\StoreService;
use Drupal\Tests\ebay_infrastructure\Support\RecordedHttpClient;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Contract\ListingImageUploaderInterface;
use Drupal\listing_publishing\Model\ListingImageUploadResult;
use Drupal\listing_publishing\Model\ListingPublishRequest;
use Drupal\user\Entity\User;
use GuzzleHttp\ClientInterface;

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
    'bb_ebay_mirror',
  ];

  private RecordedHttpClient $httpClient;

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('ebay_account');
    $this->installSchema('bb_ebay_mirror', ['bb_ebay_inventory_item', 'bb_ebay_offer']);
    $this->httpClient = new RecordedHttpClient();

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
    $this->queueJsonResponses([
      [],
      [],
      ['offers' => []],
      ['offerId' => 'offer-1'],
      [],
      [],
      ['listingId' => 'listing-1'],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->publish($request);

    $publishInventoryPayload = $this->httpClient->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');
    $this->assertNotNull($publishInventoryPayload);

    // Reset the captured HTTP traffic and run the update path.
    $this->httpClient->clearRecordedRequests();
    $this->queueJsonResponses([
      [],
      [],
      [],
      ['listing' => ['listingId' => 'listing-1']],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->updatePublication('offer-1', $request, 'FIXED_PRICE');

    $updateInventoryPayload = $this->httpClient->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');
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

    $this->queueJsonResponses([
      [],
      [],
      ['offers' => []],
      ['offerId' => 'offer-1'],
      [],
      [],
      ['listingId' => 'listing-1'],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->publish($request);

    $publishOfferUpdatePayload = $this->httpClient->findJsonPayload('PUT', '/sell/inventory/v1/offer/offer-1');
    $this->assertNotNull($publishOfferUpdatePayload);

    $this->httpClient->clearRecordedRequests();
    $this->queueJsonResponses([
      [],
      [],
      [],
      ['listing' => ['listingId' => 'listing-1']],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->updatePublication('offer-1', $request, 'FIXED_PRICE');

    $updateOfferPayload = $this->httpClient->findJsonPayload('PUT', '/sell/inventory/v1/offer/offer-1');
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

    $this->queueJsonResponses([
      [],
      [],
      ['offers' => []],
      ['offerId' => 'offer-1'],
      [],
      [],
      ['listingId' => 'listing-1'],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->publish($request);

    $createOfferPayload = $this->httpClient->findJsonPayload('POST', '/sell/inventory/v1/offer');
    $updateOfferPayload = $this->httpClient->findJsonPayload('PUT', '/sell/inventory/v1/offer/offer-1');

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

    $this->queueJsonResponses([
      [],
      [],
      ['offers' => []],
      ['offerId' => 'offer-1'],
      [],
      [],
      ['listingId' => 'listing-1'],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->publish($request);

    $inventoryPayload = $this->httpClient->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');

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

    $this->queueJsonResponses([
      [],
      [],
      ['offers' => []],
      ['offerId' => 'offer-1'],
      [],
      [],
      ['listingId' => 'listing-1'],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->publish($request);

    $inventoryPayload = $this->httpClient->findJsonPayload('PUT', '/sell/inventory/v1/inventory_item/');

    $this->assertSame('', $inventoryPayload['conditionDescription']);
  }

  public function testPublishCreatesANewOfferWhenNoExistingOfferIsFound(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    $this->queueJsonResponses([
      [],
      [],
      ['offers' => []],
      ['offerId' => 'offer-1'],
      [],
      [],
      ['listingId' => 'listing-1'],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-1', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->publish($request);

    $this->assertNotNull($this->httpClient->findRequestByPath('POST', '/sell/inventory/v1/offer'));
    $this->assertNotNull($this->httpClient->findRequest('POST', '/sell/inventory/v1/offer/offer-1/publish'));
  }

  public function testPublishReusesAnExistingOfferWhenOneAlreadyExists(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    $this->queueJsonResponses([
      [],
      [],
      ['offers' => [['offerId' => 'offer-9']]],
      [],
      ['listingId' => 'listing-1'],
      ['listingId' => 'listing-1'],
      ['sku' => 'sku-1', 'product' => ['title' => 'Birdy by William Wharton Paperback Book']],
      ['offers' => [['offerId' => 'offer-9', 'sku' => 'sku-1', 'status' => 'PUBLISHED']]],
    ]);
    $publisher->publish($request);

    $this->assertNull($this->httpClient->findRequestByPath('POST', '/sell/inventory/v1/offer'));
    $this->assertNotNull($this->httpClient->findRequest('PUT', '/sell/inventory/v1/offer/offer-9'));
    $this->assertNotNull($this->httpClient->findRequest('POST', '/sell/inventory/v1/offer/offer-9/publish'));
  }

  public function testPublisherServiceBuildsFromDrupalContainer(): void {
    $publisher = $this->container->get('drupal.ebay_connector.marketplace_publisher');

    $this->assertInstanceOf(EbayMarketplacePublisher::class, $publisher);
  }

  public function testPublishRefreshesMirrorRowsForSku(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);
    $request = $this->createRequest(
      title: 'Birdy by William Wharton Paperback Book',
      description: 'A short listing description.',
      conditionDescription: 'Clean copy with light edge wear.',
    );

    $this->queueJsonResponses([
      [],
      [],
      ['offers' => []],
      ['offerId' => 'offer-1'],
      [],
      [],
      ['listingId' => 'listing-1'],
      [
        'sku' => 'sku-1',
        'product' => [
          'title' => 'Birdy by William Wharton Paperback Book',
        ],
        'condition' => 'USED_GOOD',
      ],
      [
        'offers' => [
          [
            'offerId' => 'offer-1',
            'sku' => 'sku-1',
            'status' => 'PUBLISHED',
          ],
        ],
      ],
    ]);

    $publisher->publish($request);

    $inventoryRow = $this->container->get('database')
      ->select('bb_ebay_inventory_item', 'i')
      ->fields('i')
      ->condition('account_id', 1)
      ->condition('sku', 'sku-1')
      ->execute()
      ->fetchObject();

    $offerRow = $this->container->get('database')
      ->select('bb_ebay_offer', 'o')
      ->fields('o')
      ->condition('account_id', 1)
      ->condition('offer_id', 'offer-1')
      ->execute()
      ->fetchObject();

    $this->assertNotFalse($inventoryRow);
    $this->assertSame('Birdy by William Wharton Paperback Book', $inventoryRow->title);
    $this->assertNotFalse($offerRow);
    $this->assertSame('sku-1', $offerRow->sku);
    $this->assertSame('PUBLISHED', $offerRow->status);
  }

  public function testDeleteSkuRemovesMirrorRowsImmediately(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);

    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => 1,
        'sku' => 'sku-1',
        'title' => 'Stale title',
        'last_seen' => time(),
      ])
      ->execute();
    $this->container->get('database')->insert('bb_ebay_offer')
      ->fields([
        'account_id' => 1,
        'offer_id' => 'offer-1',
        'sku' => 'sku-1',
        'status' => 'PUBLISHED',
        'last_seen' => time(),
      ])
      ->execute();

    $this->queueJsonResponses([
      ['offers' => [['offerId' => 'offer-1']]],
      [],
      [],
    ]);

    $publisher->deleteSku('sku-1');

    $inventoryCount = $this->container->get('database')
      ->select('bb_ebay_inventory_item', 'i')
      ->condition('account_id', 1)
      ->condition('sku', 'sku-1')
      ->countQuery()
      ->execute()
      ->fetchField();
    $offerCount = $this->container->get('database')
      ->select('bb_ebay_offer', 'o')
      ->condition('account_id', 1)
      ->condition('sku', 'sku-1')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertSame('0', (string) $inventoryCount);
    $this->assertSame('0', (string) $offerCount);
  }

  public function testDeleteSkuRemovesMirrorRowsWhenInventoryAlreadyMissing(): void {
    $publisher = $this->createPublisher(['https://images.example.com/book.jpg']);

    $this->container->get('database')->insert('bb_ebay_inventory_item')
      ->fields([
        'account_id' => 1,
        'sku' => 'sku-missing',
        'title' => 'Stale title',
        'last_seen' => time(),
      ])
      ->execute();
    $this->container->get('database')->insert('bb_ebay_offer')
      ->fields([
        'account_id' => 1,
        'offer_id' => 'offer-missing',
        'sku' => 'sku-missing',
        'status' => 'PUBLISHED',
        'last_seen' => time(),
      ])
      ->execute();

    $this->httpClient->queueJsonResponse([
      'errors' => [['errorId' => 25713, 'message' => 'Offer not found.']],
    ], 404);
    $this->httpClient->queueJsonResponse([
      'errors' => [['errorId' => 25710, 'message' => 'Inventory item not found.']],
    ], 404);

    $publisher->deleteSku('sku-missing');

    $inventoryCount = $this->container->get('database')
      ->select('bb_ebay_inventory_item', 'i')
      ->condition('account_id', 1)
      ->condition('sku', 'sku-missing')
      ->countQuery()
      ->execute()
      ->fetchField();
    $offerCount = $this->container->get('database')
      ->select('bb_ebay_offer', 'o')
      ->condition('account_id', 1)
      ->condition('sku', 'sku-missing')
      ->countQuery()
      ->execute()
      ->fetchField();

    $this->assertSame('0', (string) $inventoryCount);
    $this->assertSame('0', (string) $offerCount);
  }

  private function createPublisher(array $uploadedUrls): EbayMarketplacePublisher {
    $accountManager = new EbayAccountManager(
      $this->container->get('entity_type.manager'),
      new OAuthTokenService(
        $this->createMock(ClientInterface::class),
        $this->createMock(ConfigFactoryInterface::class),
      ),
    );

    $sellApiClient = new SellApiClient($this->httpClient, $accountManager);
    $storeService = new StoreService($sellApiClient);
    $inventoryMirrorSyncService = new EbayInventoryMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );
    $offerMirrorSyncService = new EbayOfferMirrorSyncService(
      $sellApiClient,
      $this->container->get('database'),
    );
    $skuRemovalService = new EbaySkuRemovalService(
      $sellApiClient,
      $accountManager,
      $inventoryMirrorSyncService,
      $offerMirrorSyncService,
    );

    return new EbayMarketplacePublisher(
      $sellApiClient,
      new ConditionMapper(),
      new FixedImageUploader($uploadedUrls),
      $storeService,
      $accountManager,
      $inventoryMirrorSyncService,
      $offerMirrorSyncService,
      $skuRemovalService,
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

  private function queueJsonResponses(array $responses): void {
    foreach ($responses as $response) {
      $this->httpClient->queueJsonResponse($response);
    }
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
