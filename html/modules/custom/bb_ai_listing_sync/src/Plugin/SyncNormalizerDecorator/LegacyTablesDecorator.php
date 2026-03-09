<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Plugin\SyncNormalizerDecorator;

use Drupal\bb_ai_listing_sync\Service\LegacyTableSyncService;
use Drupal\content_sync\Plugin\SyncNormalizerDecoratorBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Adds and consumes bb_ai_listing legacy table payload in content_sync YAML.
 *
 * @SyncNormalizerDecorator(
 *   id = "bb_ai_listing_legacy_tables",
 *   name = @Translation("BB AI Listing legacy tables")
 * )
 */
final class LegacyTablesDecorator extends SyncNormalizerDecoratorBase implements ContainerFactoryPluginInterface {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    private readonly LegacyTableSyncService $legacyTableSyncService,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new self(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('bb_ai_listing_sync.legacy_table_sync'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function decorateNormalization(array &$normalized_entity, ContentEntityInterface $entity, $format, array $context = []): void {
    if ($entity->getEntityTypeId() !== 'bb_ai_listing') {
      return;
    }

    $listingId = (int) $entity->id();
    if ($listingId <= 0) {
      return;
    }

    $legacyRows = $this->legacyTableSyncService->exportLegacyRowsForListing($listingId);
    $normalized_entity['_bb_ai_listing_sync']['legacy_tables'] = $legacyRows;
  }

  /**
   * {@inheritdoc}
   */
  public function decorateDenormalizedEntity(ContentEntityInterface $entity, array $normalized_entity, $format, array $context = []): void {
    if ($entity->getEntityTypeId() !== 'bb_ai_listing') {
      return;
    }

    $legacyTables = $normalized_entity['_bb_ai_listing_sync']['legacy_tables'] ?? NULL;
    if (!is_array($legacyTables)) {
      return;
    }

    $listingUuid = (string) $entity->uuid();
    if ($listingUuid === '') {
      return;
    }

    $this->legacyTableSyncService->stageImportPayload($listingUuid, $legacyTables);
  }

}
