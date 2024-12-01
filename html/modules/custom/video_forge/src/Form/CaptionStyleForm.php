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
  public function getFormId(): string {
    return 'video_forge_caption_style_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    /** @var \Drupal\video_forge\Entity\CaptionStyle $entity */
    $entity = $this->entity;

    // Add the label field for the configuration entity.
    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $entity->label(),
      '#required' => TRUE,
    ];

    // Add the machine name field for the configuration entity.
    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $entity->id(),
      '#machine_name' => [
        'exists' => ['\Drupal\video_forge\Entity\CaptionStyle', 'load'],
      ],
      '#disabled' => !$entity->isNew(),
    ];

    $form['type'] = [
        '#type' => 'select',
        '#title' => $this->t('Style Type'),
        '#options' => [
          'sequence' => $this->t('Sequence'),
          'karaoke' => $this->t('Karaoke'),
          'plain' => $this->t('Plain'),
        ],
        '#default_value' => $entity->get('type') ?? 'plain',
        '#required' => TRUE,
	/*
        '#ajax' => [
          'callback' => '::updateDynamicFields',
          'wrapper' => 'dynamic-fields-wrapper',
        ],
	 */
      ];


    // Add the fields provided by the trait.
    $form += $this->buildCommonFields($entity->toArray());
    $form += $this->buildHighlightFieldsets($entity->toArray());

    // Add the actions (submit button).
    $form['actions'] = [
      '#type' => 'actions',
      'submit' => [
        '#type' => 'submit',
        '#value' => $this->t('Save'),
        '#submit' => ['::submitForm'], // Ensure the submit handler is properly linked.
      ],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    /** @var \Drupal\video_forge\Entity\CaptionStyle $entity */
    $entity = $this->entity;

    // Save the label and other fields.
    $entity->set('label', $form_state->getValue('label'));
    $entity->set('id', $form_state->getValue('id'));

    // Save additional fields using the trait method.
    $this->saveFields($form_state, $entity);

    // Save the entity.
    $result = $entity->save();

    // Display a success message.
    $this->messenger()->addStatus($this->t('Caption style %label has been saved.', ['%label' => $entity->label()]));

    $form_state->setRedirectUrl($entity->toUrl('collection'));
    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->save($form, $form_state); // Call the save method directly.
  }
}

