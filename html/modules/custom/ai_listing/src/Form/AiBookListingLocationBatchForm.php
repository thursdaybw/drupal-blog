<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\listing_publishing\Service\ListingPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiBookListingLocationBatchForm extends FormBase implements ContainerInjectionInterface {

  private ?EntityTypeManagerInterface $entityTypeManager = null;
  private ?ListingPublisher $listingPublisher = null;
  private ?DateFormatterInterface $dateFormatter = null;

  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->listingPublisher = $container->get('drupal.listing_publishing.publisher');
    $form->dateFormatter = $container->get('date.formatter');
    return $form;
  }

  public function getFormId(): string {
    return 'ai_book_listing_location_batch_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $statusFilter = $form_state->getValue('status_filter') ?? 'ready_to_shelve';
    $bargainFilter = (bool) $form_state->getValue('bargain_bin_filter');

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-filters']],
    ];

    $form['filters']['status_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Status filter'),
      '#options' => AiBookListing::getStatusOptions(),
      '#default_value' => $statusFilter,
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['filters']['bargain_bin_filter'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Only bargain bin'),
      '#default_value' => $bargainFilter,
      '#description' => $this->t('Show only listings flagged for the bargain bin shipping policy.'),
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['listings_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-batch-listings'],
    ];

    $form['listings_container']['listings'] = [
      '#type' => 'tableselect',
      '#header' => [
        'title' => $this->t('Title'),
        'author' => $this->t('Author'),
        'price' => $this->t('Price'),
        'location' => $this->t('Current location'),
        'created' => $this->t('Created'),
      ],
      '#options' => $this->buildReadyToShelveOptions($statusFilter, $bargainFilter),
      '#empty' => $this->t('No listings are ready for shelving at the moment.'),
      '#multiple' => TRUE,
      '#default_value' => [],
    ];

    $form['location'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Storage location'),
      '#description' => $this->t('Set the shelf or bin code to apply to the selected listings and publish them.'),
      '#required' => FALSE,
    ];

    $form['actions']['set_location_and_publish'] = [
      '#type' => 'submit',
      '#name' => 'set_location_and_publish',
      '#value' => $this->t('Set location and publish'),
      '#button_type' => 'primary',
      '#submit' => ['::submitSetLocationAndPublish'],
    ];

    $form['actions']['publish_update'] = [
      '#type' => 'submit',
      '#name' => 'publish_update',
      '#value' => $this->t('Publish/Update'),
      '#submit' => ['::submitPublishOrUpdate'],
    ];

    $form['#attached']['library'][] = 'ai_listing/location_table';

    return $form;
  }

  public function updateListingsCallback(array &$form, FormStateInterface $form_state): array {
    return $form['listings_container'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selected = array_filter($form_state->getValue('listings') ?? []);
    if (empty($selected)) {
      $form_state->setErrorByName('listings', $this->t('Select at least one listing to update.'));
      return;
    }

    if (!$this->isSetLocationAndPublishAction($form_state)) {
      return;
    }

    $location = $this->getRequestedLocation($form_state);
    if ($location !== '') {
      return;
    }

    $form_state->setErrorByName('location', $this->t('Provide a storage location before submitting.'));
  }

  public function submitSetLocationAndPublish(array &$form, FormStateInterface $form_state): void {
    $this->queueListingBatch($form_state, TRUE);
  }

  public function submitPublishOrUpdate(array &$form, FormStateInterface $form_state): void {
    $this->queueListingBatch($form_state, FALSE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->queueListingBatch($form_state, TRUE);
  }

  private function queueListingBatch(FormStateInterface $form_state, bool $setLocation): void {
    $selected = array_filter($form_state->getValue('listings') ?? []);
    if (empty($selected)) {
      $this->messenger()->addError($this->t('Select at least one listing to update.'));
      $form_state->setRebuild();
      return;
    }

    $location = $this->getRequestedLocation($form_state);
    if ($setLocation && $location === '') {
      $this->messenger()->addError($this->t('Provide a storage location before submitting.'));
      $form_state->setRebuild();
      return;
    }

    $operations = [];
    foreach (array_keys($selected) as $listingId) {
      $operations[] = [
        [self::class, 'processBatchOperation'],
        [(int) $listingId, $setLocation, $location],
      ];
    }

    $batch = [
      'title' => $setLocation
        ? $this->t('Setting locations and publishing listings')
        : $this->t('Publishing/updating listings'),
      'operations' => $operations,
      'finished' => [self::class, 'finishBatchOperation'],
      'init_message' => $setLocation
        ? $this->t('Starting location update and publish batch...')
        : $this->t('Starting publish/update batch...'),
      'progress_message' => $this->t('Processed @current of @total listings.'),
      'error_message' => $this->t('The batch finished with an unexpected error.'),
    ];

    batch_set($batch);
  }

  public static function processBatchOperation(int $listingId, bool $setLocation, string $location, array &$context): void {
    $entityTypeManager = \Drupal::entityTypeManager();
    $storage = $entityTypeManager->getStorage('ai_book_listing');
    /** @var \Drupal\ai_listing\Entity\AiBookListing|null $listing */
    $listing = $storage->load($listingId);

    if (!isset($context['results']['success'])) {
      $context['results']['success'] = 0;
    }
    if (!isset($context['results']['errors'])) {
      $context['results']['errors'] = [];
    }
    $context['results']['set_location'] = $setLocation;

    if (!$listing instanceof AiBookListing) {
      $context['message'] = (string) \Drupal::translation()->translate('Skipping missing listing @id.', ['@id' => $listingId]);
      return;
    }

    if ($setLocation) {
      $listing->set('storage_location', $location);
      $listing->save();
    }

    $publisher = \Drupal::service('drupal.listing_publishing.publisher');

    try {
      $result = $setLocation
        ? $publisher->publish($listing)
        : $publisher->publishOrUpdate($listing);
    }
    catch (\Throwable $e) {
      $listing->set('status', 'failed');
      $listing->save();
      $context['results']['errors'][] = (string) \Drupal::translation()->translate(
        'Listing %title failed: %reason',
        [
          '%title' => $listing->label(),
          '%reason' => $e->getMessage(),
        ]
      );
      $context['message'] = (string) \Drupal::translation()->translate('Failed listing @id.', ['@id' => $listingId]);
      return;
    }

    if (!$result->isSuccess()) {
      $listing->set('status', 'failed');
      $listing->save();
      $context['results']['errors'][] = (string) \Drupal::translation()->translate(
        'Listing %title failed: %reason',
        [
          '%title' => $listing->label(),
          '%reason' => $result->getMessage(),
        ]
      );
      $context['message'] = (string) \Drupal::translation()->translate('Failed listing @id.', ['@id' => $listingId]);
      return;
    }

    $marketplaceListingId = $result->getMarketplaceListingId() ?? $result->getMarketplaceId();
    if ($marketplaceListingId !== null && $marketplaceListingId !== '') {
      $listing->set('ebay_item_id', $marketplaceListingId);
    }
    $listing->set('status', 'published');
    $listing->save();
    $context['results']['success']++;
    $context['message'] = (string) \Drupal::translation()->translate(
      'Processed listing @id: @title',
      [
        '@id' => $listingId,
        '@title' => $listing->label() ?: 'Untitled',
      ]
    );
  }

  public static function finishBatchOperation(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $translation = \Drupal::translation();

    if (!$success) {
      $messenger->addError((string) $translation->translate('The batch process did not complete successfully.'));
    }

    $processedCount = (int) ($results['success'] ?? 0);
    $setLocation = (bool) ($results['set_location'] ?? FALSE);

    if ($processedCount > 0) {
      $singular = $setLocation ? 'Published one listing.' : 'Published/updated one listing.';
      $plural = $setLocation ? 'Published @count listings.' : 'Published/updated @count listings.';
      $messenger->addStatus($translation->formatPlural($processedCount, $singular, $plural));
    }

    foreach (($results['errors'] ?? []) as $message) {
      $messenger->addError($message);
    }
  }

  private function isSetLocationAndPublishAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';
    return $triggerName === 'set_location_and_publish';
  }

  private function getRequestedLocation(FormStateInterface $form_state): string {
    return trim((string) $form_state->getValue('location'));
  }

  private function buildReadyToShelveOptions(string $status, bool $onlyBargainBin): array {
    $storage = $this->getEntityTypeManager()->getStorage('ai_book_listing');
    $properties = ['status' => $status];
    if ($onlyBargainBin) {
      $properties['bargain_bin'] = 1;
    }
    $items = $storage->loadByProperties($properties);
    uasort($items, fn($a, $b) => $a->get('created')->value <=> $b->get('created')->value);

    $options = [];
    foreach ($items as $listing) {
      $link = Link::fromTextAndUrl(
        $listing->label() ?: $this->t('Untitled listing'),
        Url::fromRoute('entity.ai_book_listing.canonical', ['ai_book_listing' => $listing->id()])
      );

      $options[$listing->id()] = [
        'title' => $link->toString(),
        'author' => $listing->get('author')->value ?: $this->t('Unknown'),
        'price' => $listing->get('price')->value ?? $this->t('—'),
        'location' => $listing->get('storage_location')->value ?: $this->t('Unset yet'),
        'created' => $this->getDateFormatter()->format((int) $listing->get('created')->value),
      ];
    }

    return $options;
  }

  private function getEntityTypeManager(): EntityTypeManagerInterface {
    if ($this->entityTypeManager === null) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
  }

  private function getListingPublisher(): ListingPublisher {
    if ($this->listingPublisher === null) {
      $this->listingPublisher = \Drupal::service('drupal.listing_publishing.publisher');
    }
    return $this->listingPublisher;
  }

  private function getDateFormatter(): DateFormatterInterface {
    if ($this->dateFormatter === null) {
      $this->dateFormatter = \Drupal::service('date.formatter');
    }
    return $this->dateFormatter;
  }
}
