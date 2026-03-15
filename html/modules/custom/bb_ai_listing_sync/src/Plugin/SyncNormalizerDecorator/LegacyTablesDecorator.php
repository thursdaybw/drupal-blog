<?php

declare(strict_types=1);

namespace Drupal\bb_ai_listing_sync\Plugin\SyncNormalizerDecorator;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\bb_ai_listing_sync\Contract\ListingSyncGraphBuilderInterface;
use Drupal\bb_ai_listing_sync\Model\ListingSyncGraph;
use Drupal\bb_ai_listing_sync\Service\ListingGraphPruneService;
use Drupal\bb_ai_listing_sync\Service\LegacyTableSyncService;
use Drupal\content_sync\Plugin\SyncNormalizerDecoratorBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
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
    private readonly ListingSyncGraphBuilderInterface $graphBuilder,
    private readonly ListingGraphPruneService $listingGraphPruneService,
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
      $container->get('bb_ai_listing_sync.export_graph_builder'),
      $container->get('bb_ai_listing_sync.listing_graph_prune'),
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

    if ($entity instanceof BbAiListing) {
      $graph = $this->graphBuilder->buildForListing($entity);
      $normalized_entity['_bb_ai_listing_sync']['expected_uuids'] = $this->buildExpectedUuidPayload($graph);
    }
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

    $expectedUuids = $normalized_entity['_bb_ai_listing_sync']['expected_uuids'] ?? NULL;
    if (is_array($expectedUuids)) {
      $this->listingGraphPruneService->stageImportPayload($listingUuid, [
        'expected_uuids' => $expectedUuids,
      ]);
    }
  }

  /**
   * @return array<string, array<int, string>>
   */
  private function buildExpectedUuidPayload(ListingSyncGraph $graph): array {
    $byType = $graph->entitiesByType();
    $payload = [];

    foreach ($byType as $entityType => $entities) {
      if ($entityType === 'bb_ai_listing') {
        continue;
      }
      $payload[$entityType] = $this->extractEntityUuids($entities);
    }

    return $payload;
  }

  /**
   * @param array<int, \Drupal\Core\Entity\EntityInterface> $entities
   *
   * @return array<int, string>
   */
  private function extractEntityUuids(array $entities): array {
    $uuids = [];
    foreach ($entities as $entity) {
      if (!$entity instanceof EntityInterface) {
        continue;
      }
      $uuid = (string) $entity->uuid();
      if ($uuid !== '') {
        $uuids[$uuid] = $uuid;
      }
    }

    return array_values($uuids);
  }

}
