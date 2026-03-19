<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Controller;

use Drupal\ai_listing\Report\EbayStockCullReportQuery;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read model controller for the eBay stock cull report.
 */
final class AiListingStockCullReportController extends ControllerBase {

  public function __construct(
    private readonly EbayStockCullReportQuery $reportQuery,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly TimeInterface $time,
    private readonly RequestStack $requestStack,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('ai_listing.ebay_stock_cull_report_query'),
      $container->get('date.formatter'),
      $container->get('datetime.time'),
      $container->get('request_stack'),
    );
  }

  public function build(): array {
    $requestTime = $this->time->getRequestTime();
    $listingType = $this->resolveListingTypeFilter();
    $maxPrice = $this->resolveMaxPriceFilter();
    $listedBeforeTimestamp = $this->resolveListedBeforeTimestampFilter();
    $rows = $this->loadSortedRows($listingType, $maxPrice, $listedBeforeTimestamp);
    $totalCount = $this->reportQuery->countRows($listingType, $maxPrice, $listedBeforeTimestamp);

    $build = [
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => 'Currently published eBay listings ranked by cull score, where cull score = age in months / price.',
      ],
      'filters' => [
        '#type' => 'inline_template',
        '#template' => '
          <div class="container-inline">
          <form method="get" action="{{ action }}" class="container-inline">
            <label for="stock-cull-listing-type">{{ label }}</label>
            <select id="stock-cull-listing-type" name="listing_type">
              {% for value, option in options %}
                <option value="{{ value }}"{% if value == selected %} selected{% endif %}>{{ option }}</option>
              {% endfor %}
            </select>
            <label for="stock-cull-max-price">{{ max_price_label }}</label>
            <input id="stock-cull-max-price" type="number" step="0.01" min="0" name="max_price" value="{{ max_price }}" />
            <label for="stock-cull-listed-before">{{ listed_before_label }}</label>
            <input id="stock-cull-listed-before" type="date" name="listed_before" value="{{ listed_before }}" />
            <button type="submit">{{ button }}</button>
          </form>
          <a class="button button--secondary" href="{{ csv_url }}">{{ csv_label }}</a>
          <a class="button button--secondary" href="{{ picker_url }}">{{ picker_label }}</a>
          </div>
        ',
        '#context' => [
          'action' => Url::fromRoute('ai_listing.stock_cull_report')->toString(),
          'label' => $this->t('Listing type'),
          'max_price_label' => $this->t('Max price'),
          'listed_before_label' => $this->t('Listed on or before'),
          'button' => $this->t('Apply'),
          'csv_label' => $this->t('Download CSV'),
          'picker_label' => $this->t('Open picker'),
          'csv_url' => Url::fromRoute('ai_listing.stock_cull_report_csv', [], $this->buildFilterQueryOptions($listingType, $maxPrice, $listedBeforeTimestamp))->toString(),
          'picker_url' => Url::fromRoute('ai_listing.stock_cull_picker', [], $this->buildFilterQueryOptions($listingType, $maxPrice, $listedBeforeTimestamp))->toString(),
          'selected' => $listingType ?? '',
          'max_price' => $maxPrice !== NULL ? number_format($maxPrice, 2, '.', '') : '',
          'listed_before' => $listedBeforeTimestamp !== NULL ? date('Y-m-d', $listedBeforeTimestamp) : '',
          'options' => [
            '' => $this->t('All'),
            'book' => $this->t('Book'),
            'generic' => $this->t('Generic'),
          ],
        ],
      ],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => sprintf('Total matching listings: %d', $totalCount),
      ],
    ];

    if ($rows === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => 'No published eBay listings were found for the stock cull report.',
      ];
      return $build;
    }

    $table_rows = [];
    foreach ($rows as $row) {
      $effectiveListedAt = $row->effectiveListedAt();
      $ageDays = $row->ageDays($requestTime);
      $ageMonths = $row->ageMonths($requestTime);
      $cullScore = $row->cullScore($requestTime);
      $table_rows[] = [
        'listing_id' => $row->listingId,
        'listing_type' => $row->listingType,
        'title' => [
          'data' => [
            '#type' => 'link',
            '#title' => $row->title !== '' ? $row->title : '(untitled)',
            '#url' => Url::fromRoute('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $row->listingId]),
          ],
        ],
        'price' => $row->price,
        'storage_location' => $row->storageLocation,
        'inventory_sku' => $row->inventorySku,
        'marketplace_listing_id' => $row->marketplaceListingId,
        'source' => $row->source,
        'effective_listed_at' => $effectiveListedAt !== NULL ? $this->dateFormatter->format($effectiveListedAt, 'custom', 'Y-m-d H:i') : '',
        'age_days' => $ageDays !== NULL ? (string) $ageDays : '',
        'age_months' => $ageMonths !== NULL ? number_format($ageMonths, 2, '.', '') : '',
        'cull_score' => $cullScore !== NULL ? number_format($cullScore, 4, '.', '') : '',
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        'listing_id' => $this->t('Listing ID'),
        'listing_type' => $this->t('Type'),
        'title' => $this->t('Title'),
        'price' => $this->t('Price'),
        'storage_location' => $this->t('Location'),
        'inventory_sku' => $this->t('SKU'),
        'marketplace_listing_id' => $this->t('eBay listing ID'),
        'source' => $this->t('Source'),
        'effective_listed_at' => $this->t('Listed at'),
        'age_days' => $this->t('Age (days)'),
        'age_months' => $this->t('Age (months)'),
        'cull_score' => $this->t('Cull score'),
      ],
      '#rows' => $table_rows,
      '#empty' => $this->t('No rows available.'),
    ];

    return $build;
  }

  public function downloadCsv(): Response {
    $listingType = $this->resolveListingTypeFilter();
    $maxPrice = $this->resolveMaxPriceFilter();
    $listedBeforeTimestamp = $this->resolveListedBeforeTimestampFilter();
    $rows = $this->loadSortedRows($listingType, $maxPrice, $listedBeforeTimestamp);
    $requestTime = $this->time->getRequestTime();

    $handle = fopen('php://temp', 'r+');
    if ($handle === FALSE) {
      throw new \RuntimeException('Unable to create temporary CSV stream.');
    }

    fputcsv($handle, [
      'listing_id',
      'listing_type',
      'title',
      'price',
      'location',
      'sku',
      'ebay_listing_id',
      'source',
      'listed_at',
      'age_days',
      'age_months',
      'cull_score',
    ]);

    foreach ($rows as $row) {
      $effectiveListedAt = $row->effectiveListedAt();
      fputcsv($handle, [
        $row->listingId,
        $row->listingType,
        $row->title !== '' ? $row->title : '(untitled)',
        $row->price,
        $row->storageLocation,
        $row->inventorySku,
        $row->marketplaceListingId,
        $row->source,
        $effectiveListedAt !== NULL ? $this->dateFormatter->format($effectiveListedAt, 'custom', 'Y-m-d H:i') : '',
        $row->ageDays($requestTime),
        $row->ageMonths($requestTime) !== NULL ? number_format($row->ageMonths($requestTime), 2, '.', '') : '',
        $row->cullScore($requestTime) !== NULL ? number_format($row->cullScore($requestTime), 4, '.', '') : '',
      ]);
    }

    rewind($handle);
    $csv = stream_get_contents($handle);
    fclose($handle);

    $suffix = $listingType !== NULL ? '-' . $listingType : '';
    if ($maxPrice !== NULL) {
      $suffix .= '-max-price-' . str_replace('.', '_', number_format($maxPrice, 2, '.', ''));
    }
    if ($listedBeforeTimestamp !== NULL) {
      $suffix .= '-listed-before-' . date('Y_m_d', $listedBeforeTimestamp);
    }
    return new Response($csv !== FALSE ? $csv : '', 200, [
      'Content-Type' => 'text/csv; charset=UTF-8',
      'Content-Disposition' => sprintf('attachment; filename="ebay-stock-cull-report%s.csv"', $suffix),
    ]);
  }

  /**
   * @return array<int,\Drupal\ai_listing\Report\EbayStockCullReportRow>
   */
  private function loadSortedRows(?string $listingType, ?float $maxPrice, ?int $listedBeforeTimestamp): array {
    $requestTime = $this->time->getRequestTime();
    $rows = $this->reportQuery->fetchRows(250, $listingType, $maxPrice, $listedBeforeTimestamp);
    usort($rows, static function ($left, $right) use ($requestTime): int {
      $leftScore = $left->cullScore($requestTime);
      $rightScore = $right->cullScore($requestTime);

      if ($leftScore === $rightScore) {
        $leftEffective = $left->effectiveListedAt() ?? PHP_INT_MAX;
        $rightEffective = $right->effectiveListedAt() ?? PHP_INT_MAX;
        if ($leftEffective !== $rightEffective) {
          return $leftEffective <=> $rightEffective;
        }

        $leftPrice = $left->priceAsFloat() ?? INF;
        $rightPrice = $right->priceAsFloat() ?? INF;
        if ($leftPrice !== $rightPrice) {
          return $leftPrice <=> $rightPrice;
        }

        return $left->listingId <=> $right->listingId;
      }

      if ($leftScore === NULL) {
        return 1;
      }
      if ($rightScore === NULL) {
        return -1;
      }

      return $rightScore <=> $leftScore;
    });

    return $rows;
  }

  private function resolveListingTypeFilter(): ?string {
    $value = $this->requestStack->getCurrentRequest()?->query->get('listing_type');
    if (!is_string($value)) {
      return NULL;
    }

    $value = trim($value);
    if ($value === '') {
      return NULL;
    }

    return in_array($value, ['book', 'generic'], TRUE) ? $value : NULL;
  }

  private function resolveMaxPriceFilter(): ?float {
    $value = $this->requestStack->getCurrentRequest()?->query->get('max_price');
    if (!is_string($value)) {
      return NULL;
    }

    $value = trim($value);
    if ($value === '' || !is_numeric($value)) {
      return NULL;
    }

    $numeric = (float) $value;
    return $numeric >= 0 ? $numeric : NULL;
  }

  private function resolveListedBeforeTimestampFilter(): ?int {
    $value = $this->requestStack->getCurrentRequest()?->query->get('listed_before');
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
   * @return array<string,array<string,string>>
   */
  private function buildFilterQueryOptions(?string $listingType, ?float $maxPrice, ?int $listedBeforeTimestamp): array {
    $query = [];
    if ($listingType !== NULL) {
      $query['listing_type'] = $listingType;
    }
    if ($maxPrice !== NULL) {
      $query['max_price'] = number_format($maxPrice, 2, '.', '');
    }
    if ($listedBeforeTimestamp !== NULL) {
      $query['listed_before'] = date('Y-m-d', $listedBeforeTimestamp);
    }

    if ($query === []) {
      return [];
    }

    return [
      'query' => $query,
    ];
  }

}
