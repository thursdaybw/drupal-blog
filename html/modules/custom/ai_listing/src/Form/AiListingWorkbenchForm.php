<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\TempStore\PrivateTempStore;
use Drupal\compute_orchestrator\Exception\AcquirePendingException;
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

/**
 * Builds the AI listing workbench form.
 */
final class AiListingWorkbenchForm extends FormBase implements ContainerInjectionInterface {
  public const WORKBENCH_TEMPSTORE_COLLECTION = 'ai_listing.workbench';
  public const PUBLISH_UPDATE_CONFIRM_TEMPSTORE_KEY = 'publish_update_confirmation';
  public const LOCATION_CONFIRM_TEMPSTORE_KEY = 'location_confirmation';
  public const SELECTED_LISTING_KEYS_FIELD = 'selected_listing_keys';

  /**
   * Cached entity type manager service.
   */
  private ?EntityTypeManagerInterface $entityTypeManager = NULL;
  /**
   * Cached date formatter service.
   */
  private ?DateFormatterInterface $dateFormatter = NULL;
  /**
   * Cached private tempstore factory service.
   */
  private ?PrivateTempStoreFactory $tempStoreFactory = NULL;
  /**
   * Cached pager manager service.
   */
  private ?PagerManagerInterface $pagerManager = NULL;
  /**
   * Cached pager parameters service.
   */
  private ?PagerParametersInterface $pagerParameters = NULL;
  /**
   * Cached batch dataset provider service.
   */
  private ?AiListingBatchDatasetProvider $batchDatasetProvider = NULL;
  /**
   * Cached batch selection manager service.
   */
  private ?AiListingBatchSelectionManager $batchSelectionManager = NULL;

  /**
   * Cached listing type labels keyed by bundle.
   *
   * @var array<string,string>|null
   */
  private ?array $listingTypeLabels = NULL;

