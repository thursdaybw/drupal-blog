<?php

declare(strict_types=1);

namespace Drupal\bb_linktree;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists link item entities.
 */
final class LinkItemListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['label'] = $this->t('Label');
    $header['link_page'] = $this->t('Link page');
    $header['weight'] = $this->t('Weight');
    $header['status'] = $this->t('Status');
    $header['changed'] = $this->t('Updated');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $link_page = $entity->get('link_page')->entity;

    $row['id'] = $entity->id();
    $row['label'] = $entity->toLink();
    $row['link_page'] = $link_page ? $link_page->toLink()->toString() : $this->t('Missing');
    $row['weight'] = $entity->get('weight')->value;
    $row['status'] = $entity->get('status')->value ? $this->t('Published') : $this->t('Unpublished');
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);

    return $row + parent::buildRow($entity);
  }

}
