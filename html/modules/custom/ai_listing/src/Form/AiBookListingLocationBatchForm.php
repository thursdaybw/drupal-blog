<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\listing_publishing\Service\ListingPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiBookListingLocationBatchForm extends FormBase implements ContainerInjectionInterface {
  public const PUBLISH_UPDATE_CONFIRM_TEMPSTORE_COLLECTION = 'ai_listing.location_batch';
  public const PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY = 'publish_update_confirmation';

  private ?EntityTypeManagerInterface $entityTypeManager = null;
  private ?ListingPublisher $listingPublisher = null;
  private ?DateFormatterInterface $dateFormatter = null;
  private ?PrivateTempStoreFactory $tempStoreFactory = null;

  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->listingPublisher = $container->get('drupal.listing_publishing.publisher');
    $form->dateFormatter = $container->get('date.formatter');
    $form->tempStoreFactory = $container->get('tempstore.private');
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
      '#options' => [
        'new' => $this->t('New'),
        'processing' => $this->t('Processing'),
        'ready_for_review' => $this->t('Ready for review'),
        'ready_to_shelve' => $this->t('Ready to shelve'),
        'shelved' => $this->t('Shelved'),
        'published' => $this->t('Published'),
        'failed' => $this->t('Failed'),
      ],
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
        'type' => $this->t('Type'),
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

    $form['actions']['delete_selected'] = [
      '#type' => 'submit',
      '#name' => 'delete_selected',
      '#value' => $this->t('Delete selected'),
      '#submit' => ['::submitDeleteSelected'],
    ];

    $form['#attached']['library'][] = 'ai_listing/location_table';

    return $form;
  }

  public function updateListingsCallback(array &$form, FormStateInterface $form_state): array {
    return $form['listings_container'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
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
    $this->queueListingBatch($form_state, TRUE, 'publish');
  }

  public function submitPublishOrUpdate(array &$form, FormStateInterface $form_state): void {
    $setLocation = $this->getRequestedLocation($form_state) !== '';
    if ($this->redirectToPublishUpdateConfirmationIfNeeded($form_state, $setLocation)) {
      return;
    }

    $this->queueListingBatch($form_state, $setLocation, 'publish_update');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->queueListingBatch($form_state, TRUE, 'publish');
  }

  public function submitDeleteSelected(array &$form, FormStateInterface $form_state): void {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
      $this->messenger()->addError($this->t('Select at least one listing to delete.'));
      $form_state->setRebuild();
      return;
    }

    batch_set(self::buildDeleteBatchDefinition($selection));
  }

  private function queueListingBatch(FormStateInterface $form_state, bool $setLocation, string $operationMode): void {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
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

    batch_set(self::buildListingBatchDefinition($selection, $setLocation, $location, $operationMode));
  }

  /**
   * @param array<int,array{entity_type:string,id:int}|int|string> $selection
   */
  public static function buildListingBatchDefinition(array $selection, bool $setLocation, string $location, string $operationMode = 'publish'): array {
    $operations = [];
    foreach ($selection as $item) {
      if (is_int($item) || (is_string($item) && ctype_digit($item))) {
        $item = [
          'listing_type' => 'book',
          'id' => (int) $item,
        ];
      }
      if (!is_array($item)) {
        continue;
      }
      if (!isset($item['listing_type'], $item['id'])) {
        continue;
      }
      $operations[] = [
        [self::class, 'processBatchOperation'],
        [$item['listing_type'], (int) $item['id'], $setLocation, $location, $operationMode],
      ];
    }

    $translation = \Drupal::translation();

    return [
      'title' => $setLocation
        ? $translation->translate('Setting locations and publishing listings')
        : $translation->translate('Publishing/updating listings'),
      'operations' => $operations,
      'finished' => [self::class, 'finishBatchOperation'],
      'init_message' => $setLocation
        ? $translation->translate('Starting location update and publish batch...')
        : $translation->translate('Starting publish/update batch...'),
      'progress_message' => $translation->translate('Processed @current of @total listings.'),
      'error_message' => $translation->translate('The batch finished with an unexpected error.'),
    ];
  }

  public static function processBatchOperation(string $listingType, int $listingId, bool $setLocation, string $location, string $operationMode, array &$context): void {
    if (!in_array($listingType, ['book', 'book_bundle'], TRUE)) {
      $context['message'] = (string) \Drupal::translation()->translate('Skipping unsupported listing type @type:@id.', ['@type' => $listingType, '@id' => $listingId]);
      return;
    }

    $entityTypeManager = \Drupal::entityTypeManager();
    $storage = $entityTypeManager->getStorage('bb_ai_listing');
    /** @var \Drupal\ai_listing\Entity\BbAiListing|null $listing */
    $listing = $storage->load($listingId);

    if (!isset($context['results']['success'])) {
      $context['results']['success'] = 0;
    }
    if (!isset($context['results']['errors'])) {
      $context['results']['errors'] = [];
    }
    $context['results']['set_location'] = $setLocation;
    $context['results']['operation_mode'] = $operationMode;

    if (!$listing instanceof BbAiListing) {
      $context['message'] = (string) \Drupal::translation()->translate('Skipping missing listing @id.', ['@id' => $listingId]);
      return;
    }

    if ($setLocation) {
      $listing->set('storage_location', $location);
      $listing->save();
    }

    $publisher = \Drupal::service('drupal.listing_publishing.publisher');

    try {
      if ($operationMode === 'publish_update') {
        $result = $publisher->publishOrUpdate($listing);
      }
      else {
        $result = $publisher->publish($listing);
      }
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

    $listing->set('status', 'shelved');
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
    $operationMode = (string) ($results['operation_mode'] ?? 'publish');

    if ($processedCount > 0) {
      if ($operationMode === 'publish_update') {
        $singular = $setLocation ? 'Set location and published/updated one listing.' : 'Published/updated one listing.';
        $plural = $setLocation ? 'Set location and published/updated @count listings.' : 'Published/updated @count listings.';
      }
      else {
        $singular = $setLocation ? 'Shelved and published one listing.' : 'Published one listing.';
        $plural = $setLocation ? 'Shelved and published @count listings.' : 'Published @count listings.';
      }
      $messenger->addStatus($translation->formatPlural($processedCount, $singular, $plural));
    }

    foreach (($results['errors'] ?? []) as $message) {
      $messenger->addError($message);
    }
  }

  /**
   * @param array<int,array{listing_type:string,id:int}> $selection
   */
  public static function buildDeleteBatchDefinition(array $selection): array {
    $operations = [];
    foreach ($selection as $item) {
      $operations[] = [
        [self::class, 'processDeleteBatchOperation'],
        [$item['listing_type'], (int) $item['id']],
      ];
    }

    $translation = \Drupal::translation();

    return [
      'title' => $translation->translate('Deleting listings'),
      'operations' => $operations,
      'finished' => [self::class, 'finishDeleteBatchOperation'],
      'init_message' => $translation->translate('Starting delete batch...'),
      'progress_message' => $translation->translate('Deleted @current of @total listings.'),
      'error_message' => $translation->translate('The delete batch finished with an unexpected error.'),
    ];
  }

  public static function processDeleteBatchOperation(string $listingType, int $listingId, array &$context): void {
    if (!isset($context['results']['success'])) {
      $context['results']['success'] = 0;
    }
    if (!isset($context['results']['errors'])) {
      $context['results']['errors'] = [];
    }

    $entityTypeManager = \Drupal::entityTypeManager();
    $storage = $entityTypeManager->getStorage('bb_ai_listing');
    $listing = $storage->load($listingId);
    if ($listing === NULL) {
      $context['message'] = (string) \Drupal::translation()->translate('Skipping missing listing @type:@id.', ['@type' => $listingType, '@id' => $listingId]);
      return;
    }

    $title = $listing->label() ?: 'Untitled';

    if ($listingType === 'book' && self::listingHasInventoryAndMarketplaceData($listingId)) {
      $context['results']['errors'][] = (string) \Drupal::translation()->translate(
        'Listing %title was not deleted because Drupal has inventory and marketplace publication records for it.',
        ['%title' => $title]
      );
      $context['message'] = (string) \Drupal::translation()->translate('Blocked delete for listing @id.', ['@id' => $listingId]);
      return;
    }

    try {
      $listing->delete();
      $context['results']['success']++;
      $context['message'] = (string) \Drupal::translation()->translate('Deleted listing @type:@id: @title', [
        '@type' => $listingType,
        '@id' => $listingId,
        '@title' => $title,
      ]);
    }
    catch (\Throwable $e) {
      $context['results']['errors'][] = (string) \Drupal::translation()->translate(
        'Listing %title could not be deleted: %reason',
        [
          '%title' => $title,
          '%reason' => $e->getMessage(),
        ]
      );
      $context['message'] = (string) \Drupal::translation()->translate('Failed delete for listing @type:@id.', ['@type' => $listingType, '@id' => $listingId]);
    }
  }

  public static function finishDeleteBatchOperation(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $translation = \Drupal::translation();

    if (!$success) {
      $messenger->addError((string) $translation->translate('The delete batch did not complete successfully.'));
    }

    $deletedCount = (int) ($results['success'] ?? 0);
    if ($deletedCount > 0) {
      $messenger->addStatus($translation->formatPlural($deletedCount, 'Deleted one listing.', 'Deleted @count listings.'));
    }

    foreach (($results['errors'] ?? []) as $message) {
      $messenger->addError($message);
    }
  }

  private static function listingHasInventoryAndMarketplaceData(int $listingId): bool {
    $entityTypeManager = \Drupal::entityTypeManager();

    $inventoryIds = $entityTypeManager->getStorage('ai_listing_inventory_sku')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingId)
      ->range(0, 1)
      ->execute();

    if ($inventoryIds === []) {
      return FALSE;
    }

    $publicationIds = $entityTypeManager->getStorage('ai_marketplace_publication')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingId)
      ->range(0, 1)
      ->execute();

    return $publicationIds !== [];
  }

  private function isSetLocationAndPublishAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';
    return $triggerName === 'set_location_and_publish';
  }

  private function getRequestedLocation(FormStateInterface $form_state): string {
    return trim((string) $form_state->getValue('location'));
  }

  private function redirectToPublishUpdateConfirmationIfNeeded(FormStateInterface $form_state, bool $setLocation): bool {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
      return FALSE;
    }

    $selectedIds = [];
    foreach ($selection as $item) {
      $selectedIds[] = (int) $item['id'];
    }
    if ($setLocation) {
      return FALSE;
    }

    $missingLocationIds = $this->findListingsMissingStorageLocation($selectedIds);
    if ($missingLocationIds === []) {
      return FALSE;
    }

    $this->getConfirmTempStore()->set(self::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY, [
      'listing_ids' => $selectedIds,
      'set_location' => $setLocation,
      'location' => $setLocation ? $this->getRequestedLocation($form_state) : '',
      'operation_mode' => 'publish_update',
      'missing_location_ids' => $missingLocationIds,
      'selected_count' => count($selectedIds),
      'missing_location_count' => count($missingLocationIds),
      'created_at' => time(),
    ]);

    $form_state->setRedirect('ai_listing.location_batch.publish_update_confirm');
    return TRUE;
  }

  private function findListingsMissingStorageLocation(array $listingIds): array {
    if ($listingIds === []) {
      return [];
    }

    $storage = $this->getEntityTypeManager()->getStorage('bb_ai_listing');
    $listings = $storage->loadMultiple($listingIds);
    $missingIds = [];

    foreach ($listingIds as $listingId) {
      $listing = $listings[$listingId] ?? null;
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $location = trim((string) ($listing->get('storage_location')->value ?? ''));
      if ($location === '') {
        $missingIds[] = $listingId;
      }
    }

    return $missingIds;
  }

  private function buildReadyToShelveOptions(string $status, bool $onlyBargainBin): array {
    $entityTypeManager = $this->getEntityTypeManager();
    $properties = ['status' => $status];
    if ($onlyBargainBin) {
      $properties['bargain_bin'] = 1;
    }
    $rows = [];
    $items = $entityTypeManager->getStorage('bb_ai_listing')->loadByProperties($properties);
    foreach ($items as $listing) {
      if (!$listing instanceof BbAiListing) {
        continue;
      }
      $rows[] = [
        'listing_type' => (string) $listing->bundle(),
        'entity' => $listing,
        'created' => (int) $listing->get('created')->value,
      ];
    }

    usort($rows, static fn(array $a, array $b): int => $a['created'] <=> $b['created']);

    $options = [];
    foreach ($rows as $row) {
      $listingType = $row['listing_type'];
      $listing = $row['entity'];
      $created = $row['created'];

      if (!$listing instanceof BbAiListing) {
        continue;
      }

      if ($listingType === 'book') {
        $label = (string) ($listing->get('field_full_title')->value ?: $listing->get('field_title')->value ?: $listing->label());
        $link = Link::fromTextAndUrl(
          $label ?: $this->t('Untitled listing'),
          Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $listing->id()])
        );

        $options[$this->buildSelectionKey('book', (int) $listing->id())] = [
          'type' => $this->t('Book'),
          'title' => $link->toString(),
          'author' => $listing->get('field_author')->value ?: $this->t('Unknown'),
          'price' => $listing->get('price')->value ?? $this->t('—'),
          'location' => $listing->get('storage_location')->value ?: $this->t('Unset yet'),
          'created' => $this->getDateFormatter()->format($created),
        ];
        continue;
      }

      if ($listingType !== 'book_bundle') {
        continue;
      }

      $bundleLabel = (string) ($listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled bundle'));
      $bundleLink = Link::fromTextAndUrl(
        $bundleLabel,
        Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $listing->id()])
      );

      $options[$this->buildSelectionKey('book_bundle', (int) $listing->id())] = [
        'type' => $this->t('Book bundle'),
        'title' => $bundleLink->toString(),
        'author' => $this->t('—'),
        'price' => $listing->get('price')->value ?? $this->t('—'),
        'location' => $listing->get('storage_location')->value ?: $this->t('Unset yet'),
        'created' => $this->getDateFormatter()->format($created),
      ];
    }

    return $options;
  }

  private function buildSelectionKey(string $listingType, int $id): string {
    return $listingType . ':' . $id;
  }

  /**
   * @return array<int,array{listing_type:string,id:int}>
   */
  private function getSelectedListingRefs(FormStateInterface $form_state): array {
    $selected = array_filter($form_state->getValue('listings') ?? []);
    if ($selected === []) {
      return [];
    }

    $selection = [];
    foreach (array_keys($selected) as $key) {
      $decoded = $this->parseSelectionKey((string) $key);
      if ($decoded !== NULL) {
        $selection[] = $decoded;
      }
    }

    return $selection;
  }

  /**
   * @return array{listing_type:string,id:int}|null
   */
  private function parseSelectionKey(string $key): ?array {
    if (!str_contains($key, ':')) {
      return NULL;
    }

    [$listingType, $id] = explode(':', $key, 2);
    $listingType = trim($listingType);
    $entityId = (int) $id;

    if ($listingType === '' || $entityId <= 0) {
      return NULL;
    }

    return [
      'listing_type' => $listingType,
      'id' => $entityId,
    ];
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

  private function getConfirmTempStore(): \Drupal\Core\TempStore\PrivateTempStore {
    if ($this->tempStoreFactory === null) {
      $this->tempStoreFactory = \Drupal::service('tempstore.private');
    }

    return $this->tempStoreFactory->get(self::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_COLLECTION);
  }
}
