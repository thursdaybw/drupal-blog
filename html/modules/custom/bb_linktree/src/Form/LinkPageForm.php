<?php

declare(strict_types=1);

namespace Drupal\bb_linktree\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

/**
 * Form controller for link page entities.
 */
final class LinkPageForm extends ContentEntityForm {

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $path_segment = trim((string) $form_state->getValue('path_segment')[0]['value']);
    $normalized_path_segment = strtolower($path_segment);

    if (!preg_match('/^[a-z0-9-]+$/', $normalized_path_segment)) {
      $form_state->setErrorByName('path_segment', $this->t('Path segment must contain only lowercase letters, numbers, and hyphens.'));
      return;
    }

    $existing_entity_ids = $this->entityTypeManager
      ->getStorage('bb_linktree_page')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('path_segment', $normalized_path_segment)
      ->execute();

    if ($existing_entity_ids) {
      $current_entity_id = $this->entity->id();

      foreach ($existing_entity_ids as $existing_entity_id) {
        if ((int) $existing_entity_id !== (int) $current_entity_id) {
          $form_state->setErrorByName('path_segment', $this->t('That path segment is already in use.'));
          break;
        }
      }
    }

    $form_state->setValue('path_segment', [['value' => $normalized_path_segment]]);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);

    $this->updateDefaultPageAssignment();

    if ($result === SAVED_NEW) {
      $this->messenger()->addStatus($this->t('New link page %label has been created.', [
        '%label' => $this->entity->label(),
      ]));
    }
    else {
      $this->messenger()->addStatus($this->t('The link page %label has been updated.', [
        '%label' => $this->entity->label(),
      ]));
    }

    $form_state->setRedirect('entity.bb_linktree_page.canonical', [
      'bb_linktree_page' => $this->entity->id(),
    ]);

    return $result;
  }

  /**
   * Ensures only one page is marked as the default page.
   */
  private function updateDefaultPageAssignment(): void {
    if (!$this->entity->get('is_default')->value) {
      return;
    }

    $storage = $this->entityTypeManager->getStorage('bb_linktree_page');
    $other_page_ids = $storage
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('is_default', TRUE)
      ->condition('id', $this->entity->id(), '<>')
      ->execute();

    if (!$other_page_ids) {
      return;
    }

    $other_pages = $storage->loadMultiple($other_page_ids);

    foreach ($other_pages as $other_page) {
      $other_page->set('is_default', FALSE);
      $other_page->save();
    }
  }

}
