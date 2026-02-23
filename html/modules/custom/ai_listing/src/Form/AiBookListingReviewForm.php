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

    // ===== BASIC DETAILS =====

    $form['basic'] = [
      '#type' => 'details',
      '#title' => 'Basic details',
      '#open' => TRUE,
    ];


    // ===== EBAY LISTING =====

    $form['ebay'] = [
      '#type' => 'details',
      '#title' => 'eBay listing',
      '#open' => TRUE,
    ];

    $form['ebay']['ebay_title'] = [
      '#type' => 'textfield',
      '#title' => 'eBay Title',
      '#default_value' => $ai_book_listing->get('ebay_title')->value,
    ];

    $form['ebay']['description'] = [
      '#type' => 'textarea',
      '#title' => 'Description',
      '#default_value' => $ai_book_listing->get('description')->value,
      '#rows' => 12,
    ];

    $form['basic']['title'] = [
      '#type' => 'textfield',
      '#title' => 'Title',
      '#default_value' => $ai_book_listing->get('title')->value,
      '#required' => TRUE,
    ];

    $form['basic']['subtitle'] = [
      '#type' => 'textfield',
      '#title' => 'Subtitle',
      '#default_value' => $ai_book_listing->get('subtitle')->value,
    ];

    $form['basic']['full_title'] = [
      '#type' => 'textfield',
      '#title' => 'Full title',
      '#default_value' => $ai_book_listing->get('full_title')->value,
    ];

    $form['basic']['author'] = [
      '#type' => 'textfield',
      '#title' => 'Author',
      '#default_value' => $ai_book_listing->get('author')->value,
    ];

    // ===== CONDITION =====

    $form['condition'] = [
      '#type' => 'details',
      '#title' => $this->t('Condition'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    // Grade (normal radios, no container hack)
    $form['condition']['condition_grade'] = [
      '#type' => 'container',
      '#title' => $this->t('Grade'),
      '#theme' => 'ai_tile_radios',
      '#options' => [
        'acceptable' => $this->t('Acceptable'),
        'good' => $this->t('Good'),
        'very_good' => $this->t('Very good'),
        'like_new' => $this->t('Like new'),
      ],
      '#name' => 'condition[condition_grade]',
      '#value' => $ai_book_listing->get('condition_grade')->value ?? 'good',
    ];

    // Issues (flat, no panel, no wrapper)
    $existingIssues = [];
    foreach ($ai_book_listing->get('condition_issues') as $item) {
      if (!empty($item->value)) {
        $existingIssues[] = (string) $item->value;
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
      '#default_value' => $existingIssues,
      '#theme' => 'ai_tile_checkboxes',
    ];

    // Note (flat, no wrapper)
    $form['condition']['condition_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Condition note'),
      '#default_value' => $this->buildConditionNote($existingIssues),
      '#rows' => 3,
    ];



    $form['basic']['isbn'] = [
      '#type' => 'textfield',
      '#title' => 'ISBN',
      '#default_value' => $ai_book_listing->get('isbn')->value,
    ];

    $form['basic']['publisher'] = [
      '#type' => 'textfield',
      '#title' => 'Publisher',
      '#default_value' => $ai_book_listing->get('publisher')->value,
    ];

    $form['basic']['publication_year'] = [
      '#type' => 'textfield',
      '#title' => 'Publication year',
      '#default_value' => $ai_book_listing->get('publication_year')->value,
    ];

    $form['basic']['edition'] = [
      '#type' => 'textfield',
      '#title' => 'Edition',
      '#default_value' => $ai_book_listing->get('edition')->value,
    ];

    $form['basic']['series'] = [
      '#type' => 'textfield',
      '#title' => 'Series',
      '#default_value' => $ai_book_listing->get('series')->value,
    ];

    // ===== CLASSIFICATION =====

    $form['classification'] = [
      '#type' => 'details',
      '#title' => 'Classification',
      '#open' => FALSE,
    ];

    $form['classification']['format'] = [
      '#type' => 'textfield',
      '#title' => 'Format',
      '#default_value' => $ai_book_listing->get('format')->value,
    ];

    $form['classification']['language'] = [
      '#type' => 'textfield',
      '#title' => 'Language',
      '#default_value' => $ai_book_listing->get('language')->value,
    ];

    $form['classification']['genre'] = [
      '#type' => 'textfield',
      '#title' => 'Genre',
      '#default_value' => $ai_book_listing->get('genre')->value,
    ];

    $form['classification']['narrative_type'] = [
      '#type' => 'textfield',
      '#title' => 'Narrative type',
      '#default_value' => $ai_book_listing->get('narrative_type')->value,
    ];

    $form['classification']['country_printed'] = [
      '#type' => 'textfield',
      '#title' => 'Country printed',
      '#default_value' => $ai_book_listing->get('country_printed')->value,
    ];

    // ===== FEATURES =====

    $form['features'] = [
      '#type' => 'textarea',
      '#title' => 'Features (one per line)',
      '#default_value' => implode("\n", array_filter(array_map(
        fn($item) => $item->value,
        iterator_to_array($ai_book_listing->get('features'))
      ))),
      '#rows' => 5,
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

    $form['photos']['items'] = $photo_items;

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

    $listing->set('title', $form_state->getValue(['basic', 'title']));
    $listing->set('subtitle', $form_state->getValue(['basic', 'subtitle']));
    $listing->set('full_title', $form_state->getValue(['basic', 'full_title']));
    $listing->set('author', $form_state->getValue(['basic', 'author']));
    $listing->set('isbn', $form_state->getValue(['basic', 'isbn']));
    $listing->set('publisher', $form_state->getValue(['basic', 'publisher']));
    $listing->set('publication_year', $form_state->getValue(['basic', 'publication_year']));
    $listing->set('edition', $form_state->getValue(['basic', 'edition']));
    $listing->set('series', $form_state->getValue(['basic', 'series']));

    $listing->set('format', $form_state->getValue(['classification', 'format']));
    $listing->set('language', $form_state->getValue(['classification', 'language']));
    $listing->set('genre', $form_state->getValue(['classification', 'genre']));
    $listing->set('narrative_type', $form_state->getValue(['classification', 'narrative_type']));
    $listing->set('country_printed', $form_state->getValue(['classification', 'country_printed']));

    $featuresText = $form_state->getValue('features');
    $features = array_filter(array_map('trim', explode("\n", $featuresText)));
    $listing->set('features', $features);

    $listing->set('ebay_title', $form_state->getValue(['ebay', 'ebay_title']));
    $listing->set('description', $form_state->getValue(['ebay', 'description']));

    $condition = (array) $form_state->getValue('condition');

    $issues = array_values(array_filter(
      (array) ($condition['condition_issues'] ?? [])
    ));

    $note = (string) ($condition['condition_note'] ?? '');

    $grade = (string) ($condition['condition_grade'] ?? 'good');

    $listing->set('condition_grade', $grade);

    $listing->set('condition_issues', $issues);

    $conditionPayload = [
      'issues' => $issues,
      'note' => $note,
      'grade' => $grade,
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



}
