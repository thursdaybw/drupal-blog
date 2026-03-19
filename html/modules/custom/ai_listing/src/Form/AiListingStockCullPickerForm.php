<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Report\EbayStockCullReportQuery;
use Drupal\ai_listing\Report\EbayStockCullReportRow;
use Drupal\ai_listing\Service\StockCullSelectionStore;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Operational shelf-by-shelf picker for stock cull candidates.
 */
final class AiListingStockCullPickerForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    private readonly EbayStockCullReportQuery $reportQuery,
    private readonly StockCullSelectionStore $selectionStore,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
    private readonly TimeInterface $time,
    private readonly RequestStack $currentRequestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_listing.ebay_stock_cull_report_query'),
      $container->get('ai_listing.stock_cull_selection_store'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
    );
  }

  public function getFormId(): string {
    return 'ai_listing_stock_cull_picker_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $filters = $this->resolveFilters();
    $totalCount = $this->reportQuery->countRows($filters['listing_type'], $filters['max_price'], $filters['listed_before']);
    $rows = $this->loadSortedRows($totalCount, $filters['listing_type'], $filters['max_price'], $filters['listed_before']);
    $listingIds = array_map(static fn(EbayStockCullReportRow $row): int => $row->listingId, $rows);
    $statuses = $this->selectionStore->getStatuses($listingIds);
    $imageLookup = $this->buildListingImageLookup($listingIds);
    $groupedRows = $this->groupRowsByLocation($rows);

    $form['#tree'] = FALSE;
    $form['#attached']['library'][] = 'ai_listing/photo_viewer';
    $form['#attached']['library'][] = 'ai_listing/stock_cull_picker';

    $form['intro'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => 'Shelf-by-shelf picker for cull candidates grouped by storage location.',
    ];

    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['container-inline']],
    ];
    $form['filters']['listing_type'] = [
      '#type' => 'select',
      '#title' => $this->t('Listing type'),
      '#options' => [
        '' => $this->t('All'),
        'book' => $this->t('Book'),
        'generic' => $this->t('Generic'),
      ],
      '#default_value' => $filters['listing_type'] ?? '',
    ];
    $form['filters']['max_price'] = [
      '#type' => 'number',
      '#title' => $this->t('Max price'),
      '#step' => '0.01',
      '#min' => '0',
      '#default_value' => $filters['max_price'] !== NULL ? number_format($filters['max_price'], 2, '.', '') : '',
    ];
    $form['filters']['listed_before'] = [
      '#type' => 'date',
      '#title' => $this->t('Listed on or before'),
      '#default_value' => $filters['listed_before'] !== NULL ? date('Y-m-d', $filters['listed_before']) : '',
    ];
    $form['filters']['apply_filters'] = [
      '#type' => 'submit',
      '#value' => $this->t('Apply filters'),
      '#submit' => ['::submitApplyFilters'],
      '#limit_validation_errors' => [],
    ];
    $form['filters']['report_link'] = Link::fromTextAndUrl(
      $this->t('Back to stock cull report'),
      Url::fromRoute('ai_listing.stock_cull_report', [], $this->buildQueryOptionsFromFilters($filters))
    )->toRenderable();

    $form['summary'] = [
      '#type' => 'container',
    ];
    $form['summary']['matching'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => sprintf('Total matching listings: %d', $totalCount),
    ];
    $form['summary']['marked'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => sprintf('Currently marked for cull: %d', $this->selectionStore->countMarked($listingIds)),
    ];

    $form['actions_top'] = [
      '#type' => 'actions',
    ];
    $form['actions_top']['mark_selected'] = [
      '#type' => 'submit',
      '#name' => 'mark_selected',
      '#value' => $this->t('Mark selected for cull'),
      '#submit' => ['::submitMarkSelected'],
    ];
    $form['actions_top']['unmark_selected'] = [
      '#type' => 'submit',
      '#name' => 'unmark_selected',
      '#value' => $this->t('Unmark selected'),
      '#submit' => ['::submitUnmarkSelected'],
    ];

    if ($rows === []) {
      $form['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => 'No matching cull candidates found for the current filters.',
      ];
      return $form;
    }

    $form['locations'] = [
      '#type' => 'container',
      '#tree' => TRUE,
    ];

    $requestTime = $this->time->getRequestTime();
    $openFirst = TRUE;
    foreach ($groupedRows as $locationLabel => $locationRows) {
      $locationKey = 'location_' . substr(md5($locationLabel), 0, 12);
      $markedInLocation = 0;
      foreach ($locationRows as $row) {
        if (($statuses[$row->listingId] ?? StockCullSelectionStore::STATUS_NOT_MARKED) === StockCullSelectionStore::STATUS_MARKED_FOR_CULL) {
          $markedInLocation++;
        }
      }

      $form['locations'][$locationKey] = [
        '#type' => 'details',
        '#title' => sprintf('%s (%d candidates, %d marked)', $locationLabel, count($locationRows), $markedInLocation),
        '#open' => $openFirst,
      ];
      $openFirst = FALSE;

      $form['locations'][$locationKey]['select_all'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Select all in this location'),
        '#attributes' => [
          'class' => ['ai-stock-cull-picker__select-all'],
          'data-location-key' => $locationKey,
        ],
      ];

      $form['locations'][$locationKey]['table'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Select'),
          $this->t('Image'),
          $this->t('Title'),
          $this->t('Price'),
          $this->t('SKU'),
          $this->t('eBay ID'),
          $this->t('Cull status'),
          $this->t('Cull score'),
        ],
        '#tree' => TRUE,
      ];

      foreach ($locationRows as $row) {
        $status = $statuses[$row->listingId] ?? StockCullSelectionStore::STATUS_NOT_MARKED;
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['selected'] = [
          '#type' => 'checkbox',
          '#title' => '',
          '#attributes' => [
            'class' => ['ai-stock-cull-picker__row-checkbox'],
            'data-location-key' => $locationKey,
          ],
        ];
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['image'] = $this->buildGalleryCell($row->listingId, $imageLookup[$row->listingId] ?? []);
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['title'] = [
          '#type' => 'container',
          'link' => Link::fromTextAndUrl(
            $row->title !== '' ? $row->title : '(untitled)',
            Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $row->listingId])
          )->toRenderable(),
        ];
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['price'] = ['#markup' => $row->price];
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['sku'] = ['#markup' => $row->inventorySku !== '' ? $row->inventorySku : '—'];
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['ebay_id'] = ['#markup' => $row->marketplaceListingId !== '' ? $row->marketplaceListingId : '—'];
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['cull_status'] = ['#markup' => $this->formatCullStatus($status)];
        $form['locations'][$locationKey]['table'][(string) $row->listingId]['cull_score'] = [
          '#markup' => $row->cullScore($requestTime) !== NULL ? number_format($row->cullScore($requestTime), 4, '.', '') : '—',
        ];
      }
    }

    $form['actions_bottom'] = $form['actions_top'];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {}

  public function submitApplyFilters(array &$form, FormStateInterface $form_state): void {
    $form_state->setRedirect('ai_listing.stock_cull_picker', [], $this->buildQueryOptionsFromSubmittedFilters($form_state));
  }

  public function submitMarkSelected(array &$form, FormStateInterface $form_state): void {
    $selectedListingIds = $this->extractSelectedListingIds($form_state);
    if ($selectedListingIds === []) {
      $this->messenger()->addWarning($this->t('No listings were selected.'));
      $form_state->setRedirect('ai_listing.stock_cull_picker', [], $this->buildQueryOptionsFromFilters($this->resolveFilters()));
      return;
    }

    $this->selectionStore->markForCull($selectedListingIds);
    $this->messenger()->addStatus($this->t('Marked @count listings for cull.', ['@count' => count($selectedListingIds)]));
    $form_state->setRedirect('ai_listing.stock_cull_picker', [], $this->buildQueryOptionsFromFilters($this->resolveFilters()));
  }

  public function submitUnmarkSelected(array &$form, FormStateInterface $form_state): void {
    $selectedListingIds = $this->extractSelectedListingIds($form_state);
    if ($selectedListingIds === []) {
      $this->messenger()->addWarning($this->t('No listings were selected.'));
      $form_state->setRedirect('ai_listing.stock_cull_picker', [], $this->buildQueryOptionsFromFilters($this->resolveFilters()));
      return;
    }

    $this->selectionStore->clearMark($selectedListingIds);
    $this->messenger()->addStatus($this->t('Removed cull mark from @count listings.', ['@count' => count($selectedListingIds)]));
    $form_state->setRedirect('ai_listing.stock_cull_picker', [], $this->buildQueryOptionsFromFilters($this->resolveFilters()));
  }

  /**
   * @return array{listing_type:?string,max_price:?float,listed_before:?int}
   */
  private function resolveFilters(): array {
    return [
      'listing_type' => $this->resolveListingTypeFilter(),
      'max_price' => $this->resolveMaxPriceFilter(),
      'listed_before' => $this->resolveListedBeforeTimestampFilter(),
    ];
  }

  /**
   * @param \Drupal\ai_listing\Report\EbayStockCullReportRow[] $rows
   *
   * @return array<string,array<int,\Drupal\ai_listing\Report\EbayStockCullReportRow>>
   */
  private function groupRowsByLocation(array $rows): array {
    $grouped = [];
    foreach ($rows as $row) {
      $locationLabel = trim($row->storageLocation);
      if ($locationLabel === '') {
        $locationLabel = 'Unset yet';
      }
      $grouped[$locationLabel][] = $row;
    }

    uksort($grouped, static function (string $left, string $right): int {
      if ($left === 'Unset yet') {
        return 1;
      }
      if ($right === 'Unset yet') {
        return -1;
      }
      return strnatcasecmp($left, $right);
    });

    return $grouped;
  }

  /**
   * @param int[] $listingIds
   *
   * @return array<int,array<int,string>>
   */
  private function buildListingImageLookup(array $listingIds): array {
    if ($listingIds === [] || !$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    $imageIds = $this->entityTypeManager->getStorage('listing_image')->getQuery()
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
    $images = $this->entityTypeManager->getStorage('listing_image')->loadMultiple($imageIds);
    foreach ($imageIds as $imageId) {
      $image = $images[$imageId] ?? NULL;
      if ($image === NULL) {
        continue;
      }

      $ownerId = (int) ($image->get('owner')->target_id ?? 0);
      $file = $image->get('file')->entity;
      if ($ownerId <= 0 || $file === NULL) {
        continue;
      }

      $lookup[$ownerId][] = $file->getFileUri();
    }

    return $lookup;
  }

  /**
   * @param string[] $fileUris
   *
   * @return array<string,mixed>
   */
  private function buildGalleryCell(int $listingId, array $fileUris): array {
    if ($fileUris === []) {
      return ['#markup' => 'No image'];
    }

    $galleryId = 'stock-cull-' . $listingId;
    $render = [
      '#type' => 'container',
      'first' => [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#attributes' => [
          'href' => $this->fileUrlGenerator->generateString($fileUris[0]),
          'class' => ['ai-listing-photo-link'],
          'data-gallery' => $galleryId,
        ],
        'image' => [
          '#theme' => 'image_style',
          '#style_name' => 'thumbnail',
          '#uri' => $fileUris[0],
          '#alt' => '',
        ],
      ],
      'count' => [
        '#markup' => '<div class="description">' . $this->t('@count photos', ['@count' => count($fileUris)]) . '</div>',
      ],
    ];

    foreach (array_slice($fileUris, 1) as $index => $fileUri) {
      $render['hidden_' . $index] = [
        '#type' => 'html_tag',
        '#tag' => 'a',
        '#value' => '',
        '#attributes' => [
          'href' => $this->fileUrlGenerator->generateString($fileUri),
          'class' => ['ai-listing-photo-link'],
          'data-gallery' => $galleryId,
          'style' => 'display:none',
        ],
      ];
    }

    return $render;
  }

  /**
   * @return int[]
   */
  private function extractSelectedListingIds(FormStateInterface $form_state): array {
    $selected = [];
    $locations = $form_state->getValue('locations');
    if (!is_array($locations)) {
      return [];
    }

    foreach ($locations as $locationGroup) {
      if (!is_array($locationGroup) || !isset($locationGroup['table']) || !is_array($locationGroup['table'])) {
        continue;
      }
      foreach ($locationGroup['table'] as $listingId => $rowValues) {
        if (is_array($rowValues) && !empty($rowValues['selected'])) {
          $listingId = (int) $listingId;
          if ($listingId > 0) {
            $selected[] = $listingId;
          }
        }
      }
    }

    return array_values(array_unique($selected));
  }

  /**
   * @return \Drupal\ai_listing\Report\EbayStockCullReportRow[]
   */
  private function loadSortedRows(int $count, ?string $listingType, ?float $maxPrice, ?int $listedBeforeTimestamp): array {
    if ($count === 0) {
      return [];
    }

    $requestTime = $this->time->getRequestTime();
    $rows = $this->reportQuery->fetchRows($count, $listingType, $maxPrice, $listedBeforeTimestamp);
    usort($rows, static function (EbayStockCullReportRow $left, EbayStockCullReportRow $right) use ($requestTime): int {
      $leftLocation = trim($left->storageLocation);
      $rightLocation = trim($right->storageLocation);
      if ($leftLocation === '') {
        $leftLocation = '~~~~';
      }
      if ($rightLocation === '') {
        $rightLocation = '~~~~';
      }

      $locationCompare = strnatcasecmp($leftLocation, $rightLocation);
      if ($locationCompare !== 0) {
        return $locationCompare;
      }

      $leftScore = $left->cullScore($requestTime) ?? -INF;
      $rightScore = $right->cullScore($requestTime) ?? -INF;
      if ($leftScore !== $rightScore) {
        return $rightScore <=> $leftScore;
      }

      return $left->listingId <=> $right->listingId;
    });

    return $rows;
  }

  private function formatCullStatus(string $status): string {
    return match ($status) {
      StockCullSelectionStore::STATUS_MARKED_FOR_CULL => 'Marked for cull',
      StockCullSelectionStore::STATUS_CULLED => 'Culled',
      default => 'Not marked',
    };
  }

  private function resolveListingTypeFilter(): ?string {
    $value = $this->currentRequestStack->getCurrentRequest()?->query->get('listing_type');
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    return in_array($value, ['book', 'generic'], TRUE) ? $value : NULL;
  }

  private function resolveMaxPriceFilter(): ?float {
    $value = $this->currentRequestStack->getCurrentRequest()?->query->get('max_price');
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
      return NULL;
    }
    $value = (float) $value;
    return $value >= 0 ? $value : NULL;
  }

  private function resolveListedBeforeTimestampFilter(): ?int {
    $value = $this->currentRequestStack->getCurrentRequest()?->query->get('listed_before');
    if (!is_string($value)) {
      return NULL;
    }
    $value = trim($value);
    if ($value === '') {
      return NULL;
    }
    $timestamp = strtotime($value . ' 23:59:59');
    return $timestamp !== FALSE ? $timestamp : NULL;
  }

  /**
   * @param array{listing_type:?string,max_price:?float,listed_before:?int} $filters
   *
   * @return array<string,array<string,string>>
   */
  private function buildQueryOptionsFromFilters(array $filters): array {
    $query = [];
    if ($filters['listing_type'] !== NULL) {
      $query['listing_type'] = $filters['listing_type'];
    }
    if ($filters['max_price'] !== NULL) {
      $query['max_price'] = number_format($filters['max_price'], 2, '.', '');
    }
    if ($filters['listed_before'] !== NULL) {
      $query['listed_before'] = date('Y-m-d', $filters['listed_before']);
    }
    return $query === [] ? [] : ['query' => $query];
  }

  /**
   * @return array<string,array<string,string>>
   */
  private function buildQueryOptionsFromSubmittedFilters(FormStateInterface $form_state): array {
    $query = [];

    $requestValues = $this->currentRequestStack->getCurrentRequest()?->request->all() ?? [];
    $listingType = trim((string) ($requestValues['listing_type'] ?? $form_state->getValue('listing_type') ?? ''));
    if (in_array($listingType, ['book', 'generic'], TRUE)) {
      $query['listing_type'] = $listingType;
    }

    $maxPrice = trim((string) ($requestValues['max_price'] ?? $form_state->getValue('max_price') ?? ''));
    if ($maxPrice !== '' && is_numeric($maxPrice) && (float) $maxPrice >= 0) {
      $query['max_price'] = number_format((float) $maxPrice, 2, '.', '');
    }

    $listedBefore = trim((string) ($requestValues['listed_before'] ?? $form_state->getValue('listed_before') ?? ''));
    if ($listedBefore !== '') {
      $query['listed_before'] = $listedBefore;
    }

    return $query === [] ? [] : ['query' => $query];
  }

}
