<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Controller;

use Drupal\ai_listing\Entity\AiMarketplacePublication;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Read model controller for listing marketplace publication state.
 */
final class AiListingMarketplacesController extends ControllerBase {

  public function __construct(
    private readonly EntityTypeManagerInterface $listingEntityTypeManager,
    private readonly DateFormatterInterface $dateFormatter,
    private readonly FormBuilderInterface $listingFormBuilder,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('form_builder'),
    );
  }

  public function build(BbAiListing $bb_ai_listing): array {
    $build = [
      'intro' => [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => 'Current marketplace publication state for this listing.',
      ],
    ];

    $publications = $this->loadMarketplacePublications((int) $bb_ai_listing->id());
    if ($publications === []) {
      $build['empty'] = [
        '#type' => 'html_tag',
        '#tag' => 'p',
        '#value' => 'This listing has no marketplace publication records.',
      ];

      return $build;
    }

    foreach ($publications as $index => $publication) {
      $build['publication_' . $index] = [
        '#type' => 'details',
        '#title' => ucfirst((string) ($publication->get('marketplace_key')->value ?? 'Marketplace')),
        '#open' => TRUE,
        'table' => [
          '#type' => 'table',
          '#header' => [
            'field' => $this->t('Field'),
            'value' => $this->t('Value'),
          ],
          '#rows' => $this->buildPublicationRows($publication),
        ],
        'actions' => $this->buildPublicationActions($publication, $bb_ai_listing),
      ];
    }

    return $build;
  }

  public function getTitle(BbAiListing $bb_ai_listing): string {
    $label = trim((string) $bb_ai_listing->label());
    if ($label === '') {
      return 'Marketplaces';
    }

    return sprintf('Marketplaces: %s', $label);
  }

  /**
   * @return \Drupal\ai_listing\Entity\AiMarketplacePublication[]
   */
  private function loadMarketplacePublications(int $listingId): array {
    $storage = $this->listingEntityTypeManager->getStorage('ai_marketplace_publication');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', $listingId)
      ->sort('marketplace_key')
      ->sort('changed')
      ->execute();

    if ($ids === []) {
      return [];
    }

    /** @var \Drupal\ai_listing\Entity\AiMarketplacePublication[] $publications */
    $publications = $storage->loadMultiple($ids);
    return array_values($publications);
  }

  /**
   * @return array<int,array<int,string>>
   */
  private function buildPublicationRows(AiMarketplacePublication $publication): array {
    return [
      ['Marketplace', (string) ($publication->get('marketplace_key')->value ?? '')],
      ['Status', (string) ($publication->get('status')->value ?? '')],
      ['SKU', (string) ($publication->get('inventory_sku_value')->value ?? '')],
      ['Publication type', (string) ($publication->get('publication_type')->value ?? '')],
      ['Marketplace publication ID', (string) ($publication->get('marketplace_publication_id')->value ?? '')],
      ['Marketplace listing ID', (string) ($publication->get('marketplace_listing_id')->value ?? '')],
      ['Source', (string) ($publication->get('source')->value ?? '')],
      ['Published at', $this->formatTimestamp($publication->get('published_at')->value)],
      ['Marketplace started at', $this->formatTimestamp($publication->get('marketplace_started_at')->value)],
      ['Last error', (string) ($publication->get('last_error_message')->value ?? '')],
    ];
  }

  /**
   * @return array<string,mixed>
   */
  private function buildPublicationActions(AiMarketplacePublication $publication, BbAiListing $listing): array {
    $marketplaceKey = trim((string) ($publication->get('marketplace_key')->value ?? ''));
    if ($marketplaceKey !== 'ebay') {
      return ['#markup' => ''];
    }

    return [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-listing-marketplace-actions'],
      ],
      'unpublish' => $this->listingFormBuilder->getForm(
        \Drupal\listing_publishing\Form\MarketplacePublicationUnpublishForm::class,
        (int) $publication->id(),
        '/admin/ai-listings/' . (int) $listing->id() . '/marketplaces'
      ),
    ];
  }

  private function formatTimestamp(mixed $timestamp): string {
    if (!is_numeric($timestamp) || (int) $timestamp <= 0) {
      return '';
    }

    return $this->dateFormatter->format((int) $timestamp, 'custom', 'Y-m-d H:i');
  }

}
