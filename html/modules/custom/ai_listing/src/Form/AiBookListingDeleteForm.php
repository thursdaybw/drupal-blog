<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Entity\ContentEntityDeleteForm;

final class AiBookListingDeleteForm extends ContentEntityDeleteForm {

  public function getQuestion(): string {
    $label = $this->getEntity()->label();
    return $this->t('Are you sure you want to delete the AI book listing for %title?', ['%title' => $label ?: $this->t('untitled book')]);
  }

}
