<?php

declare(strict_types=1);

namespace Drupal\bb_linktree\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for link item entities.
 */
final class LinkItemForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('New link item %label has been created.', [
        '%label' => $this->entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('The link item %label has been updated.', [
        '%label' => $this->entity->label(),
      ]));
    }

    $form_state->setRedirect('entity.bb_linktree_item.canonical', [
      'bb_linktree_item' => $this->entity->id(),
    ]);

    return $result;
  }

}
