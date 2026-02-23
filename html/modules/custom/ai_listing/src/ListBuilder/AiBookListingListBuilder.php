<?php

declare(strict_types=1);

namespace Drupal\ai_listing\ListBuilder;

use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class AiBookListingListBuilder extends EntityListBuilder {

  private DateFormatterInterface $dateFormatter;
  private ?string $statusFilter = NULL;

  public function __construct(
    EntityTypeInterface $entity_type,
    EntityStorageInterface $storage,
    DateFormatterInterface $date_formatter,
  ) {
    parent::__construct($entity_type, $storage);
    $this->dateFormatter = $date_formatter;
  }

  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type): self {
    /** @var EntityTypeManagerInterface $entity_type_manager */
    $entity_type_manager = $container->get('entity_type.manager');

    return new self(
      $entity_type,
      $entity_type_manager->getStorage($entity_type->id()),
      $container->get('date.formatter')
    );
  }

  public function buildHeader(): array {
    $header['title'] = $this->t('Title');
    $header['author'] = $this->t('Author');
    $header['status'] = $this->t('Status');
    $header['updated'] = $this->t('Updated');
    return $header + parent::buildHeader();
  }

  public function buildRow(EntityInterface $entity): array {
    /** @var AiBookListing $entity */
    $parent_row = parent::buildRow($entity);
    $operations = $parent_row['operations'] ?? null;

    $row = [];
    $row['title'] = $entity->toLink($entity->label() ?: $this->t('(Untitled)'));
    $row['author'] = $entity->get('author')->value;
    $row['status'] = $entity->get('status')->value;

    $changed = $entity->get('changed')->value;
    $row['updated'] = $changed ? $this->dateFormatter->format($changed) : $this->t('n/a');

    if ($operations !== null) {
      $row['operations'] = $operations;
    }

    return $row;
  }

  public function getOperations(EntityInterface $entity): array {
    $operations = parent::getOperations($entity);

    if ($entity->access('view') && $entity->hasLinkTemplate('canonical')) {
      $viewUrl = $this->ensureDestination($entity->toUrl('canonical'));
      $operations['view'] = [
        'title' => $this->t('View'),
        'weight' => -20,
        'url' => $viewUrl,
      ];
    }

    return $operations;
  }

  public function setStatusFilter(?string $status): self {
    $this->statusFilter = $status;
    return $this;
  }

  protected function getEntityListQuery(): QueryInterface {
    $query = parent::getEntityListQuery();

    if ($this->statusFilter !== NULL) {
      $query->condition('status', $this->statusFilter);
    }

    return $query;
  }

}
