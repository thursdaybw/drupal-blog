<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\listing_publishing\Service\ListingPublisher;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class AiListingReviewFormBase extends FormBase implements ContainerInjectionInterface {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager,
    protected FileUrlGeneratorInterface $fileUrlGenerator,
    protected ListingPublisher $listingPublisher,
  ) {}

  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
      $container->get('drupal.listing_publishing.publisher'),
    );
  }

  public function buildForm(array $form, FormStateInterface $form_state, ?BbAiListing $bb_ai_listing = null): array {
    $listing = $this->resolveListing($bb_ai_listing);
    $form_state->set('listing', $listing);

    $form['photos'] = [
      '#type' => 'details',
      '#title' => 'Photos',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['photos']['items'] = $this->buildPhotoItems($listing);

    $form['basic'] = [
      '#type' => 'details',
      '#title' => 'Basic details',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['basic_search'] = [
      '#type' => 'markup',
      '#markup' => $this->buildEbaySearchLink($listing),
      '#prefix' => '<div class="ai-help">',
      '#suffix' => '</div>',
      '#weight' => -100,
    ];

    $form['ebay'] = [
      '#type' => 'details',
      '#title' => 'eBay listing',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];

    $form['ebay']['ebay_title'] = [
      '#type' => 'textfield',
      '#title' => 'eBay Title',
      '#default_value' => (string) ($listing->get('ebay_title')->value ?? ''),
    ];

    $form['ebay']['description'] = [
      '#type' => 'text_format',
      '#title' => 'Description',
      '#format' => $listing->get('description')->format ?: 'basic_html',
      '#default_value' => $this->formatDescriptionText((string) ($listing->get('description')->value ?? '')),
      '#rows' => 12,
    ];

    $form['basic']['title'] = [
      '#type' => 'textfield',
      '#title' => 'Title',
      '#default_value' => $this->getStringFieldValue($listing, 'field_title'),
      '#required' => TRUE,
    ];
    $form['basic']['subtitle'] = [
      '#type' => 'textfield',
      '#title' => 'Subtitle',
      '#default_value' => $this->getStringFieldValue($listing, 'field_subtitle'),
    ];
    $form['basic']['full_title'] = [
      '#type' => 'textfield',
      '#title' => 'Full title',
      '#default_value' => $this->getStringFieldValue($listing, 'field_full_title'),
    ];
    $form['basic']['author'] = [
      '#type' => 'textfield',
      '#title' => 'Author',
      '#default_value' => $this->getStringFieldValue($listing, 'field_author'),
    ];
    $form['basic']['price'] = [
      '#type' => 'number',
      '#title' => 'Suggested price',
      '#description' => $this->t('Suggested listing price for eBay (AUD).'),
      '#default_value' => $listing->get('price')->value ?: '29.95',
      '#step' => '0.01',
      '#min' => '0',
      '#required' => TRUE,
    ];
    $form['basic']['storage_location'] = [
      '#type' => 'textfield',
      '#title' => 'Storage location',
      '#description' => $this->t('Set this once the listing is ready to shelve so the SKU can encode where the book lives.'),
      '#default_value' => (string) ($listing->get('storage_location')->value ?? ''),
    ];
    $form['basic']['status'] = [
      '#type' => 'select',
      '#title' => 'Stage',
      '#options' => [
        'new' => $this->t('New'),
        'ready_for_review' => $this->t('Ready for review'),
        'ready_to_shelve' => $this->t('Ready to shelve'),
        'shelved' => $this->t('Shelved'),
        'failed' => $this->t('Failed'),
      ],
      '#default_value' => (string) ($listing->get('status')->value ?? 'new'),
      '#description' => $this->t('Choose the workflow stage for this listing.'),
      '#required' => TRUE,
    ];
    $form['basic']['bargain_bin'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Mark as Bargain Bin'),
      '#description' => $this->t('Use the Bargain Bin shipping policy with the preset description.'),
      '#default_value' => (bool) $listing->get('bargain_bin')->value,
    ];
    $form['basic']['isbn'] = [
      '#type' => 'textfield',
      '#title' => 'ISBN',
      '#default_value' => $this->getStringFieldValue($listing, 'field_isbn'),
    ];
    $form['basic']['publisher'] = [
      '#type' => 'textfield',
      '#title' => 'Publisher',
      '#default_value' => $this->getStringFieldValue($listing, 'field_publisher'),
    ];
    $form['basic']['publication_year'] = [
      '#type' => 'textfield',
      '#title' => 'Publication year',
      '#default_value' => $this->getStringFieldValue($listing, 'field_publication_year'),
    ];
    $form['basic']['series'] = [
      '#type' => 'textfield',
      '#title' => 'Series',
      '#default_value' => $this->getStringFieldValue($listing, 'field_series'),
    ];

    $form['classification'] = [
      '#type' => 'details',
      '#title' => 'Classification',
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
    $form['classification']['format'] = [
      '#type' => 'textfield',
      '#title' => 'Format',
      '#default_value' => $this->getStringFieldValue($listing, 'field_format'),
      '#attributes' => ['autocomplete' => 'off'],
      '#attached' => ['library' => ['ai_listing/format_picker']],
      '#theme' => 'ai_listing_format_field',
      '#format_options' => ['Paperback', 'Hardcover', 'Mixed'],
      '#format_datalist_id' => 'ai-format-options',
    ];
    $form['classification']['language'] = [
      '#type' => 'textfield',
      '#title' => 'Language',
      '#default_value' => $this->getStringFieldValue($listing, 'field_language'),
    ];
    $form['classification']['genre'] = [
      '#type' => 'textfield',
      '#title' => 'Genre',
      '#default_value' => $this->getStringFieldValue($listing, 'field_genre'),
    ];
    $form['classification']['narrative_type'] = [
      '#type' => 'textfield',
      '#title' => 'Narrative type',
      '#default_value' => $this->getStringFieldValue($listing, 'field_narrative_type'),
    ];
    $form['classification']['country_printed'] = [
      '#type' => 'textfield',
      '#title' => 'Country printed',
      '#default_value' => $this->getStringFieldValue($listing, 'field_country_printed'),
    ];

    $form['features'] = [
      '#type' => 'textarea',
      '#title' => 'Features (one per line)',
      '#default_value' => implode("\n", $this->getStringMultiFieldValues($listing, 'field_features')),
      '#rows' => 5,
    ];

    $existingIssues = $this->getConditionIssues($listing);
    $form['condition'] = [
      '#type' => 'details',
      '#title' => $this->t('Condition'),
      '#open' => TRUE,
      '#tree' => TRUE,
    ];
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
      '#value' => (string) ($listing->get('condition_grade')->value ?? 'good'),
    ];
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
        'removable dust jacket damage' => $this->t('Removable dust jacket damage'),
        'surface wear' => $this->t('Surface wear'),
        'paper ageing' => $this->t('Paper ageing'),
        'staining' => $this->t('Staining'),
        'tape/adhesive residue' => $this->t('Tape, adhesive residue'),
      ],
      '#default_value' => $existingIssues,
      '#theme' => 'ai_tile_checkboxes',
    ];
    $form['condition']['condition_note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Condition note'),
      '#default_value' => (string) ($listing->get('condition_note')->value ?? $this->buildConditionNote($existingIssues)),
      '#rows' => 3,
    ];
    $ebayListingId = $this->getEbayMarketplaceListingId($listing);
    if ($ebayListingId !== null) {
      $form['condition']['ebay_listing'] = [
        '#type' => 'markup',
        '#markup' => $this->buildEbayListingLinkMarkup($ebayListingId),
        '#prefix' => '<div class="ai-help">',
        '#suffix' => '</div>',
      ];
    }

    $form['metadata'] = [
      '#type' => 'details',
      '#title' => 'Metadata',
      '#open' => TRUE,
    ];
    $form['metadata']['raw'] = [
      '#type' => 'details',
      '#title' => 'Raw JSON (audit only)',
      '#open' => FALSE,
    ];
    $form['metadata']['raw']['json'] = [
      '#type' => 'textarea',
      '#default_value' => (string) ($listing->get('metadata_json')->value ?? ''),
      '#rows' => 12,
      '#attributes' => ['readonly' => 'readonly'],
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['bargain_bin'] = [
      '#type' => 'button',
      '#value' => 'Apply Bargain Bin $1.99',
      '#attributes' => [
        'id' => 'apply-bargain-bin-199',
        'class' => ['button', 'button--secondary'],
        'data-bargain-price' => '1.99',
      ],
    ];
    $form['actions']['bargain_bin_299'] = [
      '#type' => 'button',
      '#value' => 'Apply Bargain Bin $2.99',
      '#attributes' => [
        'id' => 'apply-bargain-bin-299',
        'class' => ['button', 'button--secondary'],
        'data-bargain-price' => '2.99',
      ],
    ];

    $currentStatus = (string) ($listing->get('status')->value ?? '');
    if ($currentStatus === 'ready_for_review') {
      $form['actions']['mark_ready_to_shelve'] = [
        '#type' => 'submit',
        '#value' => $this->t('Mark ready to shelve and continue'),
        '#submit' => ['::submitAndSetReadyToShelve'],
        '#button_type' => 'primary',
        '#name' => 'mark_ready_to_shelve',
      ];
    }
    else {
      $form['actions']['publish'] = [
        '#type' => 'submit',
        '#value' => 'Publish to eBay',
        '#submit' => ['::submitAndPublish'],
      ];
    }

    $form['actions']['save'] = [
      '#type' => 'submit',
      '#value' => 'Save Changes',
      '#button_type' => 'primary',
      '#name' => 'ai_save_listing',
    ];
    $form['actions']['delete'] = [
      '#type' => 'submit',
      '#value' => 'Delete listing',
      '#name' => 'ai_delete_listing',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitRedirectToDeleteForm'],
      '#attributes' => [
        'class' => ['button', 'button--danger'],
      ],
    ];

    $form['#attached']['library'][] = 'ai_listing/review_ui';
    $form['#attached']['library'][] = 'ai_listing/bargain_preset';
    $form['#attached']['library'][] = 'ai_listing/photo_viewer';

    return $form;
  }

  public function getTitle(?BbAiListing $bb_ai_listing = null): string {
    $listing = $this->resolveListing($bb_ai_listing);
    return $listing->label() ?: 'Review Listing';
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\ai_listing\Entity\BbAiListing $listing */
    $listing = $form_state->get('listing');

    $this->setFieldIfExists($listing, 'field_title', $form_state->getValue(['basic', 'title']));
    $this->setFieldIfExists($listing, 'field_subtitle', $form_state->getValue(['basic', 'subtitle']));
    $this->setFieldIfExists($listing, 'field_full_title', $form_state->getValue(['basic', 'full_title']));
    $this->setFieldIfExists($listing, 'field_author', $form_state->getValue(['basic', 'author']));
    $listing->set('price', $form_state->getValue(['basic', 'price']));
    $listing->set('bargain_bin', (bool) $form_state->getValue(['basic', 'bargain_bin']));
    $this->setFieldIfExists($listing, 'field_isbn', $form_state->getValue(['basic', 'isbn']));
    $this->setFieldIfExists($listing, 'field_publisher', $form_state->getValue(['basic', 'publisher']));
    $this->setFieldIfExists($listing, 'field_publication_year', $form_state->getValue(['basic', 'publication_year']));
    $this->setFieldIfExists($listing, 'field_series', $form_state->getValue(['basic', 'series']));
    $this->setFieldIfExists($listing, 'field_format', (string) $form_state->getValue(['classification', 'format']));
    $this->setFieldIfExists($listing, 'field_language', $form_state->getValue(['classification', 'language']));
    $this->setFieldIfExists($listing, 'field_genre', $form_state->getValue(['classification', 'genre']));
    $this->setFieldIfExists($listing, 'field_narrative_type', $form_state->getValue(['classification', 'narrative_type']));
    $this->setFieldIfExists($listing, 'field_country_printed', $form_state->getValue(['classification', 'country_printed']));

    $featuresText = (string) ($form_state->getValue('features') ?? '');
    $features = array_filter(array_map('trim', explode("\n", $featuresText)));
    $this->setFieldIfExists($listing, 'field_features', $features);

    $listing->set('ebay_title', $form_state->getValue(['ebay', 'ebay_title']));
    $description = (array) $form_state->getValue(['ebay', 'description'], []);
    $listing->set('description', [
      'value' => (string) ($description['value'] ?? ''),
      'format' => (string) ($description['format'] ?? 'basic_html'),
    ]);

    $condition = (array) $form_state->getValue('condition', []);
    $issues = array_values(array_filter((array) ($condition['condition_issues'] ?? [])));
    $grade = (string) ($condition['condition_grade'] ?? 'good');
    $note = (string) ($condition['condition_note'] ?? '');
    $listing->set('condition_grade', $grade);
    $listing->set('condition_note', $note);
    $this->setFieldIfExists($listing, 'field_condition_issues', $issues);
    $listing->set('condition_json', json_encode([
      'issues' => $issues,
      'note' => $note,
      'grade' => $grade,
    ], JSON_PRETTY_PRINT));

    $statusValue = $form_state->getValue(['basic', 'status']);
    if ($statusValue !== null) {
      $listing->set('status', $statusValue);
    }

    $storageLocation = $form_state->getValue(['basic', 'storage_location']);
    if ($storageLocation !== null) {
      $listing->set('storage_location', $storageLocation);
    }

    $this->savePhotoSelections($listing, $form_state);
    $listing->save();

    $this->messenger()->addStatus('Listing updated.');

    $trigger = $form_state->getTriggeringElement() ?: [];
    $nextStatusValue = (string) ($form_state->getValue(['basic', 'status']) ?? '');
    if (($trigger['#name'] ?? '') === 'ai_save_listing') {
      if ($nextStatusValue === 'new') {
        $form_state->setRedirect($this->getAddRouteName());
        return;
      }

      if ($nextStatusValue === 'ready_to_shelve') {
        $this->redirectAfterReadyToShelve($form_state);
        return;
      }
    }
  }

  public function submitAndSetReadyToShelve(array &$form, FormStateInterface $form_state): void {
    $form_state->setValue(['basic', 'status'], 'ready_to_shelve');
    $this->submitForm($form, $form_state);
    $this->redirectAfterReadyToShelve($form_state);
  }

  public function submitAndPublish(array &$form, FormStateInterface $form_state): void {
    $this->submitForm($form, $form_state);
    /** @var \Drupal\ai_listing\Entity\BbAiListing $listing */
    $listing = $form_state->get('listing');

    try {
      $result = $this->listingPublisher->publish($listing);
    }
    catch (\Throwable $e) {
      $this->handlePublishFailure($listing, 'Publish failed: ' . $e->getMessage());
      return;
    }

    if (!$result->isSuccess()) {
      $this->handlePublishFailure($listing, 'Publish failed: ' . $result->getMessage());
      return;
    }

    $this->handlePublishSuccess($listing, $result->getMarketplaceId());
  }

  public function submitRedirectToDeleteForm(array &$form, FormStateInterface $form_state): void {
    /** @var \Drupal\ai_listing\Entity\BbAiListing $listing */
    $listing = $form_state->get('listing');
    if (!$listing instanceof BbAiListing) {
      $form_state->setRedirect('ai_listing.location_batch');
      return;
    }

    $selection = [[
      'listing_type' => (string) $listing->bundle(),
      'id' => (int) $listing->id(),
    ]];

    batch_set(AiBookListingLocationBatchForm::buildDeleteBatchDefinition($selection));
    $form_state->setRedirect('ai_listing.location_batch');
  }

  protected function handlePublishFailure(BbAiListing $listing, string $message): void {
    $this->messenger()->addError($message);
    $listing->set('status', 'failed');
    $listing->save();
  }

  protected function handlePublishSuccess(BbAiListing $listing, string $marketplaceId): void {
    $listing->set('status', 'shelved');
    $listing->save();
    $this->messenger()->addStatus(sprintf('Published listing %s for entity %d.', $marketplaceId, $listing->id()));
  }

  protected function redirectAfterReadyToShelve(FormStateInterface $form_state): void {
    $nextId = $this->getNextReadyForReviewId();
    if ($nextId !== null) {
      $form_state->setRedirect('entity.bb_ai_listing.canonical', ['bb_ai_listing' => $nextId]);
      return;
    }

    $form_state->setRedirect('ai_listing.location_batch', [], [
      'query' => ['status_filter' => 'ready_to_shelve'],
    ]);
  }

  protected function getStringFieldValue(BbAiListing $listing, string $fieldName, string $default = ''): string {
    if ($listing->hasField($fieldName)) {
      $value = (string) ($listing->get($fieldName)->value ?? '');
      if ($value !== '') {
        return $value;
      }
    }

    if ($listing->bundle() === 'book_bundle') {
      $fallback = $this->getBundleMetadataFallbackValue($listing, $fieldName);
      if ($fallback !== '') {
        return $fallback;
      }
    }

    return $default;
  }

  /**
   * @return array<int,string>
   */
  protected function getStringMultiFieldValues(BbAiListing $listing, string $fieldName): array {
    if (!$listing->hasField($fieldName)) {
      return [];
    }

    $values = [];
    foreach ($listing->get($fieldName) as $item) {
      if (!empty($item->value)) {
        $values[] = (string) $item->value;
      }
    }

    return $values;
  }

  protected function setFieldIfExists(BbAiListing $listing, string $fieldName, mixed $value): void {
    if (!$listing->hasField($fieldName)) {
      return;
    }

    $listing->set($fieldName, $value);
  }

  /**
   * @return array<int,string>
   */
  protected function getConditionIssues(BbAiListing $listing): array {
    if (!$listing->hasField('field_condition_issues')) {
      return [];
    }

    $issues = [];
    foreach ($listing->get('field_condition_issues') as $item) {
      $value = trim((string) ($item->value ?? ''));
      if ($value !== '') {
        $issues[] = $value;
      }
    }

    return array_values(array_unique($issues));
  }

  /**
   * @param array<int,string> $issueKeys
   */
  protected function buildConditionNote(array $issueKeys): string {
    $phrases = array_map(fn(string $k) => $this->humanizeIssue($k), array_values(array_filter($issueKeys)));
    $list = $this->joinIssuesForSentence($phrases);

    $base = 'This item is pre-owned and shows signs of previous use';
    if ($list !== '') {
      $base .= ' with ' . $list . '.';
    }
    else {
      $base .= '.';
    }

    return $base . ' Please see photos for full details.';
  }

  protected function formatDescriptionText(?string $text): string {
    $text = $text ?? '';
    $text = str_replace(["\r\n", "\r"], "\n", $text);
    $text = preg_replace('/\s*---\s*/', "\n\n---\n\n", $text);
    $text = preg_replace('/\n{3,}/', "\n\n", $text);
    return trim((string) $text);
  }

  protected function buildEbaySearchLink(BbAiListing $listing): string {
    $title = trim($this->getStringFieldValue($listing, 'field_title'));
    $author = trim($this->getStringFieldValue($listing, 'field_author'));
    $query = trim($title . ' ' . $author);
    if ($query === '') {
      return '';
    }

    $url = 'https://www.ebay.com.au/sch/i.html?_nkw=' . UrlHelper::encodePath($query);
    $titleAttr = Html::escape($this->t('Search eBay for ~%query%~', ['%query%' => $query]));
    return sprintf('<a href="%s" target="_blank" rel="noopener noreferrer" title="%s">Search eBay for %s</a>', $url, $titleAttr, Html::escape($query));
  }

  /**
   * @return int[]
   */
  protected function getReadyForReviewIds(): array {
    $ids = $this->entityTypeManager->getStorage('bb_ai_listing')->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', 'ready_for_review')
      ->sort('id', 'ASC')
      ->execute();

    return array_values($ids);
  }

  protected function getNextReadyForReviewId(): ?int {
    $ids = $this->getReadyForReviewIds();
    if ($ids === []) {
      return null;
    }

    return (int) $ids[0];
  }

  private function humanizeIssue(string $issue): string {
    $map = [
      'ex_library' => 'ex-library markings',
      'gift inscription/pen marks' => 'gift inscription or pen marks',
      'foxing' => 'foxing',
      'tearing' => 'tearing',
      'tanning/toning' => 'tanning and toning',
      'edge wear' => 'edge wear',
      'removable dust jacket damage' => 'removable dust jacket damage',
      'surface wear' => 'surface wear',
      'paper ageing' => 'paper ageing',
      'staining' => 'staining',
      'tape/adhesive residue' => 'tape or adhesive residue',
      'paper aging' => 'paper ageing',
    ];

    $trimmed = trim($issue);
    return $map[$trimmed] ?? $trimmed;
  }

  /**
   * @param array<int,string> $issues
   */
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

  private function getBundleMetadataFallbackValue(BbAiListing $listing, string $fieldName): string {
    $metadataJson = (string) ($listing->get('metadata_json')->value ?? '');
    if ($metadataJson === '') {
      return '';
    }

    $decoded = json_decode($metadataJson, true);
    if (!is_array($decoded)) {
      return '';
    }

    $bundleItems = $decoded['bundle_items'] ?? null;
    if (!is_array($bundleItems)) {
      return '';
    }

    $firstItem = $bundleItems[0] ?? null;
    if (!is_array($firstItem)) {
      return '';
    }

    $metadata = $firstItem['metadata'] ?? null;
    if (!is_array($metadata)) {
      return '';
    }

    $fieldMap = [
      'field_title' => 'title',
      'field_subtitle' => 'subtitle',
      'field_full_title' => 'full_title',
      'field_author' => 'author',
      'field_isbn' => 'isbn',
      'field_publisher' => 'publisher',
      'field_publication_year' => 'publication_year',
      'field_series' => 'series',
      'field_format' => 'format',
      'field_language' => 'language',
      'field_genre' => 'genre',
      'field_narrative_type' => 'narrative_type',
      'field_country_printed' => 'country_printed',
      'field_edition' => 'edition',
    ];

    $metadataKey = $fieldMap[$fieldName] ?? null;
    if ($metadataKey === null) {
      return '';
    }

    return trim((string) ($metadata[$metadataKey] ?? ''));
  }

  private function getEbayMarketplaceListingId(BbAiListing $listing): ?string {
    if (!$this->entityTypeManager->hasDefinition('ai_marketplace_publication')) {
      return null;
    }

    $publicationStorage = $this->entityTypeManager->getStorage('ai_marketplace_publication');
    $query = $publicationStorage->getQuery()
      ->accessCheck(FALSE)
      ->condition('listing', (int) $listing->id())
      ->condition('marketplace_key', 'ebay')
      ->condition('marketplace_listing_id', '', '<>')
      ->sort('changed', 'DESC')
      ->sort('id', 'DESC')
      ->range(0, 1);
    $publicationIds = array_values($query->execute());
    if ($publicationIds === []) {
      return null;
    }

    $publicationId = (int) $publicationIds[0];
    $publication = $publicationStorage->load($publicationId);
    if ($publication === null) {
      return null;
    }

    $marketplaceListingId = trim((string) ($publication->get('marketplace_listing_id')->value ?? ''));
    if ($marketplaceListingId === '') {
      return null;
    }

    return $marketplaceListingId;
  }

  private function buildEbayListingLinkMarkup(string $ebayListingId): string {
    $escapedListingId = Html::escape($ebayListingId);
    $url = 'https://www.ebay.com.au/itm/' . rawurlencode($ebayListingId);
    return sprintf(
      '<a href="%s" target="_blank" rel="noopener noreferrer">View eBay listing %s</a>',
      Html::escape($url),
      $escapedListingId
    );
  }

  abstract protected function resolveListing(?BbAiListing $listing): BbAiListing;

  abstract protected function buildPhotoItems(BbAiListing $listing): array;

  abstract protected function savePhotoSelections(BbAiListing $listing, FormStateInterface $form_state): void;

  abstract protected function getAddRouteName(): string;

}
