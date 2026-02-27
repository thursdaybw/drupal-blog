<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AiBookListingUploadForm extends FormBase {

  use DependencySerializationTrait;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('file_system'),
      $container->get('entity_type.manager'),
      $container->get('file_url_generator'),
    );
  }

  public function getFormId(): string {
    return 'ai_book_listing_upload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;

    $stagedFileIds = $this->getStagedFileIds($form_state);

    $form['workspace'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ai-book-listing-upload-workspace',
      ],
    ];

    $form['workspace']['upload'] = [
      '#type' => 'details',
      '#title' => 'Book Images',
      '#open' => TRUE,
    ];

    $form['workspace']['upload']['new_images'] = [
      '#type' => 'file',
      '#title' => 'Add images',
      '#multiple' => TRUE,
      '#attributes' => [
        'accept' => 'image/*',
      ],
      '#description' => 'Select one or more images, then click Upload selected images.',
    ];

    $form['workspace']['upload']['upload_actions'] = [
      '#type' => 'actions',
    ];
    $form['workspace']['upload']['upload_actions']['upload_images'] = [
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
      $form['workspace']['staged_images']['remove_actions'] = [
        '#type' => 'actions',
      ];
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

    $form['actions'] = [
      '#type' => 'actions',
    ];
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

  public function submitUploadImages(array &$form, FormStateInterface $form_state): void {
    $stagedFileIds = $this->getStagedFileIds($form_state);
    $this->debugUploadRequest();
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
    $fileIds = $this->getStagedFileIds($form_state);

    if ($fileIds === []) {
      $this->messenger()->addError('Upload at least one image before saving.');
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
      $isMetadataSource = !empty($metadataSelections[$itemKey]['is_metadata_source']);
      $this->createListingImageRecord($listingId, (int) $file->id(), $weight, $isMetadataSource);
      $weight++;
    }

    $listing->save();
    $this->clearStagedFileIds($form_state);

    $this->messenger()->addStatus('Listing created.');
    $form_state->setRedirect('ai_listing.add');
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
      // With #tree enabled this file input lives under workspace[upload][new_images].
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

  private function debugUploadRequest(): void {
    $normalize = function (mixed $value) use (&$normalize): mixed {
      if ($value === NULL || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
        return $value;
      }
      if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $v) {
          $out[(string) $k] = $normalize($v);
        }
        return $out;
      }
      if ($value instanceof UploadedFile) {
        return [
          'uploaded_file' => TRUE,
          'name' => $value->getClientOriginalName(),
          'error' => $value->getError(),
          'mime' => $value->getMimeType(),
        ];
      }
      return 'Object(' . get_debug_type($value) . ')';
    };

    $payload = [
      'request_files' => $normalize(\Drupal::request()->files->all()),
      'request_post' => $normalize(\Drupal::request()->request->all()),
    ];

    @file_put_contents('/tmp/ai_listing_upload_request_debug.log', json_encode($payload, JSON_PRETTY_PRINT) . PHP_EOL . str_repeat('-', 80) . PHP_EOL, FILE_APPEND);
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
