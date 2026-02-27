<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Entity\BundleEntityFormBase;
use Drupal\Core\Form\FormStateInterface;

final class BbAiListingTypeForm extends BundleEntityFormBase {

  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $listingType = $this->entity;

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#default_value' => $listingType->label(),
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $listingType->id(),
      '#machine_name' => [
        'exists' => '\Drupal\ai_listing\Entity\BbAiListingType::load',
      ],
      '#disabled' => !$listingType->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => (string) $listingType->get('description'),
      '#rows' => 3,
    ];

    return $form;
  }

  public function save(array $form, FormStateInterface $form_state): int {
    $status = parent::save($form, $form_state);
    $this->messenger()->addStatus($this->t('Saved listing type %label.', [
      '%label' => $this->entity->label(),
    ]));
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $status;
  }

}

