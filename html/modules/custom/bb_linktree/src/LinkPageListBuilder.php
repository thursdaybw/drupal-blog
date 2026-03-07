<?php

declare(strict_types=1);

namespace Drupal\bb_linktree;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityListBuilder;

/**
 * Lists link page entities.
 */
final class LinkPageListBuilder extends EntityListBuilder {

  /**
   * {@inheritdoc}
   */
  public function buildHeader(): array {
    $header['id'] = $this->t('ID');
    $header['title'] = $this->t('Title');
    $header['path_segment'] = $this->t('Path segment');
    $header['status'] = $this->t('Status');
    $header['is_default'] = $this->t('Default');
    $header['changed'] = $this->t('Updated');

    return $header + parent::buildHeader();
  }

  /**
   * {@inheritdoc}
   */
  public function buildRow(EntityInterface $entity): array {
    $row['id'] = $entity->id();
    $row['title'] = $entity->toLink();
    $row['path_segment'] = $entity->get('path_segment')->value;
    $row['status'] = $entity->get('status')->value ? $this->t('Published') : $this->t('Unpublished');
    $row['is_default'] = $entity->get('is_default')->value ? $this->t('Yes') : $this->t('No');
    $row['changed']['data'] = $entity->get('changed')->view(['label' => 'hidden']);

    return $row + parent::buildRow($entity);
  }

}
