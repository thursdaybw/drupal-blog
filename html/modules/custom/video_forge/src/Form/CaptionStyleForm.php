<?php

declare(strict_types=1);

namespace Drupal\video_forge\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Caption Style configuration form.
 */
final class CaptionStyleForm extends EntityForm {

  use CaptionFormTrait;

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\video_forge\Entity\CaptionStyle $entity */
    $entity = $this->entity;

    $form += $this->buildCommonFields($entity->toArray());
    $form += $this->buildHighlightFieldsets($entity->toArray());

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\video_forge\Entity\CaptionStyle $entity */
    $entity = $this->entity;
    $this->saveFields($form_state, $entity);
    return $entity->save();
  }
}

