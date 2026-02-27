<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Entity\AiBookBundleItem;
use Drupal\ai_listing\Entity\BbAiListing;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AiBookBundleListingUploadForm extends FormBase {

  use DependencySerializationTrait;

  public function __construct(
    private readonly FileSystemInterface $fileSystem,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('file_system'),
      $container->get('entity_type.manager'),
    );
  }

  public function getFormId(): string {
    return 'ai_book_bundle_listing_upload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#tree'] = TRUE;

    $bundleGroups = $this->getBundleGroups($form_state);
    $stagedImages = $this->getStagedImages($form_state);
    $bundleListingImageIds = $this->getBundleListingImageIds($form_state);

    $form['workspace'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-book-bundle-upload-workspace'],
    ];

    $form['workspace']['bundle_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Bundle title (optional)'),
      '#default_value' => (string) $form_state->getValue(['workspace', 'bundle_title'], ''),
    ];

    $form['workspace']['upload'] = [
      '#type' => 'details',
      '#title' => $this->t('Bundle item images'),
      '#open' => TRUE,
    ];

    $form['workspace']['upload']['new_images'] = [
      '#type' => 'file',
      '#title' => $this->t('Add images'),
      '#multiple' => TRUE,
      '#attributes' => ['accept' => 'image/*'],
      '#description' => $this->t('Select images for one book, then upload. Each upload creates the next book in the bundle.'),
    ];

    $form['workspace']['upload']['actions'] = ['#type' => 'actions'];
    $form['workspace']['upload']['actions']['upload_images'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload selected images'),
      '#name' => 'upload_images',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitUploadImages'],
      '#ajax' => [
        'callback' => '::ajaxRefreshWorkspace',
        'wrapper' => 'ai-book-bundle-upload-workspace',
      ],
    ];

    $form['workspace']['bundle_upload'] = [
      '#type' => 'details',
      '#title' => $this->t('Bundle-level listing images'),
      '#open' => TRUE,
      '#description' => $this->t('Upload photos of the physically bundled lot (all covers/spines/outer views).'),
    ];

    $form['workspace']['bundle_upload']['listing_images'] = [
      '#type' => 'file',
      '#title' => $this->t('Add bundle-level images'),
      '#multiple' => TRUE,
      '#attributes' => ['accept' => 'image/*'],
      '#description' => $this->t('These images are used for listing/publishing and bundle-level condition context.'),
    ];

    $form['workspace']['bundle_upload']['actions'] = ['#type' => 'actions'];
    $form['workspace']['bundle_upload']['actions']['upload_listing_images'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload bundle-level images'),
      '#name' => 'upload_listing_images',
      '#limit_validation_errors' => [],
      '#submit' => ['::submitUploadBundleListingImages'],
      '#ajax' => [
        'callback' => '::ajaxRefreshWorkspace',
        'wrapper' => 'ai-book-bundle-upload-workspace',
      ],
    ];

    $form['workspace']['staged'] = [
      '#type' => 'details',
      '#title' => $this->t('Staged bundle images'),
      '#open' => TRUE,
      '#description' => $this->t('Metadata tags apply within each bundled book image set.'),
    ];

    if ($stagedImages === []) {
      $form['workspace']['staged']['empty'] = [
        '#markup' => '<p><em>' . $this->t('Upload images into each book group before saving the bundle listing.') . '</em></p>',
      ];
    }
    else {
      $form['workspace']['staged']['groups'] = $this->buildStagedGroups($bundleGroups, $stagedImages, $form_state);
      $form['workspace']['staged']['actions'] = ['#type' => 'actions'];
      $form['workspace']['staged']['actions']['remove_selected'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove selected images'),
        '#name' => 'remove_staged_images',
        '#limit_validation_errors' => [],
        '#submit' => ['::submitRemoveStagedImages'],
        '#ajax' => [
          'callback' => '::ajaxRefreshWorkspace',
          'wrapper' => 'ai-book-bundle-upload-workspace',
        ],
      ];
    }

    $form['workspace']['bundle_listing_images'] = [
      '#type' => 'details',
      '#title' => $this->t('Staged bundle-level images'),
      '#open' => TRUE,
    ];

    if ($bundleListingImageIds === []) {
      $form['workspace']['bundle_listing_images']['empty'] = [
        '#markup' => '<p><em>' . $this->t('No bundle-level images uploaded yet.') . '</em></p>',
      ];
    }
    else {
      $form['workspace']['bundle_listing_images']['items'] = $this->buildBundleListingImageItems($bundleListingImageIds, $form_state);
      $form['workspace']['bundle_listing_images']['actions'] = ['#type' => 'actions'];
      $form['workspace']['bundle_listing_images']['actions']['remove_selected'] = [
        '#type' => 'submit',
        '#value' => $this->t('Remove selected bundle-level images'),
        '#name' => 'remove_bundle_listing_images',
        '#limit_validation_errors' => [],
        '#submit' => ['::submitRemoveBundleListingImages'],
        '#ajax' => [
          'callback' => '::ajaxRefreshWorkspace',
          'wrapper' => 'ai-book-bundle-upload-workspace',
        ],
      ];
    }

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Save bundle listing'),
      '#button_type' => 'primary',
    ];

    return $form;
  }

  public function ajaxRefreshWorkspace(array &$form, FormStateInterface $form_state): array {
    return $form['workspace'];
  }

  public function submitUploadImages(array &$form, FormStateInterface $form_state): void {
    $newFiles = $this->saveUploadedImagesToTemp('new_images');
    if ($newFiles === []) {
      $this->messenger()->addWarning((string) $this->t('No images were uploaded.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $groups = $this->getBundleGroups($form_state);
    $staged = $this->getStagedImages($form_state);
    $targetGroup = $this->deriveNextUploadGroupKey($groups, $staged);
    if (!in_array($targetGroup, $groups, TRUE)) {
      $groups[] = $targetGroup;
      $this->setBundleGroups($form_state, $groups);
    }

    foreach ($newFiles as $file) {
      $staged[(int) $file->id()] = ['group' => $targetGroup];
    }

    $this->setStagedImages($form_state, $staged);
    $this->messenger()->addStatus((string) $this->t('Uploaded @count image(s).', ['@count' => count($newFiles)]));
    $form_state->setRebuild(TRUE);
  }

  public function submitUploadBundleListingImages(array &$form, FormStateInterface $form_state): void {
    $newFiles = $this->saveUploadedImagesToTemp('listing_images');

    if ($newFiles === []) {
      $this->messenger()->addWarning((string) $this->t('No bundle-level images were uploaded.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $staged = $this->getBundleListingImageIds($form_state);
    foreach ($newFiles as $file) {
      $staged[] = (int) $file->id();
    }
    $this->setBundleListingImageIds($form_state, $staged);

    $this->messenger()->addStatus((string) $this->t('Uploaded @count bundle-level image(s).', ['@count' => count($newFiles)]));
    $form_state->setRebuild(TRUE);
  }

  public function submitRemoveStagedImages(array &$form, FormStateInterface $form_state): void {
    $staged = $this->getStagedImages($form_state);
    $postedGroups = (array) $form_state->getValue(['workspace', 'staged', 'groups'], []);

    if ($staged === [] || $postedGroups === []) {
      $form_state->setRebuild(TRUE);
      return;
    }

    $remaining = [];
    $removedCount = 0;

    foreach ($staged as $fid => $meta) {
      $group = (string) ($meta['group'] ?? '');
      $itemKey = 'file_' . $fid;
      $remove = !empty($postedGroups[$group]['items'][$itemKey]['remove']);

      if ($remove) {
        $file = File::load((int) $fid);
        if ($file !== NULL) {
          $file->delete();
        }
        $removedCount++;
        continue;
      }

      $remaining[(int) $fid] = $meta;
    }

    $this->setStagedImages($form_state, $remaining);
    if ($removedCount > 0) {
      $this->messenger()->addStatus((string) $this->t('Removed @count staged image(s).', ['@count' => $removedCount]));
    }
    $form_state->setRebuild(TRUE);
  }

  public function submitRemoveBundleListingImages(array &$form, FormStateInterface $form_state): void {
    $staged = $this->getBundleListingImageIds($form_state);
    $items = (array) $form_state->getValue(['workspace', 'bundle_listing_images', 'items'], []);

    if ($staged === [] || $items === []) {
      $form_state->setRebuild(TRUE);
      return;
    }

    $remaining = [];
    $removedCount = 0;

    foreach ($staged as $fid) {
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

    $this->setBundleListingImageIds($form_state, $remaining);
    if ($removedCount > 0) {
      $this->messenger()->addStatus((string) $this->t('Removed @count bundle-level image(s).', ['@count' => $removedCount]));
    }
    $form_state->setRebuild(TRUE);
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $staged = $this->getStagedImages($form_state);
    $bundleListingImageIds = $this->getBundleListingImageIds($form_state);
    $groups = $this->getBundleGroups($form_state);
    $postedGroups = (array) $form_state->getValue(['workspace', 'staged', 'groups'], []);

    if ($staged === []) {
      $this->messenger()->addError((string) $this->t('Upload at least one image before saving.'));
      $form_state->setRebuild(TRUE);
      return;
    }
    if ($bundleListingImageIds === []) {
      $this->messenger()->addError((string) $this->t('Upload at least one bundle-level image before saving.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $bundleTitle = trim((string) $form_state->getValue(['workspace', 'bundle_title'], ''));
    $bundle = BbAiListing::create([
      'listing_type' => 'book_bundle',
      'status' => 'new',
      'field_title' => $bundleTitle,
      'ebay_title' => $bundleTitle,
    ]);
    $bundle->save();
    $bundleId = (int) $bundle->id();

    $targetDirectory = 'public://ai-bundles/' . $bundle->uuid();
    $this->fileSystem->prepareDirectory(
      $targetDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    foreach (array_values($groups) as $groupWeight => $groupKey) {
      $groupFileIds = $this->getGroupFileIds($staged, $groupKey);
      if ($groupFileIds === []) {
        continue;
      }

      $bundleItem = AiBookBundleItem::create([
        'bundle_listing' => $bundleId,
        'weight' => $groupWeight,
      ]);
      $bundleItem->save();
      $bundleItemId = (int) $bundleItem->id();

      $imageWeight = 0;
      foreach ($groupFileIds as $fid) {
        $file = File::load($fid);
        if ($file === NULL) {
          continue;
        }

        $newUri = $this->moveFileIntoListingDirectory($file, $targetDirectory);
        $file->setFileUri($newUri);
        $file->setPermanent();
        $file->save();

        $itemKey = 'file_' . $fid;
        $isMetadataSource = !empty($postedGroups[$groupKey]['items'][$itemKey]['is_metadata_source']);

        $this->createBundleItemImageRecord($bundleItemId, (int) $file->id(), $imageWeight, $isMetadataSource);
        $imageWeight++;
      }
    }

    $bundleListingImageWeight = 0;
    foreach ($bundleListingImageIds as $fid) {
      $file = File::load($fid);
      if ($file === NULL) {
        continue;
      }

      $newUri = $this->moveFileIntoListingDirectory($file, $targetDirectory);
      $file->setFileUri($newUri);
      $file->setPermanent();
      $file->save();

      $this->createBundleListingImageRecord($bundleId, (int) $file->id(), $bundleListingImageWeight);
      $bundleListingImageWeight++;
    }

    $this->clearBundleFormState($form_state);
    $this->messenger()->addStatus((string) $this->t('Bundle listing created.'));
    $form_state->setRedirect('ai_listing.bundle_add');
  }

  /**
   * @return array<int,string>
   */
  private function getBundleGroups(FormStateInterface $form_state): array {
    $groups = $form_state->get('bundle_groups');
    if (is_array($groups) && $groups !== []) {
      return array_values(array_map('strval', $groups));
    }

    return ['book_1'];
  }

  /**
   * @param array<int,string> $groups
   */
  private function setBundleGroups(FormStateInterface $form_state, array $groups): void {
    $form_state->set('bundle_groups', array_values(array_map('strval', $groups)));
  }

  /**
   * @return array<int,array{group:string}>
   */
  private function getStagedImages(FormStateInterface $form_state): array {
    $staged = $form_state->get('bundle_staged_images');
    if (!is_array($staged)) {
      return [];
    }

    $normalized = [];
    foreach ($staged as $fid => $meta) {
      $fileId = (int) $fid;
      $group = is_array($meta) ? (string) ($meta['group'] ?? '') : '';
      if ($fileId <= 0 || $group === '') {
        continue;
      }
      $normalized[$fileId] = ['group' => $group];
    }

    return $normalized;
  }

  /**
   * @param array<int,array{group:string}> $staged
   */
  private function setStagedImages(FormStateInterface $form_state, array $staged): void {
    $form_state->set('bundle_staged_images', $staged);
  }

  private function clearBundleFormState(FormStateInterface $form_state): void {
    $form_state->set('bundle_groups', ['book_1']);
    $form_state->set('bundle_staged_images', []);
    $form_state->set('bundle_listing_image_ids', []);
  }

  /**
   * @param array<int,string> $groups
   */
  private function createNextGroupKey(array $groups): string {
    $max = 0;
    foreach ($groups as $group) {
      if (preg_match('/^book_(\d+)$/', $group, $matches) === 1) {
        $max = max($max, (int) $matches[1]);
      }
    }
    return 'book_' . ($max + 1);
  }

  /**
   * @param array<int,string> $groups
   * @param array<int,array{group:string}> $staged
   */
  private function deriveNextUploadGroupKey(array $groups, array $staged): string {
    if ($staged === [] && $groups === ['book_1']) {
      return 'book_1';
    }

    return $this->createNextGroupKey($groups);
  }

  /**
   * @param array<int,array{group:string}> $staged
   * @param array<int,string> $groups
   */
  private function buildStagedGroups(array $groups, array $staged, FormStateInterface $form_state): array {
    $postedGroups = (array) $form_state->getValue(['workspace', 'staged', 'groups'], []);
    $elements = ['#type' => 'container'];

    foreach (array_values($groups) as $index => $groupKey) {
      $groupFileIds = $this->getGroupFileIds($staged, $groupKey);
      $elements[$groupKey] = [
        '#type' => 'details',
        '#title' => $this->t('Book @num images', ['@num' => $index + 1]),
        '#open' => TRUE,
      ];

      if ($groupFileIds === []) {
        $elements[$groupKey]['empty'] = [
          '#markup' => '<p><em>' . $this->t('No images in this book group yet.') . '</em></p>',
        ];
        continue;
      }

      $elements[$groupKey]['items'] = ['#type' => 'container'];

      foreach ($groupFileIds as $fid) {
        $file = File::load($fid);
        if ($file === NULL) {
          continue;
        }

        $itemKey = 'file_' . $fid;
        $defaultMetadata = !empty($postedGroups[$groupKey]['items'][$itemKey]['is_metadata_source']);
        $metadataCheckboxId = 'ai-bundle-upload-metadata-source-' . $groupKey . '-' . $fid;

        $elements[$groupKey]['items'][$itemKey] = [
          '#type' => 'container',
          '#attributes' => [
            'style' => 'display:inline-block; margin:0 10px 14px 0; vertical-align:top;',
          ],
        ];
        $elements[$groupKey]['items'][$itemKey]['thumbnail'] = [
          '#type' => 'html_tag',
          '#tag' => 'label',
          '#attributes' => [
            'for' => $metadataCheckboxId,
            'style' => 'display:block; margin-bottom:6px; cursor:pointer;',
          ],
        ];
        $elements[$groupKey]['items'][$itemKey]['thumbnail']['image'] = [
          '#theme' => 'image_style',
          '#style_name' => 'medium',
          '#uri' => $file->getFileUri(),
        ];
        $elements[$groupKey]['items'][$itemKey]['is_metadata_source'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Use for metadata'),
          '#default_value' => $defaultMetadata,
          '#attributes' => [
            'id' => $metadataCheckboxId,
          ],
        ];
        $elements[$groupKey]['items'][$itemKey]['remove'] = [
          '#type' => 'checkbox',
          '#title' => $this->t('Remove'),
          '#default_value' => FALSE,
        ];
      }
    }

    return $elements;
  }

  /**
   * @param array<int,array{group:string}> $staged
   * @return int[]
   */
  private function getGroupFileIds(array $staged, string $groupKey): array {
    $ids = [];
    foreach ($staged as $fid => $meta) {
      if (($meta['group'] ?? '') === $groupKey) {
        $ids[] = (int) $fid;
      }
    }
    sort($ids);
    return $ids;
  }

  /**
   * @return \Drupal\file\Entity\File[]
   */
  private function saveUploadedImagesToTemp(string $inputKey): array {
    $requestFiles = \Drupal::request()->files->all();
    $filesRoot = $requestFiles['files'] ?? NULL;
    $uploaded = $this->extractUploadedFilesByInputKey($filesRoot, $inputKey);
    if ($uploaded === []) {
      $uploaded = $this->extractUploadedFilesByInputKey($requestFiles, $inputKey);
    }
    if ($uploaded === []) {
      // Some AJAX payloads flatten file trees and lose the original input key.
      $uploaded = $this->extractUploadedFilesFromTree($filesRoot);
    }
    if ($uploaded === []) {
      $uploaded = $this->extractUploadedFilesFromTree($requestFiles);
    }

    if ($uploaded === []) {
      return [];
    }

    $tempDirectory = 'public://ai-bundles/tmp';
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
   * @return UploadedFile[]
   */
  private function extractUploadedFilesByInputKey(mixed $tree, string $inputKey): array {
    $uploads = [];
    $this->collectUploadedFilesByInputKey($tree, $inputKey, $uploads);
    return $uploads;
  }

  /**
   * @param UploadedFile[] $uploads
   */
  private function collectUploadedFilesByInputKey(mixed $node, string $inputKey, array &$uploads): void {
    if (!is_array($node)) {
      return;
    }

    foreach ($node as $key => $value) {
      if ($key === $inputKey) {
        $this->appendUploadedFiles($value, $uploads);
        continue;
      }

      $this->collectUploadedFilesByInputKey($value, $inputKey, $uploads);
    }
  }

  /**
   * @param UploadedFile[] $uploads
   */
  private function appendUploadedFiles(mixed $value, array &$uploads): void {
    if ($value instanceof UploadedFile) {
      $uploads[] = $value;
      return;
    }

    if (!is_array($value)) {
      return;
    }

    foreach ($value as $child) {
      $this->appendUploadedFiles($child, $uploads);
    }
  }

  /**
   * @return UploadedFile[]
   */
  private function extractUploadedFilesFromTree(mixed $tree): array {
    $uploads = [];
    $this->appendUploadedFiles($tree, $uploads);
    return $uploads;
  }

  private function moveFileIntoListingDirectory(File $file, string $targetDirectory): string {
    $originalUri = $file->getFileUri();
    $newUri = $targetDirectory . '/' . basename($originalUri);
    if ($originalUri !== $newUri) {
      $this->fileSystem->move($originalUri, $newUri);
    }
    return $newUri;
  }

  private function createBundleItemImageRecord(int $bundleItemId, int $fileId, int $weight, bool $isMetadataSource): void {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return;
    }

    $this->entityTypeManager->getStorage('listing_image')->create([
      'owner' => [
        'target_type' => 'ai_book_bundle_item',
        'target_id' => $bundleItemId,
      ],
      'file' => $fileId,
      'weight' => $weight,
      'is_metadata_source' => $isMetadataSource,
    ])->save();
  }

  private function createBundleListingImageRecord(int $bundleListingId, int $fileId, int $weight): void {
    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return;
    }

    $this->entityTypeManager->getStorage('listing_image')->create([
      'owner' => [
        'target_type' => 'bb_ai_listing',
        'target_id' => $bundleListingId,
      ],
      'file' => $fileId,
      'weight' => $weight,
      'is_metadata_source' => FALSE,
    ])->save();
  }

  /**
   * @return int[]
   */
  private function getBundleListingImageIds(FormStateInterface $form_state): array {
    $ids = $form_state->get('bundle_listing_image_ids');
    if (!is_array($ids)) {
      return [];
    }

    $normalized = [];
    foreach ($ids as $id) {
      $fileId = (int) $id;
      if ($fileId > 0) {
        $normalized[] = $fileId;
      }
    }

    return array_values(array_unique($normalized));
  }

  /**
   * @param int[] $ids
   */
  private function setBundleListingImageIds(FormStateInterface $form_state, array $ids): void {
    $normalized = [];
    foreach ($ids as $id) {
      $fileId = (int) $id;
      if ($fileId > 0) {
        $normalized[] = $fileId;
      }
    }

    $form_state->set('bundle_listing_image_ids', array_values(array_unique($normalized)));
  }

  /**
   * @param int[] $fileIds
   */
  private function buildBundleListingImageItems(array $fileIds, FormStateInterface $form_state): array {
    $postedItems = (array) $form_state->getValue(['workspace', 'bundle_listing_images', 'items'], []);
    $elements = ['#type' => 'container'];

    foreach ($fileIds as $fid) {
      $file = File::load($fid);
      if ($file === NULL) {
        continue;
      }

      $itemKey = 'file_' . $fid;
      $elements[$itemKey] = [
        '#type' => 'container',
        '#attributes' => [
          'style' => 'display:inline-block; margin:0 10px 14px 0; vertical-align:top;',
        ],
      ];
      $elements[$itemKey]['thumbnail'] = [
        '#theme' => 'image_style',
        '#style_name' => 'medium',
        '#uri' => $file->getFileUri(),
      ];
      $elements[$itemKey]['remove'] = [
        '#type' => 'checkbox',
        '#title' => $this->t('Remove'),
        '#default_value' => !empty($postedItems[$itemKey]['remove']),
      ];
    }

    return $elements;
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

}