  /**
   * Creates the workbench form instance.
   */
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

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'ai_listing_workbench_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $statusFilter = $this->resolveFilterValue($form_state, 'status_filter', 'any');
    $bargainFilterMode = $this->resolveFilterValue($form_state, 'bargain_bin_filter', 'any');
    $publishedToEbayFilterMode = $this->resolveFilterValue($form_state, 'published_to_ebay_filter', 'any');
    $storageLocationFilter = trim($this->resolveFilterValue($form_state, 'storage_location_filter', ''));
    $searchQuery = trim($this->resolveFilterValue($form_state, 'search_query', ''));
    $itemsPerPage = $this->resolveItemsPerPage($form_state);
    $sortField = $this->resolveSortField($form_state);
    $sortDirection = $this->resolveSortDirection($form_state);
    $datasetFilter = new AiListingBatchFilter(
      status: $statusFilter,
      bargainBinFilterMode: $bargainFilterMode,
      publishedToEbayFilterMode: $publishedToEbayFilterMode,
      searchQuery: $searchQuery,
      storageLocationFilter: $storageLocationFilter,
      itemsPerPage: $itemsPerPage,
      currentPage: $this->getCurrentPage(),
      sortField: $sortField,
      sortDirection: $sortDirection,
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
        'ready_for_image_selection' => $this->t('Ready for image selection'),
        'ready_for_inference' => $this->t('Ready for inference'),
        'processing' => $this->t('Processing'),
        'ready_for_review' => $this->t('Ready for review'),
        'ready_to_shelve' => $this->t('Ready to shelve'),
        'ready_to_publish' => $this->t('Ready to publish'),
        'shelved' => $this->t('Shelved'),
        'archived' => $this->t('Archived'),
        'lost' => $this->t('Lost'),
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
      '#markup' => (string) $this->t('<div><strong>@label</strong> <span id="ai-batch-selected-count">0</span></div>', [
        '@label' => $this->t('Selected:'),
      ]),
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

    $pagerParameters = [
      'status_filter' => $statusFilter,
      'bargain_bin_filter' => $bargainFilterMode,
      'published_to_ebay_filter' => $publishedToEbayFilterMode,
      'storage_location_filter' => $storageLocationFilter,
      'search_query' => $searchQuery,
      'items_per_page' => (string) $itemsPerPage,
      'sort' => $sortField,
      'order' => $sortDirection,
    ];

    $form['listings_container']['pager_top'] = [
      '#type' => 'pager',
      '#parameters' => $pagerParameters,
    ];

    $form['listings_container']['desktop_table'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-desktop-table']],
    ];

    $form['listings_container']['desktop_table']['listings'] = [
      '#type' => 'tableselect',
      '#header' => $this->buildListingTableHeader($sortField, $sortDirection, $pagerParameters),
      '#options' => $this->buildListingTableOptions($dataset->pagedRows),
      '#empty' => $this->t('No listings match the selected filters.'),
      '#multiple' => TRUE,
      '#default_value' => [],
    ];

    $form['listings_container']['mobile_cards'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-mobile-cards']],
      'items' => $this->buildMobileListingCards($dataset->pagedRows),
    ];

    $form['listings_container']['pager_bottom'] = [
      '#type' => 'pager',
      '#parameters' => $pagerParameters,
    ];

    $form['actions'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-actions']],
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
    $form['actions']['run_ai_inference_ready'] = [
      '#type' => 'submit',
      '#name' => 'run_ai_inference_ready',
      '#value' => $this->t('Run AI inference (ready)'),
      '#submit' => ['::submitRunAiInferenceReady'],
    ];

    $form['#attached']['library'][] = 'ai_listing/location_table';

    return $form;
  }

  /**
   * Ajax callback for refreshing the listings container.
   */
  public function updateListingsCallback(array &$form, FormStateInterface $form_state): array {
    return $form['listings_container'];
  }

  /**
   * Rebuilds the form after filter submission.
   */
  public function submitApplyFilters(array &$form, FormStateInterface $form_state): void {
    $form_state->setRebuild();
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if ($this->isApplyFiltersAction($form_state)
      || $this->isFilterDropdownAjaxAction($form_state)
      || $this->isRunAiInferenceReadyAction($form_state)) {
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

  /**
   * Starts the location-update confirmation flow.
   */
  public function submitUpdateLocation(array &$form, FormStateInterface $form_state): void {
    $this->redirectToLocationConfirmation($form_state);
  }

  /**
   * Starts the publish-or-update confirmation flow.
   */
  public function submitPublishOrUpdate(array &$form, FormStateInterface $form_state): void {
    $this->redirectToPublishUpdateConfirmation($form_state, FALSE, '', 'publish_update');
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->submitPublishOrUpdate($form, $form_state);
  }

  /**
   * Starts the delete flow for selected listings.
   */
  public function submitDeleteSelected(array &$form, FormStateInterface $form_state): void {
    $selection = $this->getSelectedListingRefs($form_state);
    if ($selection === []) {
      $this->messenger()->addError($this->t('Select at least one listing to delete.'));
      $form_state->setRebuild();
      return;
    }

    batch_set(self::buildDeleteBatchDefinition($selection));
  }

  /**
   * Starts a batch run for listings currently ready for inference.
   */
  public function submitRunAiInferenceReady(array &$form, FormStateInterface $form_state): void {
    $preflightError = $this->validateInferenceRuntimePreflight();
    if ($preflightError !== NULL) {
      $this->messenger()->addError($preflightError);
      $form_state->setRebuild();
      return;
    }

    batch_set(self::buildInferenceBatchDefinition());
  }

  /**
   * Builds the publish/update batch definition.
   *
   * @param array<int,array{entity_type:string,id:int}|int|string> $selection
   *   Selected listing references.
   * @param bool $setLocation
   *   Whether to set a storage location first.
   * @param string $location
   *   The storage location string.
   * @param string $operationMode
   *   The batch operation mode.
   * @param int $locationTermId
   *   The storage-location term id.
   *
   * @return array<string,mixed>
   *   The Drupal batch definition.
   */
  public static function buildListingBatchDefinition(array $selection, bool $setLocation, string $location, string $operationMode = 'publish', int $locationTermId = 0): array {
    $operations = [];
    foreach ($selection as $item) {
      if (is_int($item) || (is_string($item) && ctype_digit($item))) {
        $item = [
          'listing_type' => self::resolveListingTypeForBatchItem((int) $item),
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
        [$item['listing_type'], (int) $item['id'], $setLocation, $location, $operationMode, $locationTermId],
      ];
    }

    $translation = \Drupal::translation();

    return [
      'title' => match ($operationMode) {
        'location_only' => $translation->translate('Updating listing locations'),
        'publish_update' => $setLocation
          ? $translation->translate('Setting locations and publishing listings')
          : $translation->translate('Publishing/updating listings'),
        default => $setLocation
          ? $translation->translate('Shelving and publishing listings')
          : $translation->translate('Publishing listings'),
      },
      'operations' => $operations,
      'finished' => [self::class, 'finishBatchOperation'],
      'init_message' => match ($operationMode) {
        'location_only' => $translation->translate('Starting location update batch...'),
        'publish_update' => $setLocation
          ? $translation->translate('Starting location update and publish batch...')
          : $translation->translate('Starting publish/update batch...'),
        default => $setLocation
          ? $translation->translate('Starting shelf and publish batch...')
          : $translation->translate('Starting publish batch...'),
      },
      'progress_message' => $translation->translate('Processed @current of @total listings.'),
      'error_message' => $translation->translate('The batch finished with an unexpected error.'),
    ];
  }

  /**
   * Processes one publish/update batch operation.
   */
  public static function processBatchOperation(string $listingType, int $listingId, bool $setLocation, string $location, string $operationMode, int $locationTermId, array &$context): void {
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
      $context['message'] = (string) \Drupal::translation()->translate(
        'Skipping missing listing @id.',
        ['@id' => $listingId],
      );
      return;
    }

    if ($setLocation) {
      $listing->set('storage_location', $location);
      $listing->set('storage_location_term', $locationTermId > 0 ? ['target_id' => $locationTermId] : NULL);
    }

    if ($operationMode === 'location_only') {
      $listing->set('status', 'ready_to_publish');
      $listing->save();
      $context['results']['success']++;
      $context['message'] = (string) \Drupal::translation()->translate(
        'Updated location for listing @id: @title',
        [
          '@id' => $listingId,
          '@title' => $listing->label() ?: 'Untitled',
        ]
      );
      return;
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
      $context['message'] = (string) \Drupal::translation()->translate(
        'Failed listing @id.',
        ['@id' => $listingId],
      );
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
      $context['message'] = (string) \Drupal::translation()->translate(
        'Failed listing @id.',
        ['@id' => $listingId],
      );
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

  /**
   * Finishes the publish/update batch operation.
   */
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
      if ($operationMode === 'location_only') {
        $singular = 'Updated location for one listing.';
        $plural = 'Updated location for @count listings.';
      }
      elseif ($operationMode === 'publish_update') {
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
   * Builds the delete batch definition.
   *
   * @param array<int,array{listing_type:string,id:int}> $selection
   *   Selected listing references.
   *
   * @return array<string,mixed>
   *   The Drupal batch definition.
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

  /**
   * Processes one delete batch operation.
   */
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
      $context['message'] = (string) \Drupal::translation()->translate(
        'Skipping missing listing @type:@id.',
        [
          '@type' => $listingType,
          '@id' => $listingId,
        ],
      );
      return;
    }

    $title = $listing->label() ?: 'Untitled';

    if (self::listingHasInventoryAndMarketplaceData($listingId)) {
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
      $context['message'] = (string) \Drupal::translation()->translate(
        'Failed delete for listing @type:@id.',
        [
          '@type' => $listingType,
          '@id' => $listingId,
        ],
      );
    }
  }

  /**
   * Finishes the delete batch operation.
   */
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

  /**
   * Builds a batch definition for ready-for-inference processing.
   *
   * @return array<string,mixed>
   *   Batch definition suitable for Drupal batch_set().
   */
  public static function buildInferenceBatchDefinition(): array {
    $translation = \Drupal::translation();
    /** @var \Drupal\ai_listing_inference\Service\AiBookListingBatchDataExtractionProcessor $batchProcessor */
    $batchProcessor = \Drupal::service('ai_listing_inference.batch_data_extraction_processor');
    $listingIds = array_values(array_map('intval', $batchProcessor->getReadyForInferenceListingIds()));
    $operations = [
      [
        [self::class, 'processInferenceAcquireLeaseBatchOperation'],
        [$listingIds],
      ],
    ];

    foreach ($listingIds as $index => $listingId) {
      $operations[] = [
        [self::class, 'processInferenceListingBatchOperation'],
        [$listingId, $index + 1, count($listingIds)],
      ];
    }

    $operations[] = [
      [self::class, 'processInferenceReleaseLeaseBatchOperation'],
      [],
    ];

    return [
      'title' => $translation->translate('Running AI inference'),
      'operations' => $operations,
      'finished' => [self::class, 'finishInferenceBatchOperation'],
      'init_message' => $translation->translate('Starting AI inference batch...'),
      'progress_message' => $translation->translate('Processed @current of @total operations.'),
      'error_message' => $translation->translate('AI inference batch finished with an unexpected error.'),
    ];
  }

  /**
   * Batch operation: acquire qwen-vl lease before listing work starts.
   */
  public static function processInferenceAcquireLeaseBatchOperation(array $listingIds, array &$context): void {
    $context['results']['processed'] = (int) ($context['results']['processed'] ?? 0);
    $context['results']['failed'] = (int) ($context['results']['failed'] ?? 0);
    $context['results']['total'] = (int) ($context['results']['total'] ?? count($listingIds));
    $context['results']['errors'] = (array) ($context['results']['errors'] ?? []);
    $context['results']['lease_contract_id'] = (string) ($context['results']['lease_contract_id'] ?? '');
    $context['results']['lease_released'] = (bool) ($context['results']['lease_released'] ?? FALSE);
    $context['results']['acquire_failed'] = (bool) ($context['results']['acquire_failed'] ?? FALSE);
    $context['results']['aborted_early'] = (bool) ($context['results']['aborted_early'] ?? FALSE);
    $context['results']['renew_counter'] = (int) ($context['results']['renew_counter'] ?? 0);

    $sandbox = &$context['sandbox'];
    $sandbox['acquire_attempts'] = (int) ($sandbox['acquire_attempts'] ?? 0);
    $total = count($listingIds);
    if ($total === 0) {
      $context['message'] = (string) t('No listings are currently ready for inference.');
      return;
    }

    /** @var \Drupal\compute_orchestrator\Service\VllmPoolManager $poolManager */
    $poolManager = \Drupal::service('compute_orchestrator.vllm_pool_manager');

    try {
      $record = $poolManager->acquire('qwen-vl', NULL, TRUE, 25, 25);
      $contractId = trim((string) ($record['contract_id'] ?? ''));
      if ($contractId === '') {
        throw new \RuntimeException('Pool acquire did not return a contract ID.');
      }
      $context['results']['lease_contract_id'] = $contractId;
      $context['message'] = (string) t(
        'Lease acquired for @count listing(s): @contract',
        [
          '@count' => (string) $total,
          '@contract' => $contractId,
        ]
      );
    }
    catch (AcquirePendingException $exception) {
      $sandbox['acquire_attempts']++;
      $context['message'] = (string) t(
        'Waiting for pooled runtime warmup (attempt @attempt): @message',
        [
          '@attempt' => (string) $sandbox['acquire_attempts'],
          '@message' => $exception->getMessage(),
        ]
      );
      $context['finished'] = 0;
    }
    catch (\Throwable $exception) {
      $context['results']['failed'] = $total;
      $context['results']['acquire_failed'] = TRUE;
      $context['results']['errors'][] = 'Could not acquire qwen-vl lease from pool: ' . $exception->getMessage();
      $context['results']['errors'][] = 'Check /admin/compute-orchestrator/vllm-pool for last error details.';
      $context['message'] = (string) t('Inference stopped: lease acquisition failed.');
    }
  }

  /**
   * Batch operation: process one listing under the active pooled lease.
   */
  public static function processInferenceListingBatchOperation(int $listingId, int $position, int $total, array &$context): void {
    $context['results']['processed'] = (int) ($context['results']['processed'] ?? 0);
    $context['results']['failed'] = (int) ($context['results']['failed'] ?? 0);
    $context['results']['total'] = (int) ($context['results']['total'] ?? $total);
    $context['results']['errors'] = (array) ($context['results']['errors'] ?? []);
    $context['results']['lease_contract_id'] = (string) ($context['results']['lease_contract_id'] ?? '');
    $context['results']['lease_released'] = (bool) ($context['results']['lease_released'] ?? FALSE);
    $context['results']['acquire_failed'] = (bool) ($context['results']['acquire_failed'] ?? FALSE);
    $context['results']['aborted_early'] = (bool) ($context['results']['aborted_early'] ?? FALSE);
    $context['results']['renew_counter'] = (int) ($context['results']['renew_counter'] ?? 0);

    if ($context['results']['acquire_failed'] || $context['results']['aborted_early']) {
      return;
    }

    $contractId = trim((string) $context['results']['lease_contract_id']);
    if ($contractId === '') {
      $context['results']['failed']++;
      $context['results']['errors'][] = 'Listing ' . $listingId . ' was skipped because no lease was available.';
      return;
    }

    /** @var \Drupal\compute_orchestrator\Service\VllmPoolManager $poolManager */
    $poolManager = \Drupal::service('compute_orchestrator.vllm_pool_manager');
    /** @var \Drupal\ai_listing_inference\Service\AiBookListingBatchDataExtractionProcessor $batchProcessor */
    $batchProcessor = \Drupal::service('ai_listing_inference.batch_data_extraction_processor');

    $context['message'] = (string) t(
      'Running inference for listing @current of @total...',
      [
        '@current' => (string) $position,
        '@total' => (string) $total,
      ]
    );

    $listing = $batchProcessor->loadListing($listingId);
    if ($listing === NULL) {
      $context['results']['failed']++;
      $context['results']['errors'][] = 'Listing ' . $listingId . ' was not found.';
    }
    else {
      try {
        \Drupal::logger('ai_listing_inference')->notice(
          'Batch listing inference started for listing @listing_id (@position/@total).',
          [
            '@listing_id' => $listingId,
            '@position' => $position,
            '@total' => $total,
          ]
        );
        $batchProcessor->processListing($listing);
        $context['results']['processed']++;
        \Drupal::logger('ai_listing_inference')->notice(
          'Batch listing inference completed for listing @listing_id (@position/@total).',
          [
            '@listing_id' => $listingId,
            '@position' => $position,
            '@total' => $total,
          ]
        );
      }
      catch (\Throwable $exception) {
        $context['results']['failed']++;
        $context['results']['errors'][] = 'Listing ' . $listingId . ' inference failed: ' . $exception->getMessage();
        $isConnectivityFailure = self::isInferenceConnectivityFailure($exception);
        \Drupal::logger('ai_listing_inference')->error(sprintf(
          'Batch listing inference failed for listing %d (%d/%d). class=%s message=%s connectivity_failure=%s trace=%s',
          $listingId,
          $position,
          $total,
          $exception::class,
          $exception->getMessage(),
          $isConnectivityFailure ? 'yes' : 'no',
          $exception->getTraceAsString(),
        ));
        if ($isConnectivityFailure) {
          $context['results']['aborted_early'] = TRUE;
          $context['results']['errors'][] = 'Aborting remaining listings because VLM connectivity failed.';
          \Drupal::logger('ai_listing_inference')->error(sprintf(
            'Batch listing inference is aborting remaining listings after listing %d because a connectivity failure was detected.',
            $listingId,
          ));
        }
      }
    }

    $context['results']['renew_counter'] = (int) $context['results']['renew_counter'] + 1;
    if ($context['results']['renew_counter'] >= 5) {
      try {
        $poolManager->renewLease($contractId);
      }
      catch (\Throwable $exception) {
        $context['results']['errors'][] = 'Failed to renew lease ' . $contractId . ': ' . $exception->getMessage();
      }
      $context['results']['renew_counter'] = 0;
    }
  }

  /**
   * Batch operation: release the pooled lease when listing work completes.
   */
  public static function processInferenceReleaseLeaseBatchOperation(array &$context): void {
    $context['results']['lease_contract_id'] = (string) ($context['results']['lease_contract_id'] ?? '');
    $context['results']['lease_released'] = (bool) ($context['results']['lease_released'] ?? FALSE);
    $context['results']['errors'] = (array) ($context['results']['errors'] ?? []);

    $contractId = trim((string) $context['results']['lease_contract_id']);
    if ($contractId !== '') {
      /** @var \Drupal\compute_orchestrator\Service\VllmPoolManager $poolManager */
      $poolManager = \Drupal::service('compute_orchestrator.vllm_pool_manager');
      try {
        $poolManager->release($contractId);
        $context['results']['lease_released'] = TRUE;
      }
      catch (\Throwable $exception) {
        $context['results']['errors'][] = 'Failed to release lease ' . $contractId . ': ' . $exception->getMessage();
      }
    }

    $context['message'] = (string) t('Inference batch completed.');
  }

  /**
   * Batch finish callback for AI inference processing.
   */
  public static function finishInferenceBatchOperation(bool $success, array $results, array $operations): void {
    $messenger = \Drupal::messenger();
    $translation = \Drupal::translation();

    if (!empty($results['lease_contract_id']) && empty($results['lease_released'])) {
      $messenger->addWarning((string) $translation->translate(
        'Inference lease @contract may still be held; please check pool state.',
        ['@contract' => (string) $results['lease_contract_id']]
      ));
    }

    if (!$success) {
      $messenger->addError((string) $translation->translate('The AI inference batch did not complete successfully.'));
    }

    $processedCount = (int) ($results['processed'] ?? 0);
    $failedCount = (int) ($results['failed'] ?? 0);
    if ($processedCount > 0) {
      $messenger->addStatus($translation->formatPlural(
        $processedCount,
        'Processed inference for one listing.',
        'Processed inference for @count listings.'
      ));
    }
    if ($failedCount > 0) {
      $messenger->addError($translation->formatPlural(
        $failedCount,
        'One listing failed inference.',
        '@count listings failed inference.'
      ));
    }
    if (!empty($results['errors'])) {
      $messenger->addWarning((string) $translation->translate(
        'For infrastructure-level details, review /admin/compute-orchestrator/vllm-pool.'
      ));
    }

    foreach (($results['errors'] ?? []) as $error) {
      $messenger->addError($error);
    }
  }

  /**
   * Detects connectivity failures that justify aborting remaining listings.
   */
  private static function isInferenceConnectivityFailure(\Throwable $error): bool {
    $message = strtolower($error->getMessage());
    foreach ([
      'curl error 28',
      'failed to connect',
      'connection refused',
      'could not resolve host',
      'operation timed out',
      'vllm not configured',
      '/v1/chat/completions',
    ] as $pattern) {
      if (str_contains($message, $pattern)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Checks whether a listing has inventory and marketplace data.
   */
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

  /**
   * Determines whether the current submit updates location for publishing.
   */
  private function isSetLocationAndPublishAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';
    return $triggerName === 'update_location';
  }

  /**
   * Determines whether the current submit is the run-inference action.
   */
  private function isRunAiInferenceReadyAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';
    return $triggerName === 'run_ai_inference_ready';
  }

  /**
   * Determines whether the current submit applies filters.
   */
  private function isApplyFiltersAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    $triggerName = $trigger['#name'] ?? '';
    return $triggerName === 'apply_filters';
  }

  /**
   * Determines whether the current submit is a filter AJAX action.
   */
  private function isFilterDropdownAjaxAction(FormStateInterface $form_state): bool {
    $trigger = $form_state->getTriggeringElement();
    if (!is_array($trigger)) {
      return FALSE;
    }

    $triggerName = $trigger['#name'] ?? '';
    if (in_array($triggerName, ['status_filter', 'bargain_bin_filter', 'published_to_ebay_filter'], TRUE)) {
      return TRUE;
    }

    $parents = $trigger['#parents'] ?? NULL;
    if (!is_array($parents) || $parents === []) {
      return FALSE;
    }

    return (string) $parents[0] === 'filters';
  }

  /**
   * Validates runtime prerequisites before launching inference batch.
   */
  private function validateInferenceRuntimePreflight(): ?string {
    $sshKeyPath = trim((string) (getenv('VAST_SSH_PRIVATE_KEY_CONTAINER_PATH') ?: ''));
    if ($sshKeyPath === '' || !is_readable($sshKeyPath)) {
      return (string) $this->t(
        'Vast SSH key is not readable in app runtime. Set VAST_SSH_PRIVATE_KEY_CONTAINER_PATH to a readable private key inside the container.'
      );
    }

    return NULL;
  }

  /**
   * Resolves a filter value from submitted form input.
   */
  private function resolveFilterValue(FormStateInterface $form_state, string $key, string $default): string {
    $input = $form_state->getUserInput();
    if (isset($input[$key]) && is_scalar($input[$key])) {
      return trim((string) $input[$key]);
    }

    $value = $form_state->getValue($key);
    if ($value !== NULL && is_scalar($value)) {
      return trim((string) $value);
    }

    $queryValue = $this->requestStack?->getCurrentRequest()?->query->get($key);
    if (is_string($queryValue)) {
      return trim($queryValue);
    }

    return $default;
  }

  /**
   * Redirects to the publish/update confirmation flow.
   */
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
      'selection' => $selection,
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

  /**
   * Redirects to the location confirmation flow.
   */
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
      'selection' => $selection,
      'listing_ids' => $selectedIds,
      'selected_count' => count($selectedIds),
      'created_at' => time(),
    ]);

    $form_state->setRedirect('ai_listing.workbench.location_confirm');
  }

  /**
   * Finds selected listings that do not yet have a storage location.
   */
  private function findListingsMissingStorageLocation(array $listingIds): array {
    if ($listingIds === []) {
      return [];
    }

    $storage = $this->getEntityTypeManager()->getStorage('bb_ai_listing');
    $listings = $storage->loadMultiple($listingIds);
    $missingIds = [];

    foreach ($listingIds as $listingId) {
      $listing = $listings[$listingId] ?? NULL;
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

  /**
   * Resolves the selected items-per-page value.
   */
  private function resolveItemsPerPage(FormStateInterface $form_state): int {
    $value = $this->resolveFilterValue($form_state, 'items_per_page', '50');
    $itemsPerPage = (int) $value;

    if (!in_array($itemsPerPage, [25, 50, 100, 250], TRUE)) {
      return 50;
    }

    return $itemsPerPage;
  }

  /**
   * Resolves the selected sort field.
   */
  private function resolveSortField(FormStateInterface $form_state): string {
    $sortField = trim($this->resolveFilterValue($form_state, 'sort', 'created'));
    $allowedSortFields = [
      'type',
      'entity_id',
      'listing_code',
      'sku',
      'status',
      'title',
      'author',
      'price',
      'location',
      'published_to_ebay',
      'created',
    ];

    if (!in_array($sortField, $allowedSortFields, TRUE)) {
      return 'created';
    }

    return $sortField;
  }

  /**
   * Resolves the selected sort direction.
   */
  private function resolveSortDirection(FormStateInterface $form_state): string {
    $direction = strtolower(trim($this->resolveFilterValue($form_state, 'order', 'asc')));
    return $direction === 'desc' ? 'desc' : 'asc';
  }

  /**
   * Builds the listing table header.
   *
   * @param string $sortField
   *   The active sort field.
   * @param string $sortDirection
   *   The active sort direction.
   * @param array<string,string> $baseParameters
   *   Base query parameters to preserve in sort links.
   *
   * @return array<string,\Drupal\Core\StringTranslation\TranslatableMarkup|string|\Drupal\Component\Render\MarkupInterface>
   *   Table header values keyed by column.
   */
  private function buildListingTableHeader(string $sortField, string $sortDirection, array $baseParameters): array {
    return [
      'type' => $this->buildSortableHeaderCell($this->t('Type'), 'type', $sortField, $sortDirection, $baseParameters),
      'entity_id' => $this->buildSortableHeaderCell($this->t('Entity ID'), 'entity_id', $sortField, $sortDirection, $baseParameters),
      'listing_code' => $this->buildSortableHeaderCell($this->t('Listing code'), 'listing_code', $sortField, $sortDirection, $baseParameters),
      'sku' => $this->buildSortableHeaderCell($this->t('SKU'), 'sku', $sortField, $sortDirection, $baseParameters),
      'status' => $this->buildSortableHeaderCell($this->t('Status'), 'status', $sortField, $sortDirection, $baseParameters),
      'ebay' => $this->t('eBay'),
      'title' => $this->buildSortableHeaderCell($this->t('Title'), 'title', $sortField, $sortDirection, $baseParameters),
      'author' => $this->buildSortableHeaderCell($this->t('Author'), 'author', $sortField, $sortDirection, $baseParameters),
      'price' => $this->buildSortableHeaderCell($this->t('Price'), 'price', $sortField, $sortDirection, $baseParameters),
      'location' => $this->buildSortableHeaderCell($this->t('Current location'), 'location', $sortField, $sortDirection, $baseParameters),
      'published_to_ebay' => $this->buildSortableHeaderCell($this->t('Published to eBay'), 'published_to_ebay', $sortField, $sortDirection, $baseParameters),
      'created' => $this->buildSortableHeaderCell($this->t('Created'), 'created', $sortField, $sortDirection, $baseParameters),
    ];
  }

  /**
   * Builds a sortable table header cell.
   *
   * @param string|\Stringable $label
   *   The header label.
   * @param string $field
   *   The field key for sorting.
   * @param string $currentSortField
   *   The currently active sort field.
   * @param string $currentSortDirection
   *   The currently active sort direction.
   * @param array<string,string> $baseParameters
   *   Base query parameters to preserve.
   *
   * @return string|\Drupal\Component\Render\MarkupInterface
   *   The rendered sortable header cell.
   */
  private function buildSortableHeaderCell(
    string|\Stringable $label,
    string $field,
    string $currentSortField,
    string $currentSortDirection,
    array $baseParameters,
  ): string|MarkupInterface {
    $labelText = (string) $label;
    $isCurrentSortField = $field === $currentSortField;
    $nextDirection = 'asc';
    if ($isCurrentSortField && $currentSortDirection === 'asc') {
      $nextDirection = 'desc';
    }

    $sortSuffix = '';
    if ($isCurrentSortField) {
      $sortSuffix = $currentSortDirection === 'asc' ? ' ↑' : ' ↓';
    }

    $query = $baseParameters;
    $query['sort'] = $field;
    $query['order'] = $nextDirection;
    unset($query['page']);

    return Link::fromTextAndUrl(
      $labelText . $sortSuffix,
      Url::fromRoute('ai_listing.workbench', [], ['query' => $query])
    )->toString();
  }

  /**
   * Builds table options for listing rows.
   *
   * @param array<string,array{selection_key:string,listing_type:string,listing_id:int,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int,sku:string,is_published_to_ebay:bool,ebay_listing_id:?string}> $rows
   *   Listing rows keyed by selection key.
   *
   * @return array<string,array<string,string|\Drupal\Component\Render\MarkupInterface>>
   *   Table options keyed by selection key.
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
        'type' => $this->buildTypeLabel($listingType),
        'entity_id' => $listingId,
        'listing_code' => trim((string) ($listing->get('listing_code')->value ?? '')) ?: $this->t('Unset'),
        'sku' => $row['sku'] !== '' ? $row['sku'] : $this->t('—'),
        'status' => $listing->get('status')->value ?: $this->t('—'),
        'ebay' => $this->buildEbayItemLink($row['ebay_listing_id']),
        'title' => $link->toString(),
        'author' => $listingType === 'book'
          ? ($this->getStringFieldValueIfExists($listing, 'field_author') ?: $this->t('Unknown'))
          : $this->t('—'),
        'price' => $listing->get('price')->value ?? $this->t('—'),
        'location' => $listing->get('storage_location')->value ?: $this->t('Unset yet'),
        'published_to_ebay' => !empty($row['is_published_to_ebay']) ? $this->t('Yes') : $this->t('No'),
        'created' => $this->getDateFormatter()->format((int) $row['created']),
      ];
    }

    return $options;
  }

  /**
   * Builds mobile card render arrays for listing rows.
   *
   * @param array<string,array{selection_key:string,listing_type:string,listing_id:int,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int,sku:string,is_published_to_ebay:bool,ebay_listing_id:?string}> $rows
   *   Listing rows keyed by selection key.
   *
   * @return array<string,array<string,mixed>>
   *   Mobile card render arrays keyed by selection key.
   */
  private function buildMobileListingCards(array $rows): array {
    $cards = [];
    $thumbnailLookup = $this->buildListingThumbnailUriLookup($rows);

    foreach ($rows as $selectionKey => $row) {
      $listing = $row['entity'] ?? NULL;
      if (!$listing instanceof BbAiListing) {
        continue;
      }

      $listingId = (int) ($row['listing_id'] ?? 0);
      $listingType = (string) ($row['listing_type'] ?? '');
      $author = $listingType === 'book'
        ? ($this->getStringFieldValueIfExists($listing, 'field_author') ?: $this->t('Unknown'))
        : $this->t('—');
      $thumbnailUri = $thumbnailLookup[$listingId] ?? NULL;

      $cards[$selectionKey] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['ai-batch-mobile-card'],
        ],
        'header' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-batch-mobile-card__header']],
          'select' => [
            '#type' => 'checkbox',
            '#title' => '',
            '#attributes' => [
              'class' => ['ai-batch-mobile-card__check'],
              'data-ai-selection-key' => $selectionKey,
              'aria-label' => 'Select listing ' . $listingId,
            ],
          ],
          'thumb' => $this->buildMobileThumbnail($thumbnailUri),
          'identity' => [
            '#type' => 'container',
            'title' => [
              '#type' => 'html_tag',
              '#tag' => 'div',
              '#attributes' => ['class' => ['ai-batch-mobile-card__title']],
              'link' => Link::fromTextAndUrl(
                $this->buildListingLabel($listingType, $listing),
                Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $listingId])
              )->toRenderable(),
            ],
          ],
        ],
        'meta' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-batch-mobile-card__meta']],
          'type' => $this->buildMobileMetaItem('Type', $this->buildTypeLabel($listingType)),
          'entity_id' => $this->buildMobileMetaItem('Entity ID', (string) $listingId),
          'listing_code' => $this->buildMobileMetaItem('Listing code', trim((string) ($listing->get('listing_code')->value ?? '')) ?: 'Unset'),
          'sku' => $this->buildMobileMetaItem('SKU', $row['sku'] !== '' ? $row['sku'] : '—'),
          'status' => $this->buildMobileMetaItem('Status', (string) ($listing->get('status')->value ?: '—')),
          'author' => $this->buildMobileMetaItem('Author', (string) $author),
          'price' => $this->buildMobileMetaItem('Price', (string) ($listing->get('price')->value ?? '—')),
          'location' => $this->buildMobileMetaItem('Location', (string) ($listing->get('storage_location')->value ?: 'Unset yet')),
          'published' => $this->buildMobileMetaItem('Published to eBay', !empty($row['is_published_to_ebay']) ? 'Yes' : 'No'),
          'created' => $this->buildMobileMetaItem('Created', $this->getDateFormatter()->format((int) $row['created'])),
        ],
      ];
    }

    if ($cards === []) {
      return [
        'empty' => [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => 'No listings match the selected filters.',
        ],
      ];
    }

    return $cards;
  }

  /**
   * Builds a thumbnail URI lookup keyed by listing id.
   *
   * @param array<string,array{selection_key:string,listing_type:string,listing_id:int,entity:\Drupal\ai_listing\Entity\BbAiListing,created:int,sku:string,is_published_to_ebay:bool,ebay_listing_id:?string}> $rows
   *   Listing rows keyed by selection key.
   *
   * @return array<int,string>
   *   Thumbnail URIs keyed by listing id.
   */
  private function buildListingThumbnailUriLookup(array $rows): array {
    if (!$this->getEntityTypeManager()->hasDefinition('listing_image')) {
      return [];
    }

    $listingIds = [];
    foreach ($rows as $row) {
      $listingId = (int) ($row['listing_id'] ?? 0);
      if ($listingId > 0) {
        $listingIds[] = $listingId;
      }
    }

    if ($listingIds === []) {
      return [];
    }

    $imageIds = $this->getEntityTypeManager()->getStorage('listing_image')->getQuery()
      ->accessCheck(FALSE)
      ->condition('owner.target_type', 'bb_ai_listing')
      ->condition('owner.target_id', array_values(array_unique($listingIds)), 'IN')
      ->sort('weight', 'ASC')
      ->sort('id', 'ASC')
      ->execute();

    if ($imageIds === []) {
      return [];
    }

    $lookup = [];
    $images = $this->getEntityTypeManager()->getStorage('listing_image')->loadMultiple($imageIds);
    foreach ($imageIds as $imageId) {
      $image = $images[$imageId] ?? NULL;
      if ($image === NULL) {
        continue;
      }

      $ownerId = (int) ($image->get('owner')->target_id ?? 0);
      if ($ownerId <= 0 || isset($lookup[$ownerId])) {
        continue;
      }

      $file = $image->get('file')->entity;
      if ($file === NULL) {
        continue;
      }

      $lookup[$ownerId] = $file->getFileUri();
    }

    return $lookup;
  }

  /**
   * Builds the mobile thumbnail render array.
   *
   * @return array<string,mixed>
   *   A render array for the thumbnail area.
   */
  private function buildMobileThumbnail(?string $thumbnailUri): array {
    if ($thumbnailUri === NULL || $thumbnailUri === '') {
      return [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-batch-mobile-card__thumb']],
        'placeholder' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => 'No image',
          '#attributes' => ['class' => ['ai-batch-mobile-card__thumb-placeholder']],
        ],
      ];
    }

    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-mobile-card__thumb']],
      'image' => [
        '#theme' => 'image_style',
        '#style_name' => 'thumbnail',
        '#uri' => $thumbnailUri,
        '#alt' => '',
      ],
    ];
  }

  /**
   * Builds a mobile metadata row.
   *
   * @return array<string,mixed>
   *   A render array for one metadata item.
   */
  private function buildMobileMetaItem(string $label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-batch-mobile-card__meta-item']],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $label,
        '#attributes' => ['class' => ['ai-batch-mobile-card__meta-label']],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => $value,
        '#attributes' => ['class' => ['ai-batch-mobile-card__meta-value']],
      ],
    ];
  }

  /**
   * Returns a trimmed string field value when the field exists.
   */
  private function getStringFieldValueIfExists(BbAiListing $listing, string $fieldName): string {
    if (!$listing->hasField($fieldName)) {
      return '';
    }

    return trim((string) ($listing->get($fieldName)->value ?? ''));
  }

  /**
   * Builds the display label for a listing row.
   */
  private function buildListingLabel(string $listingType, BbAiListing $listing): string {
    if ($listingType === 'book_bundle') {
      return (string) ($listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled bundle'));
    }

    if ($listingType === 'book') {
      return (string) ($listing->get('field_full_title')->value ?: $listing->get('field_title')->value ?: $listing->label() ?: $this->t('Untitled listing'));
    }

    return (string) ($listing->label() ?: $listing->get('ebay_title')->value ?: $this->t('Untitled listing'));
  }

  /**
   * Builds the display label for a listing type.
   */
  private function buildTypeLabel(string $listingType): string {
    $label = $this->getListingTypeLabels()[$listingType] ?? NULL;
    if (is_string($label) && $label !== '') {
      return $label;
    }

    return ucfirst(str_replace('_', ' ', $listingType));
  }

  /**
   * Loads listing type labels keyed by bundle.
   *
   * @return array<string,string>
   *   Listing type labels keyed by type id.
   */
  private function getListingTypeLabels(): array {
    if ($this->listingTypeLabels !== NULL) {
      return $this->listingTypeLabels;
    }

    $labels = [];
    $types = $this->getEntityTypeManager()->getStorage('bb_ai_listing_type')->loadMultiple();
    foreach ($types as $type) {
      $id = (string) $type->id();
      $label = (string) $type->label();
      if ($id === '' || $label === '') {
        continue;
      }

      $labels[$id] = $label;
    }

    $this->listingTypeLabels = $labels;
    return $this->listingTypeLabels;
  }

  /**
   * Resolves the listing bundle for a batch item id.
   */
  private static function resolveListingTypeForBatchItem(int $listingId): string {
    $listing = \Drupal::entityTypeManager()->getStorage('bb_ai_listing')->load($listingId);
    if ($listing instanceof BbAiListing) {
      return (string) $listing->bundle();
    }

    return '';
  }

  /**
   * Returns the current pager page.
   */
  private function getCurrentPage(): int {
    return $this->getPagerParameters()->findPage();
  }

  /**
   * Builds the eBay item link markup for a listing id.
   */
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

  /**
   * Returns selected listing references from form state.
   *
   * @return array<int,array{listing_type:string,id:int}>
   *   Selected listing references.
   */
  private function getSelectedListingRefs(FormStateInterface $form_state): array {
    return $this->getBatchSelectionManager()
      ->buildSelectionRefs($this->getSubmittedSelectedListingKeys($form_state));
  }

  /**
   * Returns submitted selected listing keys from form state.
   *
   * @return string[]
   *   Selected listing keys.
   */
  private function getSubmittedSelectedListingKeys(FormStateInterface $form_state): array {
    $submittedValue = $form_state->getValue(self::SELECTED_LISTING_KEYS_FIELD);
    $currentPageSelection = is_array($form_state->getValue('listings'))
      ? $form_state->getValue('listings')
      : [];

    return $this->getBatchSelectionManager()
      ->extractSelectionKeys($submittedValue, $currentPageSelection);
  }

  /**
   * Builds the encoded selected-listing payload for the client.
   */
  private function buildSelectedListingKeysPayload(FormStateInterface $form_state): string {
    return $this->getBatchSelectionManager()
      ->encodeSelectionKeys($this->getSubmittedSelectedListingKeys($form_state));
  }

  /**
   * Returns the entity type manager service.
   */
  private function getEntityTypeManager(): EntityTypeManagerInterface {
    assert($this->entityTypeManager instanceof EntityTypeManagerInterface);
    return $this->entityTypeManager;
  }

  /**
   * Returns the date formatter service.
   */
  private function getDateFormatter(): DateFormatterInterface {
    assert($this->dateFormatter instanceof DateFormatterInterface);
    return $this->dateFormatter;
  }

  /**
   * Returns the workbench confirmation tempstore.
   */
  private function getConfirmTempStore(): PrivateTempStore {
    assert($this->tempStoreFactory instanceof PrivateTempStoreFactory);
    return $this->tempStoreFactory->get(self::WORKBENCH_TEMPSTORE_COLLECTION);
  }

  /**
   * Returns the pager manager service.
   */
  private function getPagerManager(): PagerManagerInterface {
    assert($this->pagerManager instanceof PagerManagerInterface);
    return $this->pagerManager;
  }

  /**
   * Returns the pager parameters service.
   */
  private function getPagerParameters(): PagerParametersInterface {
    assert($this->pagerParameters instanceof PagerParametersInterface);
    return $this->pagerParameters;
  }

  /**
   * Returns the batch dataset provider service.
   */
  private function getBatchDatasetProvider(): AiListingBatchDatasetProvider {
    assert($this->batchDatasetProvider instanceof AiListingBatchDatasetProvider);
    return $this->batchDatasetProvider;
  }

  /**
   * Returns the batch selection manager service.
   */
  private function getBatchSelectionManager(): AiListingBatchSelectionManager {
    assert($this->batchSelectionManager instanceof AiListingBatchSelectionManager);
    return $this->batchSelectionManager;
  }

}
