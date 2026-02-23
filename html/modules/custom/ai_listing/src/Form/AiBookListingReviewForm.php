<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;

final class AiBookListingReviewForm extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(\Symfony\Component\DependencyInjection\ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
    );
  }

  public function getFormId(): string {
    return 'ai_book_listing_review_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state, AiBookListing $ai_book_listing = NULL): array {

    $form_state->set('listing', $ai_book_listing);

    // ===== SUMMARY =====

    $form['title'] = [
      '#type' => 'textfield',
      '#title' => 'Title',
      '#default_value' => $ai_book_listing->get('title')->value,
      '#required' => TRUE,
    ];

    $form['author'] = [
      '#type' => 'textfield',
      '#title' => 'Author',
      '#default_value' => $ai_book_listing->get('author')->value,
    ];

    $form['ebay_title'] = [
      '#type' => 'textfield',
      '#title' => 'eBay Title',
      '#default_value' => $ai_book_listing->get('ebay_title')->value,
    ];

    // ===== CONDITION =====

    $form['condition'] = [
      '#type' => 'details',
      '#title' => $this->t('Condition'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    // Determine current values properly.
    $conditionState = $form_state->getValue('condition');

    if (is_array($conditionState) && isset($conditionState['condition_issues'])) {
      // Rebuild or AJAX case
      $existingIssues = array_values(array_filter($conditionState['condition_issues']));
    }
    else {
      // Initial load from entity
      $existingIssues = [];
      foreach ($ai_book_listing->get('condition_issues') as $item) {
        if (!empty($item->value)) {
          $existingIssues[] = (string) $item->value;
        }
      }

      if (!in_array('edge wear', $existingIssues, TRUE)) {
        $existingIssues[] = 'edge wear';
      }
    }

    $form['condition']['condition_issues'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Condition issues'),
      '#options' => [
        'ex_library' => $this->t('Ex-library'),
        'gift inscription/pen marks' => $this->t('Gift inscription, pen marks'),
        'foxing' => $this->t('Foxing'),
        'tearing' => $this->t('Tearing'),
        'tanning/toning' => $this->t('Tanning, Toning'),
        'edge wear' => $this->t('Edge wear'),
        'dust jacket damage' => $this->t('Dust jacket damage'),
        'surface wear' => $this->t('Surface wear'),
        'paper ageing' => $this->t('Paper ageing'),
        'staining' => $this->t('Staining'),
      ],
      '#default_value' => array_combine($existingIssues, $existingIssues),
      '#attributes' => ['class' => ['ai-issue-checkboxes']],
      '#ajax' => [
        'callback' => '::ajaxConditionNote',
        'wrapper' => 'condition-note-wrapper',
        'event' => 'change',
        'disable-refocus' => TRUE,
        'progress' => [
          'type' => 'none',
        ],
      ],
    ];

    // Build note
    if (is_array($conditionState) && isset($conditionState['condition_note'])) {
      $defaultNote = $conditionState['condition_note'];
    }
    else {
      $defaultNote = $this->buildConditionNote($existingIssues);
    }

    $form['condition']['condition_note_wrapper'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'condition-note-wrapper'],
    ];

    $form['condition']['condition_note_wrapper']['condition_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Condition note'),
      '#default_value' => $defaultNote,
      '#rows' => 3,
    ];


    // ===== DESCRIPTION =====

    $form['description'] = [
      '#type' => 'textarea',
      '#title' => 'Description',
      '#default_value' => $ai_book_listing->get('description')->value,
      '#rows' => 12,
    ];

    // ===== PHOTOS =====

    $form['photos'] = [
      '#type' => 'details',
      '#title' => 'Photos',
      '#open' => TRUE,
    ];

    $fileStorage = $this->entityTypeManager->getStorage('file');
    $photo_items = [];

    foreach ($ai_book_listing->get('images') as $delta => $item) {

      if (empty($item->target_id)) {
        continue;
      }

      $file = $fileStorage->load($item->target_id);
      if (!$file) {
        continue;
      }

      $uri = $file->getFileUri();
      $url = \Drupal\Core\Url::fromUri(
        $this->fileUrlGenerator->generateAbsoluteString($uri)
      );

      $photo_items[] = [
        '#type' => 'link',
        '#title' => [
          '#theme' => 'image_style',
          '#style_name' => 'thumbnail',
          '#uri' => $uri,
        ],
        '#url' => $url,
        '#attributes' => [
          'class' => ['ai-listing-photo-link'],
        ],
      ];
    }

    $form['photos']['gallery'] = [
      '#type' => 'container',
      'items' => $photo_items,
    ];

    // ===== METADATA =====

    $form['metadata'] = [
      '#type' => 'details',
      '#title' => 'Metadata',
      '#open' => TRUE,
    ];

    $metadata_json = $ai_book_listing->get('metadata_json')->value ?? '';

    $form['metadata']['raw'] = [
      '#type' => 'details',
      '#title' => 'Raw JSON (audit only)',
      '#open' => FALSE,
    ];

    $form['metadata']['raw']['json'] = [
      '#type' => 'textarea',
      '#default_value' => $metadata_json,
      '#rows' => 12,
      '#attributes' => ['readonly' => 'readonly'],
    ];

    // ===== ACTIONS =====

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => 'Save Changes',
      '#button_type' => 'primary',
    ];

    $form['#attached']['library'][] = 'ai_listing/photo_viewer';
    $form['#attached']['library'][] = 'ai_listing/review_ui';

    return $form;
  }

  public function getTitle(AiBookListing $ai_book_listing): string {
    return $ai_book_listing->label() ?: 'Review Listing';
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {

    /** @var AiBookListing $listing */
    $listing = $form_state->get('listing');

    $listing->set('title', $form_state->getValue('title'));
    $listing->set('author', $form_state->getValue('author'));
    $listing->set('ebay_title', $form_state->getValue('ebay_title'));
    $listing->set('description', $form_state->getValue('description'));

    $conditionValues = (array) $form_state->getValue('condition');
    $values = (array) ($conditionValues['condition_issues'] ?? []);
    $issues = array_values(array_filter($values));
    $note = (string) ($conditionValues['condition_note_wrapper']['condition_note'] ?? '');

    $listing->set('condition_issues', $issues);

    $conditionPayload = [
      'issues' => $issues,
      'note' => $note,
    ];

    $listing->set('condition_json', json_encode($conditionPayload, JSON_PRETTY_PRINT));

    $listing->save();

    $this->messenger()->addStatus('Listing updated.');
  }

  private function humanizeIssue(string $issue): string {
    $map = [
      'ex_library' => 'ex-library markings',
      'gift inscription/pen marks' => 'gift inscription or pen marks',
      'foxing' => 'foxing',
      'tearing' => 'tearing',
      'tanning/toning' => 'tanning and toning',
      'edge wear' => 'edge wear',
      'dust jacket damage' => 'dust jacket damage',
      'surface wear' => 'surface wear',
      'paper ageing' => 'paper ageing',
      'staining' => 'staining',
      // Allow common variants if your AI returns them.
      'tanning/toning' => 'tanning and toning',
      'paper aging' => 'paper ageing',
    ];

    $issue = trim($issue);
    return $map[$issue] ?? $issue;
  }

  private function joinIssuesForSentence(array $issues): string {
    $issues = array_values(array_filter(array_map('trim', $issues)));
    $issues = array_values(array_unique($issues));

    if (count($issues) === 0) {
      return '';
    }
    if (count($issues) === 1) {
      return $issues[0];
    }
    if (count($issues) === 2) {
      return $issues[0] . ' and ' . $issues[1];
    }

    $last = array_pop($issues);
    return implode(', ', $issues) . ' and ' . $last;
  }

  private function buildConditionNote(array $issueKeys): string {
    $issueKeys = array_values(array_filter($issueKeys));

    $phrases = array_map(fn(string $k) => $this->humanizeIssue($k), $issueKeys);
    $list = $this->joinIssuesForSentence($phrases);

    $base = 'This item is pre-owned and shows signs of previous use';
    if ($list !== '') {
      $base .= ' with ' . $list . '.';
    }
    else {
      $base .= '.';
    }

    return $base . " Please see photos for full details.";
  }

  public function ajaxConditionNote(array &$form, FormStateInterface $form_state): array {

    $conditionValues = (array) $form_state->getValue('condition');
    $issueValues = (array) ($conditionValues['condition_issues'] ?? []);

    $selected = array_values(array_filter($issueValues));

    $newNote = $this->buildConditionNote($selected);

    // Set value at correct tree path.
    $form_state->setValue(
      ['condition', 'condition_note_wrapper', 'condition_note'],
      $newNote
    );

    // Update element default for rebuild.
    $form['condition']['condition_note_wrapper']['condition_note']['#value'] = $newNote;

    return $form['condition']['condition_note_wrapper'];
  }

}
