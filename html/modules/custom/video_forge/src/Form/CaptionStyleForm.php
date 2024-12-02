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

    // Add a general description for the form.
    $form['description'] = [
        '#markup' => $this->t('<p>The Caption Style configuration allows you to define custom styles for ASS subtitle files. '
            . 'Each style type serves a specific purpose:</p>'
            . '<ul>'
            . '<li><strong>Plain:</strong> Simple subtitle styles with no animation or highlighting.</li>'
            . '<li><strong>Sequence:</strong> Styles designed for dynamic word-by-word highlighting, often used in tutorials or presentations.</li>'
            . '<li><strong>Karaoke:</strong> Designed for karaoke lyrics, enabling word-based animations and synchronized text effects.</li>'
            . '</ul>'
            . '<p>Styles are applied to ASS files generated during video processing. Configure the fields below to customize the appearance and behavior of your subtitles.</p>'),
    ];

    // Existing fields for label and machine name.
    $form['label'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Label'),
        '#maxlength' => 255,
        '#default_value' => $entity->label(),
        '#required' => TRUE,
    ];

    $form['id'] = [
        '#type' => 'machine_name',
        '#default_value' => $entity->id(),
        '#machine_name' => [
            'exists' => ['\Drupal\video_forge\Entity\CaptionStyle', 'load'],
        ],
        '#disabled' => !$entity->isNew(),
    ];

    // Style type field with description.
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
        '#description' => $this->t('<p>Select the type of style you want to configure:</p>'
            . '<ul>'
            . '<li><strong>Plain:</strong> Basic subtitle styling without animations or effects.</li>'
            . '<li><strong>Sequence:</strong> Highlights words dynamically, suitable for educational or presentation purposes.</li>'
            . '<li><strong>Karaoke:</strong> Perfect for karaoke lyrics with synchronized word animations.</li>'
            . '</ul>'),
    ];

    // Add the common fields and highlight-specific fields.
    $form += $this->buildCommonFields($entity->toArray());
    $form += $this->buildHighlightFieldsets($entity->toArray());

    // Add the actions (submit button).
    $form['actions'] = [
        '#type' => 'actions',
        'submit' => [
            '#type' => 'submit',
            '#value' => $this->t('Save'),
            '#submit' => ['::submitForm'],
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

