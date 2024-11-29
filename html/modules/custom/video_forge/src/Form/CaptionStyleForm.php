<?php

declare(strict_types=1);

namespace Drupal\video_forge\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Form\FormStateInterface;
use Drupal\video_forge\Entity\CaptionStyle;

/**
 * Caption Style form.
 */
final class CaptionStyleForm extends EntityForm {

  /**
   * {@inheritdoc}
   */
  public function form(array $form, FormStateInterface $form_state): array {
    $form = parent::form($form, $form_state);

    $form['label'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Label'),
      '#maxlength' => 255,
      '#default_value' => $this->entity->label(),
      '#required' => TRUE,
    ];

    $form['id'] = [
      '#type' => 'machine_name',
      '#default_value' => $this->entity->id(),
      '#machine_name' => [
        'exists' => [CaptionStyle::class, 'load'],
      ],
      '#disabled' => !$this->entity->isNew(),
    ];

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Description'),
      '#default_value' => $this->entity->get('description'),
    ];

    $form['type'] = [
      '#type' => 'select',
      '#title' => $this->t('Style Type'),
      '#options' => [
        'sequence' => $this->t('Sequence'),
        'karaoke' => $this->t('Karaoke'),
        'plain' => $this->t('Plain'),
      ],
      '#default_value' => $this->entity->get('type'),
      '#required' => TRUE,
    ];

    $form['fontName'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Font Name'),
      '#default_value' => $this->entity->get('fontName'),
    ];

    $form['fontSize'] = [
      '#type' => 'number',
      '#title' => $this->t('Font Size'),
      '#default_value' => $this->entity->get('fontSize'),
      '#min' => 10,
      '#max' => 100,
    ];

    $form['primaryColour'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Primary Colour'),
      '#default_value' => $this->entity->get('primaryColour'),
    ];

    $form['secondaryColour'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Secondary Colour'),
      '#default_value' => $this->entity->get('secondaryColour'),
    ];

    $form['outlineColour'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Outline Colour'),
      '#default_value' => $this->entity->get('outlineColour'),
    ];

    $form['bold'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Bold'),
      '#default_value' => $this->entity->get('bold') === -1,
    ];

    $form['italic'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Italic'),
      '#default_value' => $this->entity->get('italic'),
    ];

    $form['underline'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Underline'),
      '#default_value' => $this->entity->get('underline'),
    ];

    $form['scaleX'] = [
      '#type' => 'number',
      '#title' => $this->t('Scale X'),
      '#default_value' => $this->entity->get('scaleX'),
    ];

    $form['scaleY'] = [
      '#type' => 'number',
      '#title' => $this->t('Scale Y'),
      '#default_value' => $this->entity->get('scaleY'),
    ];

    $form['alignment'] = [
      '#type' => 'select',
      '#title' => $this->t('Alignment'),
      '#options' => [
        1 => $this->t('Left'),
        2 => $this->t('Center'),
        3 => $this->t('Right'),
      ],
      '#default_value' => $this->entity->get('alignment'),
    ];

    $form['marginL'] = [
      '#type' => 'number',
      '#title' => $this->t('Margin Left'),
      '#default_value' => $this->entity->get('marginL'),
    ];

    $form['marginR'] = [
      '#type' => 'number',
      '#title' => $this->t('Margin Right'),
      '#default_value' => $this->entity->get('marginR'),
    ];

    $form['marginV'] = [
      '#type' => 'number',
      '#title' => $this->t('Margin Vertical'),
      '#default_value' => $this->entity->get('marginV'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, FormStateInterface $form_state): int {
    $result = parent::save($form, $form_state);
    $message_args = ['%label' => $this->entity->label()];
    $this->messenger()->addStatus(
      match($result) {
        \SAVED_NEW => $this->t('Created new caption style %label.', $message_args),
        \SAVED_UPDATED => $this->t('Updated caption style %label.', $message_args),
      }
    );
    $form_state->setRedirectUrl($this->entity->toUrl('collection'));
    return $result;
  }
}

