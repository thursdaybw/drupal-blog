<?php

declare(strict_types=1);

namespace Drupal\bb_ebay_legacy_migration\Controller;

use Drupal\bb_ebay_legacy_migration\Service\EbayLegacyImportBlocklistService;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Link;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\ebay_infrastructure\Service\EbayAccountManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class EbayLegacyBlockedReportController extends ControllerBase {

  public function __construct(
    private readonly EbayLegacyImportBlocklistService $blocklistService,
    private readonly EbayAccountManager $accountManager,
    private readonly DateFormatterInterface $dateFormatter,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('bb_ebay_legacy_migration.import_blocklist_service'),
      $container->get('drupal.ebay_infrastructure.account_manager'),
      $container->get('date.formatter'),
    );
  }

  /**
   * Builds the blocked legacy import report table.
   */
  public function report(): array {
    $account = $this->accountManager->loadPrimaryAccount();
    $this->blocklistService->pruneNonActiveFailuresForAccount((int) $account->id());
    $rows = $this->blocklistService->getFailuresForAccount((int) $account->id());

    $build = [
      'intro' => [
        '#type' => 'item',
        '#markup' => $this->t('Blocked legacy imports for account @id (@label). These rows failed migration and need manual fixes in eBay before retry.', [
          '@id' => (string) $account->id(),
          '@label' => (string) $account->label(),
        ]),
      ],
    ];

    if ($rows === []) {
      $build['empty'] = [
        '#type' => 'item',
        '#markup' => $this->t('No blocked legacy imports are currently recorded.'),
      ];
      return $build;
    }

    $tableRows = [];
    foreach ($rows as $row) {
      $listingId = $row['listing_id'];
      $status = (string) ($row['status'] ?? 'needs_manual_fix');

      $clearAction = '';
      if ($status === 'needs_manual_fix') {
        $clearAction = Link::fromTextAndUrl(
          $this->t('Clear'),
          Url::fromRoute('bb_ebay_legacy_migration.clear_blocked_listing', [
            'listing_id' => $listingId,
          ], [
            'attributes' => ['class' => ['button', 'button--small']],
          ])
        )->toString();
      }
      elseif ($status === 'cleared') {
        $clearAction = Markup::create('<span class="button button--small button--disabled" aria-disabled="true">Cleared</span>');
      }

      $tableRows[] = [
        $listingId,
        $this->formatStatus($status),
        $row['title'] ?? 'Untitled listing',
        $row['sku'] ?? 'unset',
        (string) $row['failure_count'],
        $this->formatTimestamp($row['first_failed_at']),
        $this->formatTimestamp($row['last_failed_at']),
        $row['last_error_message'],
        Link::fromTextAndUrl($this->t('Open eBay item'), Url::fromUri('https://www.ebay.com.au/itm/' . $listingId, ['attributes' => ['target' => '_blank', 'rel' => 'noopener noreferrer']]))->toString(),
        $clearAction,
      ];
    }

    $build['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('eBay Item ID'),
        $this->t('Status'),
        $this->t('Title'),
        $this->t('SKU'),
        $this->t('Failures'),
        $this->t('First failed'),
        $this->t('Last failed'),
        $this->t('Last error'),
        $this->t('eBay link'),
        $this->t('Actions'),
      ],
      '#rows' => $tableRows,
      '#empty' => $this->t('No blocked legacy imports are currently recorded.'),
    ];

    return $build;
  }

  private function formatTimestamp(int $timestamp): string {
    if ($timestamp <= 0) {
      return 'unknown';
    }

    return $this->dateFormatter->format($timestamp, 'custom', 'Y-m-d H:i:s');
  }

  private function formatStatus(string $status): string {
    return match ($status) {
      'retry_next_run' => 'Retry next run',
      'cleared' => 'Cleared',
      'already_migrated' => 'Already migrated',
      default => 'Needs manual fix',
    };
  }

}
