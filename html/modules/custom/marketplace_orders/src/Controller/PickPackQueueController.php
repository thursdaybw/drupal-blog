<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\marketplace_orders\Service\PickPackQueueQueryService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Admin UI controller for the marketplace pick-pack queue.
 */
final class PickPackQueueController extends ControllerBase {

  public function __construct(
    private readonly PickPackQueueQueryService $pickPackQueueQueryService,
    private readonly PagerManagerInterface $pagerManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(PickPackQueueQueryService::class),
      $container->get('pager.manager'),
      $container->get('date.formatter'),
    );
  }

  public function build(Request $request): array {
    $marketplace = trim((string) $request->query->get('marketplace', 'ebay'));
    $limit = $this->normalizeLimit($request->query->get('limit', '25'));
    $showAll = $this->normalizeBooleanQueryFlag($request->query->get('all', '0'));

    $currentPage = max(0, (int) $request->query->get('page', 0));
    $offset = $currentPage * $limit;

    $result = $this->pickPackQueueQueryService->query([
      'marketplace' => $marketplace,
      'limit' => $limit,
      'offset' => $offset,
      'actionable_only' => !$showAll,
    ]);

    $this->pagerManager->createPager($result->getTotalRows(), $limit);

    $build = [
      'filters' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['marketplace-orders-filters']],
        'text' => [
          '#markup' => $this->buildFilterMarkup($marketplace, $limit, $showAll),
        ],
      ],
      'summary' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => sprintf(
          'Showing %d of %d rows (offset %d).',
          count($result->getRows()),
          $result->getTotalRows(),
          $result->getOffset(),
        ),
      ],
      'table' => [
        '#type' => 'table',
        '#header' => [
          'ordered_at' => $this->t('Ordered'),
          'marketplace' => $this->t('Marketplace'),
          'order' => $this->t('Order'),
          'buyer' => $this->t('Buyer'),
          'payment' => $this->t('Payment'),
          'fulfillment' => $this->t('Fulfillment'),
          'line' => $this->t('Line'),
          'sku' => $this->t('SKU'),
          'qty' => $this->t('Qty'),
          'listing' => $this->t('Listing'),
          'location' => $this->t('Location'),
        ],
        '#rows' => $this->buildRows($result->getRows()),
        '#empty' => $this->t('No rows match the current filter.'),
      ],
      'pager' => [
        '#type' => 'pager',
      ],
    ];

    return $build;
  }

  /**
   * @param array<int,\Drupal\marketplace_orders\Model\PickPackQueueRow> $rows
   *
   * @return array<int,array<string,string>>
   */
  private function buildRows(array $rows): array {
    $tableRows = [];

    foreach ($rows as $row) {
      $orderedAt = $row->getOrderedAt();
      $orderedText = $orderedAt === NULL
        ? ''
        : $this->dateFormatter->format($orderedAt, 'custom', 'Y-m-d H:i');

      $tableRows[] = [
        'ordered_at' => $orderedText,
        'marketplace' => $row->getMarketplace(),
        'order' => $row->getExternalOrderId(),
        'buyer' => $row->getBuyerHandle() ?? '',
        'payment' => $row->getPaymentStatus() ?? '',
        'fulfillment' => $row->getFulfillmentStatus() ?? '',
        'line' => $row->getExternalLineId(),
        'sku' => $row->getSku() ?? '',
        'qty' => (string) $row->getQuantity(),
        'listing' => $row->getListingTitle() ?? ($row->getLineTitle() ?? ''),
        'location' => $row->getStorageLocation() ?? '',
      ];
    }

    return $tableRows;
  }

  private function normalizeLimit(mixed $value): int {
    if (!is_numeric($value)) {
      return 25;
    }

    $limit = (int) $value;
    if ($limit < 1) {
      return 25;
    }

    if ($limit > 200) {
      return 200;
    }

    return $limit;
  }

  private function normalizeBooleanQueryFlag(mixed $value): bool {
    if (is_bool($value)) {
      return $value;
    }

    $normalized = strtolower(trim((string) $value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], TRUE);
  }

  private function buildFilterMarkup(string $marketplace, int $limit, bool $showAll): string {
    $allFlag = $showAll ? '1' : '0';
    $toggleAllFlag = $showAll ? '0' : '1';

    $base = [
      'marketplace' => $marketplace,
      'limit' => $limit,
    ];

    $actionableUrl = Url::fromRoute('marketplace_orders.pick_pack_queue', [], [
      'query' => $base + ['all' => '0'],
    ])->toString();

    $allUrl = Url::fromRoute('marketplace_orders.pick_pack_queue', [], [
      'query' => $base + ['all' => '1'],
    ])->toString();

    $toggleUrl = Url::fromRoute('marketplace_orders.pick_pack_queue', [], [
      'query' => $base + ['all' => $toggleAllFlag],
    ])->toString();

    $modeLabel = $showAll ? 'all rows' : 'actionable only';

    return sprintf(
      'Marketplace: %s | Limit: %d | Mode: %s | <a href="%s">Actionable</a> | <a href="%s">All</a> | <a href="%s">Toggle</a>',
      htmlspecialchars($marketplace, ENT_QUOTES, 'UTF-8'),
      $limit,
      $modeLabel,
      $actionableUrl,
      $allUrl,
      $toggleUrl,
    );
  }

}
