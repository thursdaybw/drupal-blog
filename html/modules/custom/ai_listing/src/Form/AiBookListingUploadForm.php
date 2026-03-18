<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AiBookListingUploadForm extends FormBase {

  use DependencySerializationTrait;

  private const INTAKE_MEDIA_BUNDLE = 'ai_listing_intake';

  public function __construct(
    private FileSystemInterface $fileSystem,
    private EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('file_system'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'ai_book_listing_upload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;
    $form['#attached']['library'][] = 'ai_listing/bundle_upload';
    $form['#attached']['library'][] = 'ai_listing/intake_picker';

    $stagedFileIds = $this->getStagedFileIds($form_state);

    $form['workspace'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-book-listing-upload-workspace'],
    ];

    $form['workspace']['upload'] = [
      '#theme' => 'ai_bundle_upload_panel',
      '#title' => 'Book Images',
      '#description' => 'Select one or more images and drop them anywhere on this panel.',
      '#attributes' => ['id' => 'ai-book-upload-panel-images'],
    ];

    $form['workspace']['upload']['file_input'] = [
      '#type' => 'file',
      '#title' => 'Add images',
      '#multiple' => TRUE,
      '#attributes' => ['accept' => 'image/*'],
    ];

    $form['workspace']['upload']['intake_picker'] = $this->buildIntakePickerElement(
      (array) $form_state->getValue(['workspace', 'upload', 'intake_picker'], []),
    );

    $form['workspace']['upload']['actions'] = ['#type' => 'actions'];
    $form['workspace']['upload']['actions']['upload_images'] = [
      '#type' => 'submit',
      '#value' => 'Upload selected images',
      '#name' => 'upload_images',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitUploadImages'],
      '#ajax' => [
        'callback' => '::ajaxRefreshWorkspace',
        'wrapper' => 'ai-book-listing-upload-workspace',
      ],
    ];

    $form['workspace']['staged_images'] = [
      '#type' => 'details',
      '#title' => 'Metadata source images',
      '#open' => TRUE,
      '#description' => 'Choose which uploaded images should be used for metadata inference.',
    ];

    if ($stagedFileIds === []) {
      $form['workspace']['staged_images']['empty'] = [
        '#markup' => '<p><em>Upload images above, then choose metadata source images here.</em></p>',
      ];
    }
    else {
      $form['workspace']['staged_images']['items'] = $this->buildStagedImageItems($stagedFileIds, $form_state);
      $form['workspace']['staged_images']['staged_file_ids'] = [
        '#type' => 'value',
        '#value' => $stagedFileIds,
      ];
      $form['workspace']['staged_images']['remove_actions'] = ['#type' => 'actions'];
      $form['workspace']['staged_images']['remove_actions']['remove_selected'] = [
        '#type' => 'submit',
        '#value' => 'Remove selected images',
        '#name' => 'remove_staged_images',
        '#limit_validation_errors' => [],
        '#submit' => ['::submitRemoveStagedImages'],
        '#ajax' => [
          'callback' => '::ajaxRefreshWorkspace',
          'wrapper' => 'ai-book-listing-upload-workspace',
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Save Listing',
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function ajaxRefreshWorkspace(array &$form, FormStateInterface $form_state): array {
    return $form['workspace'];
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $fileIds = $this->getEffectiveFileIds($form_state);
    if ($fileIds === []) {
      return;
    }

    // Intake-selected sets/images have no staged metadata UI yet; defaulting
    // metadata selection is handled on save.
    if ($this->getStagedFileIds($form_state) === []) {
      return;
    }

    $metadataSelections = (array) $form_state->getValue(['workspace', 'staged_images', 'items'], []);
    if ($this->hasMetadataSourceSelection($fileIds, $metadataSelections)) {
      return;
    }

    $form_state->setErrorByName(
      'workspace][staged_images',
      'Select at least one image to use for metadata before saving.',
    );
  }

  public function submitUploadImages(array &$form, FormStateInterface $form_state): void {
    $stagedFileIds = $this->getStagedFileIds($form_state);
    $newFiles = $this->saveUploadedImagesToTemp();

    if ($newFiles === []) {
      $this->messenger()->addWarning('No images were uploaded.');
      $form_state->setRebuild(TRUE);
      return;
    }

    foreach ($newFiles as $file) {
      $stagedFileIds[] = (int) $file->id();
    }

    $this->setStagedFileIds($form_state, $stagedFileIds);
    $this->messenger()->addStatus(sprintf('Uploaded %d image(s).', count($newFiles)));
    $form_state->setRebuild(TRUE);
  }

  public function submitRemoveStagedImages(array &$form, FormStateInterface $form_state): void {
    $stagedFileIds = $this->getStagedFileIds($form_state);
    $items = (array) $form_state->getValue(['workspace', 'staged_images', 'items'], []);

    if ($stagedFileIds === [] || $items === []) {
      $form_state->setRebuild(TRUE);
      return;
    }

    $remaining = [];
    $removedCount = 0;

    foreach ($stagedFileIds as $fid) {
      $itemKey = 'file_' . $fid;
      if (!empty($items[$itemKey]['remove'])) {
        $file = File::load($fid);
        if ($file !== NULL) {
          $file->delete();
        }
        $removedCount++;
        continue;
      }

      $remaining[] = $fid;
    }

    $this->setStagedFileIds($form_state, $remaining);
    if ($removedCount > 0) {
      $this->messenger()->addStatus(sprintf('Removed %d staged image(s).', $removedCount));
    }
    $form_state->setRebuild(TRUE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $stagedFileIds = $this->getStagedFileIds($form_state);
    $fileIds = $this->getEffectiveFileIds($form_state);

    if ($fileIds === []) {
      $this->messenger()->addError('Upload at least one image or select an intake set before saving.');
      $form_state->setRebuild(TRUE);
      return;
    }

    $listing = BbAiListing::create([
      'listing_type' => 'book',
      'status' => 'new',
    ]);
    $listing->save();
    $listingId = (int) $listing->id();

    $targetDirectory = 'public://ai-listings/' . $listing->uuid();
    $this->fileSystem->prepareDirectory(
      $targetDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $metadataSelections = (array) $form_state->getValue(['workspace', 'staged_images', 'items'], []);
    $useDefaultMetadataSelection = ($stagedFileIds === []);
    $weight = 0;

    foreach ($fileIds as $fid) {
      $file = File::load($fid);
      if ($file === NULL) {
        continue;
      }

      $newUri = $this->moveFileIntoListingDirectory($file, $targetDirectory);
      $file->setFileUri($newUri);
      $file->setPermanent();
      $file->save();

      $itemKey = 'file_' . $fid;
      $isMetadataSource = $useDefaultMetadataSelection
        ? TRUE
        : !empty($metadataSelections[$itemKey]['is_metadata_source']);
      $this->createListingImageRecord($listingId, (int) $file->id(), $weight, $isMetadataSource);
      $weight++;
    }

    $this->clearStagedFileIds($form_state);

    $this->messenger()->addStatus('Listing created.');
    $form_state->setRedirect('ai_listing.add');
  }

  /**
   * @param int[] $fileIds
   * @param array<string,mixed> $metadataSelections
   */
  private function hasMetadataSourceSelection(array $fileIds, array $metadataSelections): bool {
    foreach ($fileIds as $fid) {
      $itemKey = 'file_' . $fid;
      if (!empty($metadataSelections[$itemKey]['is_metadata_source'])) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * @return int[]
   */
  private function getStagedFileIds(FormStateInterface $form_state): array {
    $fromState = $form_state->get('staged_file_ids');
    if (is_array($fromState)) {
      return $this->normalizeFileIds($fromState);
    }

    $fromPosted = $form_state->getValue(['workspace', 'staged_images', 'staged_file_ids']);
    if (is_array($fromPosted)) {
      return $this->normalizeFileIds($fromPosted);
    }

    return [];
  }

  /**
   * @param array<int,mixed> $fileIds
   */
  private function setStagedFileIds(FormStateInterface $form_state, array $fileIds): void {
    $form_state->set('staged_file_ids', $this->normalizeFileIds($fileIds));
  }

  private function clearStagedFileIds(FormStateInterface $form_state): void {
    $form_state->set('staged_file_ids', []);
  }

  /**
   * @return int[]
   */
  private function getEffectiveFileIds(FormStateInterface $form_state): array {
    $stagedFileIds = $this->getStagedFileIds($form_state);
    if ($stagedFileIds !== []) {
      return $stagedFileIds;
    }

    $mediaIds = $this->extractSelectedMediaIdsFromPicker(
      (array) $form_state->getValue(['workspace', 'upload', 'intake_picker'], []),
    );
    if ($mediaIds === []) {
      return [];
    }

    return $this->loadIntakeFileIdsFromMediaIds($mediaIds);
  }

  /**
   * @return \Drupal\file\Entity\File[]
   */
  private function saveUploadedImagesToTemp(): array {
    $requestFiles = \Drupal::request()->files->all();
    $filesRoot = $requestFiles['files'] ?? NULL;
    $uploaded = [];

    if ($filesRoot instanceof UploadedFile) {
      $uploaded = [$filesRoot];
    }
    elseif (is_array($filesRoot)) {
      $uploaded = $filesRoot['workspace']['upload']['new_images']
        ?? $filesRoot['workspace']
        ?? $filesRoot['upload']['new_images']
        ?? ($filesRoot['new_images'] ?? []);
    }
    elseif (isset($requestFiles['workspace']['upload']['new_images'])) {
      $uploaded = $requestFiles['workspace']['upload']['new_images'];
    }
    elseif (isset($requestFiles['upload']['new_images'])) {
      $uploaded = $requestFiles['upload']['new_images'];
    }

    if ($uploaded instanceof UploadedFile) {
      $uploaded = [$uploaded];
    }

    if (!is_array($uploaded) || $uploaded === []) {
      return [];
    }

    $tempDirectory = 'public://ai-listings/tmp';
    $this->fileSystem->prepareDirectory(
      $tempDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $realTempDirectory = $this->fileSystem->realpath($tempDirectory);
    if (!$realTempDirectory) {
      throw new \RuntimeException('Unable to resolve temporary upload directory.');
    }

    $savedFiles = [];

    foreach ($uploaded as $upload) {
      if (!$upload instanceof UploadedFile) {
        continue;
      }
      if ($upload->getError() !== UPLOAD_ERR_OK) {
        continue;
      }
      if (!$this->isAcceptedImageUpload($upload)) {
        continue;
      }

      $safeName = $this->fileSystem->createFilename($upload->getClientOriginalName(), $tempDirectory);
      $basename = basename($safeName);
      $upload->move($realTempDirectory, $basename);

      $file = File::create([
        'uri' => $tempDirectory . '/' . $basename,
        'status' => 0,
      ]);
      $file->save();

      $savedFiles[] = $file;
    }

    return $savedFiles;
  }

  /**
   * @param int[] $stagedFileIds
   */
  private function buildStagedImageItems(array $stagedFileIds, FormStateInterface $form_state): array {
    $items = ['#type' => 'container'];
    $postedItems = (array) $form_state->getValue(['workspace', 'staged_images', 'items'], []);

    foreach ($stagedFileIds as $fid) {
      $file = File::load($fid);
      if ($file === NULL) {
        continue;
      }

      $key = 'file_' . $fid;
      $defaultMetadata = FALSE;
      if (isset($postedItems[$key]) && is_array($postedItems[$key])) {
        $defaultMetadata = !empty($postedItems[$key]['is_metadata_source']);
      }

      $items[$key] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'display:inline-block; margin:0 10px 14px 0; vertical-align:top;',
        ],
      ];
      $metadataCheckboxId = 'ai-upload-metadata-source-' . $fid;
      $items[$key]['thumbnail'] = [
        '#type' => 'html_tag',
        '#tag' => 'label',
        '#attributes' => [
          'for' => $metadataCheckboxId,
          'style' => 'display:block; margin-bottom:6px; cursor:pointer;',
        ],
      ];
      $items[$key]['thumbnail']['image'] = [
        '#theme' => 'image_style',
        '#style_name' => 'medium',
        '#uri' => $file->getFileUri(),
      ];
      $items[$key]['is_metadata_source'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Use for metadata'),
        '#default_value' => $defaultMetadata,
        '#attributes' => [
          'id' => $metadataCheckboxId,
        ],
      ];
      $items[$key]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
        '#default_value' => FALSE,
      ];
    }

    return $items;
  }

  private function moveFileIntoListingDirectory(File $file, string $targetDirectory): string {
    $originalUri = $file->getFileUri();
    $newUri = $targetDirectory . '/' . basename($originalUri);

    if ($originalUri !== $newUri) {
      $this->fileSystem->move($originalUri, $newUri);
    }

    return $newUri;
  }

  private function createListingImageRecord(int $listingId, int $fileId, int $weight, bool $isMetadataSource): void {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return;
    }

    $this->entityTypeManager->getStorage('listing_image')->create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => $listingId,
      ],
      'file' => $fileId,
      'weight' => $weight,
      'is_metadata_source' => $isMetadataSource,
    ])->save();
  }

  private function isAcceptedImageUpload(UploadedFile $upload): bool {
    $mimeType = strtolower((string) $upload->getMimeType());
    if (str_starts_with($mimeType, 'image/')) {
      return TRUE;
    }

    $clientName = strtolower((string) $upload->getClientOriginalName());
    $extension = pathinfo($clientName, PATHINFO_EXTENSION);

    return in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'bmp', 'tif', 'tiff'], TRUE);
  }

  /**
   * @param array<string,mixed> $postedItems
   */
  private function buildIntakePickerElement(array $postedPicker): array {
    $element = [
      '#type' => 'details',
      '#title' => $this->t('Attach from intake library'),
      '#open' => TRUE,
      '#description' => $this->t('Select one or more sets, then save the listing. Expand a set only if you need per-image selection.'),
    ];

    $rows = $this->loadRecentIntakeMediaRows();
    if ($rows === []) {
      $element['empty'] = [
        '#markup' => '<p><em>' . $this->t('No AI Listing Intake media found yet.') . '</em></p>',
      ];
      return $element;
    }

    $grouped = [];
    foreach ($rows as $mediaId => $row) {
      $setId = (string) ($row['set_id'] ?? '');
      if ($setId === '') {
        $setId = 'ungrouped';
      }
      if (!isset($grouped[$setId])) {
        $grouped[$setId] = [];
      }
      $grouped[$setId][$mediaId] = $row;
    }

    $setOptions = [];
    foreach ($grouped as $setId => $setRows) {
      $setOptions[$setId] = $this->t('@set (@count images)', [
        '@set' => $setId,
        '@count' => count($setRows),
      ])->render();
    }
    $element['selected_sets'] = [
      '#type' => 'checkboxes',
      '#title' => $this->t('Attach whole sets'),
      '#options' => $setOptions,
      '#default_value' => array_keys(array_filter((array) ($postedPicker['selected_sets'] ?? []))),
      '#description' => $this->t('Select one or more sets to attach all their images in one click.'),
    ];

    $element['items_by_set'] = ['#type' => 'container'];
    foreach ($grouped as $setId => $setRows) {
      $element['items_by_set'][$setId] = [
        '#type' => 'details',
        '#title' => $this->t('Set @set images', ['@set' => $setId]),
        '#open' => FALSE,
      ];
      $element['items_by_set'][$setId]['items'] = [
        '#type' => 'table',
        '#header' => [
          $this->t('Attach'),
          $this->t('Preview'),
          $this->t('Media name'),
          $this->t('File'),
        ],
      ];

      foreach ($setRows as $mediaId => $row) {
        $itemKey = (string) $mediaId;
        $element['items_by_set'][$setId]['items'][$itemKey]['attach'] = [
          '#type' => 'checkbox',
          '#default_value' => !empty($postedPicker['items'][$itemKey]['attach']),
          '#attributes' => ['class' => ['ai-intake-picker-checkbox']],
        ];
        $element['items_by_set'][$setId]['items'][$itemKey]['preview'] = [
          '#theme' => 'image_style',
          '#style_name' => 'thumbnail',
          '#uri' => $row['file_uri'],
        ];
        $element['items_by_set'][$setId]['items'][$itemKey]['name'] = [
          '#plain_text' => $row['name'],
        ];
        $element['items_by_set'][$setId]['items'][$itemKey]['file'] = [
          '#plain_text' => $row['file_name'],
        ];
      }
    }

    return $element;
  }

  /**
   * @return array<int,array{name:string,file_name:string,file_uri:string,set_id:string}>
   */
  private function loadRecentIntakeMediaRows(): array {
    $ids = $this->entityTypeManager
      ->getStorage('media')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', self::INTAKE_MEDIA_BUNDLE)
      ->sort('created', 'DESC')
      ->sort('mid', 'DESC')
      ->range(0, 200)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $assignedFileIds = $this->getAssignedListingFileIds();
    $rows = [];
    $mediaEntities = $this->entityTypeManager->getStorage('media')->loadMultiple($ids);
    foreach (array_values($ids) as $mediaId) {
      $media = $mediaEntities[$mediaId] ?? NULL;
      if ($media === NULL) {
        continue;
      }
      if (!$media->hasField('field_media_image')) {
        continue;
      }
      $imageField = $media->get('field_media_image');
      if ($imageField->isEmpty() || $imageField->entity === NULL) {
        continue;
      }
      $file = $imageField->entity;
      $fileId = (int) $file->id();
      if ($fileId > 0 && isset($assignedFileIds[$fileId])) {
        continue;
      }

      $rows[(int) $media->id()] = [
        'name' => (string) $media->label(),
        'file_name' => (string) $file->getFilename(),
        'file_uri' => (string) $file->getFileUri(),
        'set_id' => $this->extractSetIdFromFileUri((string) $file->getFileUri()),
      ];
    }

    return $rows;
  }

  /**
   * @param array<string,mixed> $picker
   * @return int[]
   */
  private function extractSelectedMediaIdsFromPicker(array $picker): array {
    $ids = [];

    $selectedSets = [];
    $setRows = $this->loadRecentIntakeMediaRows();
    foreach ((array) ($picker['selected_sets'] ?? []) as $setId => $setValue) {
      $value = (string) $setValue;
      if ($value === '' || $value === '0') {
        continue;
      }
      $selectedSets[] = $this->normalizeSetKey((string) $setId);
    }

    if ($selectedSets !== []) {
      foreach ($setRows as $mediaId => $row) {
        $rowSetKey = $this->normalizeSetKey((string) ($row['set_id'] ?? ''));
        if (in_array($rowSetKey, $selectedSets, TRUE)) {
          $ids[] = (int) $mediaId;
        }
      }
    }

    foreach ((array) ($picker['items'] ?? []) as $mediaId => $row) {
      if (!is_array($row) || empty($row['attach'])) {
        continue;
      }
      $id = (int) $mediaId;
      if ($id > 0) {
        $ids[] = $id;
      }
    }

    return array_values(array_unique($ids));
  }

  private function extractSetIdFromFileUri(string $uri): string {
    $prefix = 'public://ai-intake/';
    if (!str_starts_with($uri, $prefix)) {
      return 'ungrouped';
    }

    $tail = substr($uri, strlen($prefix));
    if (!is_string($tail) || $tail === '') {
      return 'ungrouped';
    }

    $parts = explode('/', $tail, 2);
    return (string) ($parts[0] ?? 'ungrouped');
  }

  /**
   * @return array<int,true>
   */
  private function getAssignedListingFileIds(): array {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    $ids = $this->entityTypeManager
      ->getStorage('listing_image')
      ->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $assigned = [];
    $images = $this->entityTypeManager->getStorage('listing_image')->loadMultiple($ids);
    foreach ($images as $image) {
      if (!$image->hasField('file')) {
        continue;
      }
      $fileId = (int) $image->get('file')->target_id;
      if ($fileId > 0) {
        $assigned[$fileId] = TRUE;
      }
    }

    return $assigned;
  }

  private function normalizeSetKey(string $value): string {
    return str_replace('_', '-', trim($value));
  }

  /**
   * @param int[] $mediaIds
   * @return int[]
   */
  private function loadIntakeFileIdsFromMediaIds(array $mediaIds): array {
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $mediaEntities = $mediaStorage->loadMultiple($mediaIds);
    $fileIds = [];

    foreach ($mediaEntities as $media) {
      if ($media->bundle() !== self::INTAKE_MEDIA_BUNDLE) {
        continue;
      }
      if (!$media->hasField('field_media_image')) {
        continue;
      }

      $imageField = $media->get('field_media_image');
      if ($imageField->isEmpty()) {
        continue;
      }

      $fileId = (int) $imageField->target_id;
      if ($fileId > 0) {
        $fileIds[] = $fileId;
      }
    }

    return array_values(array_unique($fileIds));
  }

  /**
   * @param array<int,mixed> $values
   * @return int[]
   */
  private function normalizeFileIds(array $values): array {
    $ids = [];
    foreach ($values as $value) {
      if (is_scalar($value) && is_numeric((string) $value)) {
        $ids[] = (int) $value;
      }
    }

    return array_values(array_unique(array_filter($ids)));
  }

}
