<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_listing\Kernel;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Entity\BbAiListingType;
use Drupal\ai_listing\Form\AiBookListingReviewForm;
use Drupal\ai_listing\Service\ListingCullService;
use Drupal\ai_listing\Service\ListingHistoryQuery;
use Drupal\Core\Form\FormState;
use Drupal\file\Entity\File;
use Drupal\KernelTests\KernelTestBase;
use Drupal\listing_publishing\Service\ListingPublisher;

final class AiBookListingReviewFormWorkflowTest extends KernelTestBase {

  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'text',
    'filter',
    'options',
    'dynamic_entity_reference',
    'bb_platform',
    'ai_listing',
  ];

  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('file');
    $this->installEntitySchema('bb_ai_listing');
    $this->installEntitySchema('listing_image');
    $this->installEntitySchema('ai_marketplace_publication');
    $this->installConfig(['ai_listing']);
    $this->installSchema('ai_listing', ['bb_ai_listing_history']);

    $this->createBookType();
  }

  public function testNewListingShowsReadyForInferenceAction(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
    ]);
    $listing->save();

    $form = $this->buildReviewForm();
    $built = $form->buildForm([], new FormState(), $listing);

    $this->assertArrayHasKey('mark_ready_for_inference', $built['actions']);
    $this->assertArrayNotHasKey('mark_ready_to_shelve', $built['actions']);
  }

  public function testNewListingCanBeSavedWithoutMetadataSelection(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
    ]);
    $listing->save();

    $form = $this->buildReviewForm();
    $formState = new FormState();
    $formState->setTriggeringElement(['#name' => 'ai_save_listing']);
    $formState->setValue(['basic', 'status'], 'new');
    $built = $form->buildForm([], $formState, $listing);

    $form->validateForm($built, $formState);

    $this->assertSame([], $formState->getErrors());
  }

  public function testSavePersistsKeepScore(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
      'price' => '9.99',
    ]);
    $listing->save();

    $form = $this->buildReviewForm();
    $formState = new FormState();
    $formState->set('listing', $listing);
    $formState->setTriggeringElement(['#name' => 'ai_save_listing']);
    $formState->setValue(['basic', 'status'], 'new');
    $formState->setValue(['basic', 'price'], '9.99');
    $formState->setValue(['basic', 'keep_score'], 'high');
    $formState->setValue(['ebay', 'description'], ['value' => '', 'format' => 'basic_html']);
    $formState->setValue(['condition', 'condition_grade'], 'good');
    $formState->setValue(['condition', 'condition_issues'], []);
    $formState->setValue(['condition', 'condition_note'], '');

    $built = [];
    $form->submitForm($built, $formState);

    $reloaded = BbAiListing::load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloaded);
    $this->assertSame('high', (string) $reloaded->get('keep_score')->value);
  }

  public function testReadyForInferenceActionRequiresMetadataSelection(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
    ]);
    $listing->save();

    $form = $this->buildReviewForm();
    $formState = new FormState();
    $formState->setTriggeringElement(['#name' => 'mark_ready_for_inference']);
    $formState->setValue(['basic', 'status'], 'new');
    $built = $form->buildForm([], $formState, $listing);

    $form->validateForm($built, $formState);

    $this->assertArrayHasKey('photos][items][listing_image_items', $formState->getErrors());
  }

  public function testReadyForInferenceActionUpdatesStatusAndRedirectsToNextNewListing(): void {
    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
      'price' => '9.99',
    ]);
    $listing->save();

    $nextListing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
      'price' => '9.99',
    ]);
    $nextListing->save();

    $file = $this->createPermanentFile('public://ai-review/metadata.jpg');
    $listingImage = $this->container->get('entity_type.manager')->getStorage('listing_image')->create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => (int) $listing->id(),
      ],
      'file' => (int) $file->id(),
      'weight' => 0,
      'is_metadata_source' => FALSE,
    ]);
    $listingImage->save();

    $form = $this->buildReviewForm();
    $formState = new FormState();
    $formState->set('listing', $listing);
    $formState->setValue(['basic', 'status'], 'new');
    $formState->setValue(['basic', 'price'], '9.99');
    $formState->setValue(['ebay', 'description'], ['value' => '', 'format' => 'basic_html']);
    $formState->setValue(['condition', 'condition_grade'], 'good');
    $formState->setValue(['condition', 'condition_issues'], []);
    $formState->setValue(['condition', 'condition_note'], '');
    $formState->setValue(['photos', 'items', 'listing_image_items', 'listing_image_' . (int) $listingImage->id(), 'is_metadata_source'], 1);

    $built = [];
    $form->submitAndSetReadyForInference($built, $formState);

    $reloaded = BbAiListing::load((int) $listing->id());
    $this->assertInstanceOf(BbAiListing::class, $reloaded);
    $this->assertSame('ready_for_inference', (string) $reloaded->get('status')->value);

    $reloadedImage = $this->container->get('entity_type.manager')->getStorage('listing_image')->load((int) $listingImage->id());
    $this->assertSame('1', (string) $reloadedImage->get('is_metadata_source')->value);

    $redirect = $formState->getRedirect();
    $this->assertSame('entity.bb_ai_listing.canonical', $redirect->getRouteName());
    $this->assertSame((int) $nextListing->id(), (int) $redirect->getRouteParameters()['bb_ai_listing']);
  }

  private function buildReviewForm(): AiBookListingReviewForm {
    return new AiBookListingReviewForm(
      $this->container->get('entity_type.manager'),
      $this->container->get('file_url_generator'),
      (new \ReflectionClass(ListingPublisher::class))->newInstanceWithoutConstructor(),
      new ListingHistoryQuery($this->container->get('database')),
      (new \ReflectionClass(ListingCullService::class))->newInstanceWithoutConstructor(),
      $this->container->get('date.formatter'),
    );
  }

  private function createPermanentFile(string $uri): File {
    $directory = dirname($uri);
    \Drupal::service('file_system')->prepareDirectory($directory, \Drupal\Core\File\FileSystemInterface::CREATE_DIRECTORY | \Drupal\Core\File\FileSystemInterface::MODIFY_PERMISSIONS);
    file_put_contents(\Drupal::service('file_system')->realpath($directory) . '/' . basename($uri), 'test');

    $file = File::create([
      'uri' => $uri,
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();

    return $file;
  }

  private function createBookType(): void {
    BbAiListingType::create([
      'id' => 'book',
      'label' => 'Book',
      'description' => 'Single book listing.',
    ])->save();
  }

}
