<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Pager\PagerParametersInterface;
use Drupal\Core\TempStore\PrivateTempStoreFactory;
use Drupal\Core\Url;
use Drupal\Component\Render\MarkupInterface;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\ai_listing\Model\AiListingBatchFilter;
use Drupal\ai_listing\Service\AiListingBatchDatasetProvider;
use Drupal\ai_listing\Service\AiListingBatchSelectionManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiListingWorkbenchForm extends FormBase implements ContainerInjectionInterface {
  public const WORKBENCH_TEMPSTORE_COLLECTION = 'ai_listing.workbench';
  public const PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY = 'publish_update_confirmation';
  public const LOCATION_CONFIRM_TEMPSTORE_KEY = 'location_confirmation';
  public const SELECTED_LISTING_KEYS_FIELD = 'selected_listing_keys';

  private ?EntityTypeManagerInterface $entityTypeManager = null;
  private ?DateFormatterInterface $dateFormatter = null;
  private ?PrivateTempStoreFactory $tempStoreFactory = null;
  private ?PagerManagerInterface $pagerManager = null;
  private ?PagerParametersInterface $pagerParameters = null;
  private ?AiListingBatchDatasetProvider $batchDatasetProvider = null;
  private ?AiListingBatchSelectionManager $batchSelectionManager = null;

  public static function create(ContainerInterface $container): self {
    $form = new self();
    $form->entityTypeManager = $container->get('entity_type.manager');
    $form->dateFormatter = $container->get('date.formatter');
    $form->tempStoreFactory = $container->get('tempstore.private');
    $form->pagerManager = $container->get('pager.manager');
    $form->pagerParameters = $container->get('pager.parameters');
    $form->batchDatasetProvider = $container->get('ai_listing.batch_dataset_provider');
    $form->batchSelectionManager = $container->get('ai_listing.batch_selection_manager');
    return $form;
  }

  public function getFormId(): string {
    return 'ai_listing_workbench_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $statusFilter = $this->resolveFilterValue($form_state, 'status_filter', 'any');
    $bargainFilterMode = $this->resolveFilterValue($form_state, 'bargain_bin_filter', 'any');
    $publishedToEbayFilterMode = $this->resolveFilterValue($form_state, 'published_to_ebay_filter', 'any');
    $storageLocationFilter = trim($this->resolveFilterValue($form_state, 'storage_location_filter', ''));
    $searchQuery = trim($this->resolveFilterValue($form_state, 'search_query', ''));
    $itemsPerPage = $this->resolveItemsPerPage($form_state);
    $datasetFilter = new AiListingBatchFilter(
      status: $statusFilter,
      bargainBinFilterMode: $bargainFilterMode,
      publishedToEbayFilterMode: $publishedToEbayFilterMode,
      searchQuery: $searchQuery,
      storageLocationFilter: $storageLocationFilter,
      itemsPerPage: $itemsPerPage,
      currentPage: $this->getCurrentPage(),
    );
    $dataset = $this->getBatchDatasetProvider()->buildDataset($datasetFilter);
    $this->getPagerManager()->createPager($dataset->filteredCount, $itemsPerPage);

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-filters']],
    ];

    $form['filters']['status_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Status filter'),
      '#options' => [
        'any' => $this->t('Any'),
        'new' => $this->t('New'),
        'processing' => $this->t('Processing'),
        'ready_for_review' => $this->t('Ready for review'),
        'ready_to_shelve' => $this->t('Ready to shelve'),
        'shelved' => $this->t('Shelved'),
        'failed' => $this->t('Failed'),
      ],
      '#default_value' => $statusFilter,
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['filters']['bargain_bin_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Bargain bin'),
      '#options' => [
        'any' => $this->t('Any'),
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $bargainFilterMode,
      '#description' => $this->t('Filter by bargain bin flag.'),
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['filters']['published_to_ebay_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Published to eBay'),
      '#options' => [
        'any' => $this->t('Any'),
        'yes' => $this->t('Yes'),
        'no' => $this->t('No'),
      ],
      '#default_value' => $publishedToEbayFilterMode,
      '#description' => $this->t('Filter by active eBay publication record.'),
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['filters']['search_query'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Search'),
      '#default_value' => $searchQuery,
      '#description' => $this->t('Match title, author, description, storage location, or SKU.'),
      '#attributes' => [
        'id' => 'ai-listing-search-query',
        'autocomplete' => 'off',
        'placeholder' => 'title, author, description, SKU, location',
      ],
    ];

    $form['filters']['storage_location_filter'] = [
      '#type' => 'select',
      '#title' => $this->t('Storage location'),
      '#options' => $dataset->storageLocationOptions,
      '#default_value' => $storageLocationFilter,
      '#description' => $this->t('Filter by storage location.'),
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['filters']['items_per_page'] = [
      '#type' => 'select',
      '#title' => $this->t('Items per page'),
      '#options' => [
        '25' => '25',
        '50' => '50',
        '100' => '100',
        '250' => '250',
      ],
      '#default_value' => (string) $itemsPerPage,
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['filters']['apply_filters'] = [
      '#type' => 'submit',
      '#name' => 'apply_filters',
      '#value' => $this->t('Apply filters'),
      '#limit_validation_errors' => [],
      '#submit' => ['::submitApplyFilters'],
      '#attributes' => [
        'id' => 'ai-listing-apply-filters',
      ],
      '#ajax' => [
        'callback' => '::updateListingsCallback',
        'wrapper' => 'ai-batch-listings',
      ],
    ];

    $form['listings_container'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-batch-listings'],
    ];

    $form['listings_container']['summary'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-listing-summary']],
    ];
    $form['listings_container']['summary']['total'] = [
      '#type' => 'markup',
      '#markup' => '<div><strong>' . $this->t('Total listings:') . '</strong> ' . $dataset->totalCount . '</div>',
    ];
    $form['listings_container']['summary']['filtered'] = [
      '#type' => 'markup',
      '#markup' => '<div><strong>' . $this->t('Matching current filters:') . '</strong> ' . $dataset->filteredCount . '</div>',
    ];
    $form['listings_container']['summary']['selected'] = [
      '#type' => 'markup',
      '#markup' => '<div><strong>' . $this->t('Selected:') . '</strong> <span id="ai-batch-selected-count">0</span></div>',
    ];
    $form['listings_container']['summary']['clear_selection'] = [
      '#type' => 'button',
      '#value' => $this->t('Clear selection'),
      '#attributes' => [
        'id' => 'ai-batch-clear-selection',
      ],
    ];

    $form['listings_container'][self::SELECTED_LISTING_KEYS_FIELD] = [
      '#type' => 'hidden',
      '#default_value' => $this->buildSelectedListingKeysPayload($form_state),
      '#attributes' => [
        'id' => 'ai-batch-selected-listing-keys',
      ],
    ];

    $form['listings_container']['listings'] = [
      '#type' => 'tableselect',
      '#header' => [
        'type' => $this->t('Type'),
        'entity_id' => $this->t('Entity ID'),
        'listing_code' => $this->t('Listing code'),
        'sku' => $this->t('SKU'),
        'status' => $this->t('Status'),
        'ebay' => $this->t('eBay'),
        'title' => $this->t('Title'),
        'author' => $this->t('Author'),
        'price' => $this->t('Price'),
        'location' => $this->t('Current location'),
        'published_to_ebay' => $this->t('Published to eBay'),
        'created' => $this->t('Created'),
      ],
      '#options' => $this->buildListingTableOptions($dataset->pagedRows),
      '#empty' => $this->t('No listings match the selected filters.'),
      '#multiple' => TRUE,
      '#default_value' => [],
    ];

    $form['listings_container']['pager'] = [
      '#type' => 'pager',
      '#parameters' => [
        'status_filter' => $statusFilter,
        'bargain_bin_filter' => $bargainFilterMode,
        'published_to_ebay_filter' => $publishedToEbayFilterMode,
        'storage_location_filter' => $storageLocationFilter,
        'search_query' => $searchQuery,
        'items_per_page' => (string) $itemsPerPage,
      ],
    ];

    $form['actions']['update_location'] = [
      '#type' => 'submit',
      '#name' => 'update_location',
      '#value' => $this->t('Update location'),
      '#button_type' => 'primary',
      '#submit' => ['::submitUpdateLocation'],
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

  public function submitApplyFilters(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->isApplyFiltersAction($form_state) || $this->isFilterDropdownAjaxAction($form_state)) {
      return;
    }

    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
      $form_state->setErrorByName('listings', $this->t('Select at least one listing to update.'));
      return;
    }

    if ($this->isSetLocationAndPublishAction($form_state)) {
      return;
    }
  }

  public function submitUpdateLocation(array &$form, FormStateInterface $form_state): void {
    $this->redirectToLocationConfirmation($form_state);
  }

  public function submitPublishOrUpdate(array &$form, FormStateInterface $form_state): void {
    $this->redirectToPublishUpdateConfirmation($form_state, FALSE, '', 'publish_update');
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->submitPublishOrUpdate($form, $form_state);
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

  private function queueListingBatch(FormStateInterface $form_state, bool $setLocation, string $location, string $operationMode): void {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
      $this->messenger()->addError($this->t('Select at least one listing to update.'));
      $form_state->setRebuild();
      return;
    }

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
      ->condition('status', ['published', 'publishing'], 'IN')
      ->range(0, 1)
      ->execute();

    return $publicationIds !== [];
  }

  private function isSetLocationAndPublishAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';
    return $triggerName === 'update_location';
  }

  private function isApplyFiltersAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';
    return $triggerName === 'apply_filters';
  }

  private function isFilterDropdownAjaxAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    if (!is_array($trigger)) {
      return FALSE;
    }

    $triggerName = $trigger['#name'] ?? '';
    if (in_array($triggerName, ['status_filter', 'bargain_bin_filter', 'published_to_ebay_filter'], TRUE)) {
      return TRUE;
    }

    $parents = $trigger['#parents'] ?? null;
    if (!is_array($parents) || $parents === []) {
      return FALSE;
    }

    return (string) $parents[0] === 'filters';
  }

  private function resolveFilterValue(FormStateInterface $form_state, string $key, string $default): string {
    $input = $form_state->getUserInput();
    if (isset($input[$key]) && is_scalar($input[$key])) {
      return trim((string) $input[$key]);
    }

    $value = $form_state->getValue($key);
    if ($value !== null && is_scalar($value)) {
      return trim((string) $value);
    }

    $queryValue = \Drupal::request()->query->get($key);
    if (is_string($queryValue)) {
      return trim($queryValue);
    }

    return $default;
  }

  private function redirectToPublishUpdateConfirmation(
    FormStateInterface $form_state,
    bool $setLocation,
    string $location,
    string $operationMode,
  ): void {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
      $this->messenger()->addError($this->t('Select at least one listing to update.'));
      $form_state->setRebuild();
      return;
    }

    $selectedIds = [];
    foreach ($selection as $item) {
      $selectedIds[] = (int) $item['id'];
    }

    $missingLocationIds = $this->findListingsMissingStorageLocation($selectedIds);

    $this->getConfirmTempStore()->set(self::PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY, [
      'listing_ids' => $selectedIds,
      'set_location' => $setLocation,
      'location' => $location,
      'operation_mode' => $operationMode,
      'missing_location_ids' => $missingLocationIds,
      'selected_count' => count($selectedIds),
      'missing_location_count' => count($missingLocationIds),
      'created_at' => time(),
    ]);

    $form_state->setRedirect('ai_listing.workbench.publish_update_confirm');
  }

  private function redirectToLocationConfirmation(FormStateInterface $form_state): void {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
      $this->messenger()->addError($this->t('Select at least one listing to update.'));
      $form_state->setRebuild();
      return;
    }

    $selectedIds = [];
    foreach ($selection as $item) {
      $selectedIds[] = (int) $item['id'];
    }

    $this->getConfirmTempStore()->set(self::LOCATION_CONFIRM_TEMPSTORE_KEY, [
      'listing_ids' => $selectedIds,
      'selected_count' => count($selectedIds),
      'created_at' => time(),
    ]);

    $form_state->setRedirect('ai_listing.workbench.location_confirm');
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

  private function resolveItemsPerPage(FormStateInterface $form_state): int {
    $value = $this->resolveFilterValue($form_state, 'items_per_page', '50');
    $itemsPerPage = (int) $value;

    if (!in_array($itemsPerPage, [25, 50, 100, 250], TRUE)) {
      return 50;
    }

    return $itemsPerPage;
  }

  /**
   * @param array<string,array{selection_key:string,listing_type:string,listing_id:int,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int,sku:string,is_published_to_ebay:bool,ebay_listing_id:?string}> $rows
   *
   * @return array<string,array<string,string|\Drupal\Component\Render\MarkupInterface>>
   */
  private function buildListingTableOptions(array $rows): array {
    $options = [];

    foreach ($rows as $selectionKey => $row) {
      $listing = $row['entity'] ?? NULL;
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $listingType = (string) ($row['listing_type'] ?? '');
      $listingId = (int) ($row['listing_id'] ?? 0);
      $label = $this->buildListingLabel($listingType, $listing);
      $link = Link::fromTextAndUrl(
        $label,
        Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $listingId])
      );

      $options[$selectionKey] = [
        'type' => $listingType === 'book_bundle' ? $this->t('Book bundle') : $this->t('Book'),
        'entity_id' => $listingId,
        'listing_code' => trim((string) ($listing->get('listing_code')->value ?? '')) ?: $this->t('Unset'),
        'sku' => $row['sku'] !== '' ? $row['sku'] : $this->t('—'),
        'status' => $listing->get('status')->value ?: $this->t('—'),
        'ebay' => $this->buildEbayItemLink($row['ebay_listing_id']),
        'title' => $link->toString(),
        'author' => $listingType === 'book'
          ? ($listing->get('field_author')->value ?: $this->t('Unknown'))
          : $this->t('—'),
        'price' => $listing->get('price')->value ?? $this->t('—'),
        'location' => $listing->get('storage_location')->value ?: $this->t('Unset yet'),
        'published_to_ebay' => !empty($row['is_published_to_ebay']) ? $this->t('Yes') : $this->t('No'),
        'created' => $this->getDateFormatter()->format((int) $row['created']),
      ];
    }

    return $options;
  }

  private function buildListingLabel(string $listingType, BbAiListing $listing): string {
    if ($listingType === 'book_bundle') {
      return (string) ($listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled bundle'));
    }

    return (string) ($listing->get('field_full_title')->value ?: $listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled listing'));
  }

  private function getCurrentPage(): int {
    return $this->getPagerParameters()->findPage();
  }

  private function buildEbayItemLink(?string $ebayListingId): string|MarkupInterface {
    if ($ebayListingId === NULL || $ebayListingId === '') {
      return (string) $this->t('—');
    }

    $url = Url::fromUri(
      'https://www.ebay.com.au/itm/' . rawurlencode($ebayListingId),
      ['attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]
    );

    return Link::fromTextAndUrl($this->t('View'), $url)->toString();
  }

  private function buildSelectionKey(string $listingType, int $id): string {
    return $this->getBatchSelectionManager()->buildSelectionKey($listingType, $id);
  }

  /**
   * @return array<int,array{listing_type:string,id:int}>
   */
  private function getSelectedListingRefs(FormStateInterface $form_state): array {
    return $this->getBatchSelectionManager()
      ->buildSelectionRefs($this->getSubmittedSelectedListingKeys($form_state));
  }

  /**
   * @return string[]
   */
  private function getSubmittedSelectedListingKeys(FormStateInterface $form_state): array {
    $submittedValue = $form_state->getValue(self::SELECTED_LISTING_KEYS_FIELD);
    $currentPageSelection = is_array($form_state->getValue('listings'))
      ? $form_state->getValue('listings')
      : [];

    return $this->getBatchSelectionManager()
      ->extractSelectionKeys($submittedValue, $currentPageSelection);
  }

  private function buildSelectedListingKeysPayload(FormStateInterface $form_state): string {
    return $this->getBatchSelectionManager()
      ->encodeSelectionKeys($this->getSubmittedSelectedListingKeys($form_state));
  }

  private function getEntityTypeManager(): EntityTypeManagerInterface {
    if ($this->entityTypeManager === null) {
      $this->entityTypeManager = \Drupal::entityTypeManager();
    }
    return $this->entityTypeManager;
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

    return $this->tempStoreFactory->get(self::WORKBENCH_TEMPSTORE_COLLECTION);
  }

  private function getPagerManager(): PagerManagerInterface {
    if ($this->pagerManager === null) {
      $this->pagerManager = \Drupal::service('pager.manager');
    }

    return $this->pagerManager;
  }

  private function getPagerParameters(): PagerParametersInterface {
    if ($this->pagerParameters === null) {
      $this->pagerParameters = \Drupal::service('pager.parameters');
    }

    return $this->pagerParameters;
  }

  private function getBatchDatasetProvider(): AiListingBatchDatasetProvider {
    if ($this->batchDatasetProvider === null) {
      $this->batchDatasetProvider = \Drupal::service('ai_listing.batch_dataset_provider');
    }

    return $this->batchDatasetProvider;
  }

  private function getBatchSelectionManager(): AiListingBatchSelectionManager {
    if ($this->batchSelectionManager === null) {
      $this->batchSelectionManager = \Drupal::service('ai_listing.batch_selection_manager');
    }

    return $this->batchSelectionManager;
  }

}
