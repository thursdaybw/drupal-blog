<?php

declare(strict_types=1);

namespace Drupal\marketplace_orders\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Drupal\Core\Pager\PagerManagerInterface;
use Drupal\Core\Url;
use Drupal\marketplace_orders\Form\FetchMarketplaceOrdersForm;
use Drupal\marketplace_orders\Form\OrderLineWorkflowTransitionForm;
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
    private readonly FormBuilderInterface $workflowActionFormBuilder,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get(PickPackQueueQueryService::class),
      $container->get('pager.manager'),
      $container->get('date.formatter'),
      $container->get('form_builder'),
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
      '#attached' => [
        'library' => [
          'marketplace_orders/pick_pack_queue',
        ],
      ],
      'filters' => [
        '#type' => 'container',
        '#attributes' => ['class' => ['marketplace-orders-filters']],
        'text' => [
          '#markup' => $this->buildFilterMarkup($marketplace, $limit, $showAll),
        ],
        'fetch' => $this->workflowActionFormBuilder->getForm(
          FetchMarketplaceOrdersForm::class,
          $marketplace,
          $request->getRequestUri()
        ),
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
        '#attributes' => [
          'class' => ['marketplace-orders-desktop-table'],
        ],
        '#header' => [
          'ordered_at' => $this->t('Ordered'),
          'marketplace' => $this->t('Marketplace'),
          'order' => $this->t('Order'),
          'buyer' => $this->t('Buyer'),
          'payment' => $this->t('Payment'),
          'fulfillment' => $this->t('Fulfillment'),
          'warehouse' => $this->t('Warehouse'),
          'line' => $this->t('Line'),
          'sku' => $this->t('SKU'),
          'qty' => $this->t('Qty'),
          'listing' => $this->t('Listing'),
          'location' => $this->t('Location'),
          'actions' => $this->t('Actions'),
        ],
        '#rows' => $this->buildRows($result->getRows(), $request),
        '#empty' => $this->t('No rows match the current filter.'),
      ],
      'mobile_cards' => [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['marketplace-orders-mobile-cards'],
        ],
        'items' => $this->buildMobileCards($result->getRows(), $request),
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
   * @return array<int,array<string,mixed>>
   */
  private function buildRows(array $rows, Request $request): array {
    $tableRows = [];
    $destination = $request->getRequestUri();

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
        'warehouse' => $row->getWarehouseStatus(),
        'line' => $row->getExternalLineId(),
        'sku' => $row->getSku() ?? '',
        'qty' => (string) $row->getQuantity(),
        'listing' => $row->getListingTitle() ?? ($row->getLineTitle() ?? ''),
        'location' => $row->getStorageLocation() ?? '',
        'actions' => [
          'data' => $this->buildActionCell(
            $row->getOrderLineId(),
            $row->getWarehouseStatus(),
            $destination
          ),
        ],
      ];
    }

    return $tableRows;
  }

  private function buildActionCell(int $orderLineId, string $warehouseStatus, string $destination): array {
    $nextAction = $this->resolveNextWorkflowAction($warehouseStatus);

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['marketplace-orders-actions'],
      ],
      'next_action' => $nextAction === NULL
        ? ['#markup' => '']
        : $this->workflowActionFormBuilder->getForm(
          OrderLineWorkflowTransitionForm::class,
          $orderLineId,
          $nextAction,
          $destination
        ),
    ];
  }

  /**
   * @param array<int,\Drupal\marketplace_orders\Model\PickPackQueueRow> $rows
   *
   * @return array<int,array<string,mixed>>
   */
  private function buildMobileCards(array $rows, Request $request): array {
    $cards = [];
    $destination = $request->getRequestUri();

    foreach ($rows as $index => $row) {
      $orderedAt = $row->getOrderedAt();
      $orderedText = $orderedAt === NULL
        ? ''
        : $this->dateFormatter->format($orderedAt, 'custom', 'Y-m-d H:i');

      $cards[$index] = [
        '#type' => 'container',
        '#attributes' => [
          'class' => ['marketplace-orders-mobile-card'],
        ],
        'topline' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['marketplace-orders-mobile-card__topline'],
          ],
          'location' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => $row->getStorageLocation() ?? 'Unset',
            '#attributes' => [
              'class' => ['marketplace-orders-mobile-card__location'],
            ],
          ],
          'qty' => [
            '#type' => 'html_tag',
            '#tag' => 'div',
            '#value' => 'Qty ' . $row->getQuantity(),
            '#attributes' => [
              'class' => ['marketplace-orders-mobile-card__qty'],
            ],
          ],
        ],
        'title' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $row->getListingTitle() ?? ($row->getLineTitle() ?? 'Untitled'),
          '#attributes' => [
            'class' => ['marketplace-orders-mobile-card__title'],
          ],
        ],
        'sku' => [
          '#type' => 'html_tag',
          '#tag' => 'div',
          '#value' => $row->getSku() ?? '',
          '#attributes' => [
            'class' => ['marketplace-orders-mobile-card__sku'],
          ],
        ],
        'meta' => [
          '#type' => 'container',
          '#attributes' => [
            'class' => ['marketplace-orders-mobile-card__meta'],
          ],
          'order' => $this->buildMetaItem('Order', $row->getExternalOrderId()),
          'buyer' => $this->buildMetaItem('Buyer', $row->getBuyerHandle() ?? ''),
          'marketplace' => $this->buildMetaItem('Marketplace', $row->getMarketplace()),
          'ordered' => $this->buildMetaItem('Ordered', $orderedText),
          'payment' => $this->buildMetaItem('Payment', $row->getPaymentStatus() ?? ''),
          'fulfillment' => $this->buildMetaItem('Fulfillment', $row->getFulfillmentStatus() ?? ''),
          'warehouse' => $this->buildMetaItem('Warehouse', $row->getWarehouseStatus()),
          'line' => $this->buildMetaItem('Line', $row->getExternalLineId()),
        ],
        'actions' => $this->buildActionCell(
          $row->getOrderLineId(),
          $row->getWarehouseStatus(),
          $destination
        ),
      ];
    }

    return $cards;
  }

  /**
   * @return array<string,mixed>
   */
  private function buildMetaItem(string $label, string $value): array {
    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['marketplace-orders-mobile-card__meta-item'],
      ],
      'label' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $label,
        '#attributes' => [
          'class' => ['marketplace-orders-mobile-card__meta-label'],
        ],
      ],
      'value' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#value' => $value,
        '#attributes' => [
          'class' => ['marketplace-orders-mobile-card__meta-value'],
        ],
      ],
    ];
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

  private function resolveNextWorkflowAction(string $warehouseStatus): ?string {
    return match (trim(strtolower($warehouseStatus))) {
      'new' => 'picked',
      'picked' => 'packed',
      'packed' => 'label_purchased',
      'label_purchased' => 'dispatched',
      default => NULL,
    };
  }

  private function buildFilterMarkup(string $marketplace, int $limit, bool $showAll): string {
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

    $modeLabel = $showAll ? 'all rows' : 'actionable only';

    return sprintf(
      '<div class="marketplace-orders-filters__meta"><span>Marketplace: %s</span><span>Limit: %d</span><span>Mode: %s</span></div><div class="marketplace-orders-filters__links"><a href="%s">Actionable</a><a href="%s">All</a></div>',
      htmlspecialchars($marketplace, ENT_QUOTES, 'UTF-8'),
      $limit,
      $modeLabel,
      $actionableUrl,
      $allUrl,
    );
  }

}
