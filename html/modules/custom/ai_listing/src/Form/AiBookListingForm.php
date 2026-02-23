<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Form\FormStateInterface;

final class AiBookListingForm extends ContentEntityForm {

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form = parent::buildForm($form, $form_state);

    // Hide raw JSON fields from the editing UI; they're kept for diagnostics.
    if (isset($form['metadata_json'])) {
      $form['metadata_json']['#access'] = FALSE;
    }
    if (isset($form['condition_json'])) {
      $form['condition_json']['#access'] = FALSE;
    }

    return $form;
  }

}
