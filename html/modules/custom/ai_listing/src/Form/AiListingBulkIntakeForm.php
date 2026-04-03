<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\ai_listing\Service\IntakeSetListingMaterializer;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file\FileInterface;
use Drupal\media\MediaInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AiListingBulkIntakeForm extends FormBase implements ContainerInjectionInterface {
  private const INTAKE_MEDIA_BUNDLE = 'ai_listing_intake';

  protected EntityTypeManagerInterface $entityTypeManager;
  protected FileSystemInterface $fileSystem;
  protected AccountProxyInterface $currentUser;
  protected DateFormatterInterface $dateFormatter;
  protected IntakeSetListingMaterializer $intakeSetListingMaterializer;

  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    FileSystemInterface $file_system,
    AccountProxyInterface $current_user,
    DateFormatterInterface $date_formatter,
    IntakeSetListingMaterializer $intake_set_listing_materializer,
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->fileSystem = $file_system;
    $this->currentUser = $current_user;
    $this->dateFormatter = $date_formatter;
    $this->intakeSetListingMaterializer = $intake_set_listing_materializer;
  }

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('current_user'),
      $container->get('date.formatter'),
      $container->get('ai_listing.intake_set_listing_materializer'),
    );
  }

  public function getFormId(): string {
    return 'ai_listing_bulk_intake_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#attached']['library'][] = 'ai_listing/bulk_intake_sets';
    $form['#attributes']['enctype'] = 'multipart/form-data';
    $form['#attached']['drupalSettings']['aiListingBulkIntake'] = [
      'chunkUploadUrl' => Url::fromRoute('ai_listing.bulk_intake_upload_chunk')->toString(),
      'chunkSizeBytes' => 512 * 1024,
      'maxParallelSets' => 1,
      'maxChunkAttempts' => 20,
      'chunkRequestTimeoutMs' => 45000,
      'maxChunkRetryWindowMs' => 30 * 60 * 1000,
      // Non-production test hooks for deterministic recovery validation.
      'debugSimulateFailureSetKey' => '',
      'debugSimulateFailureChunkIndex' => -1,
      'debugSimulateFailureOnce' => TRUE,
    ];

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>Stage many image sets first, then process staged sets in one controlled run.</p>',
    ];

    $form['sets'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ai-bulk-intake-sets-root',
        'class' => ['ai-bulk-intake-sets-root'],
      ],
    ];
    $form['sets']['set_1'] = [
      '#type' => 'container',
      '#attributes' => [
        'data-ai-bulk-intake-set-row' => '1',
        'style' => 'margin-bottom:14px;',
      ],
    ];
    $form['sets']['set_1']['label'] = [
      '#type' => 'html_tag',
      '#tag' => 'label',
      '#value' => $this->t('Set 1 images'),
      '#attributes' => [
        'style' => 'display:block;font-weight:600;margin-bottom:6px;',
      ],
    ];
    $form['sets']['set_1']['files'] = [
      '#type' => 'file',
      '#title' => $this->t('Set 1 images'),
      '#title_display' => 'invisible',
      '#multiple' => TRUE,
      '#attributes' => [
        'accept' => 'image/*',
        'class' => ['ai-bulk-intake-file-input'],
      ],
      '#name' => 'intake_sets[set_1][]',
    ];
    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#name' => 'stage_uploaded_sets',
      '#value' => $this->t('Stage uploaded sets'),
      '#button_type' => 'primary',
      '#attributes' => [
        'id' => 'ai-bulk-intake-stage-submit',
      ],
    ];
    $form['actions']['process_staged'] = [
      '#type' => 'submit',
      '#name' => 'process_staged_sets',
      '#value' => $this->t('Process staged sets into listings'),
    ];
    $form['upload_progress'] = [
      '#type' => 'container',
      '#attributes' => [
        'id' => 'ai-bulk-intake-upload-progress',
        'class' => ['ai-bulk-intake-upload-progress'],
        'aria-live' => 'polite',
      ],
    ];

    $stagedRows = $this->buildStagedSetRows();
    $form['staged'] = [
      '#type' => 'details',
      '#title' => $this->t('Staged intake sets'),
      '#open' => TRUE,
    ];
    $form['staged']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Set'),
        $this->t('Images'),
        $this->t('Status'),
      ],
      '#rows' => $stagedRows,
      '#empty' => $this->t('No staged intake sets found.'),
    ];

    $recentRows = $this->buildRecentRows();
    $form['recent'] = [
      '#type' => 'details',
      '#title' => $this->t('Recently ingested images'),
      '#open' => TRUE,
    ];
    $form['recent']['table'] = [
      '#type' => 'table',
      '#header' => [
        $this->t('Set'),
        $this->t('Media ID'),
        $this->t('Name'),
        $this->t('File'),
        $this->t('Created'),
      ],
      '#rows' => $recentRows,
      '#empty' => $this->t('No image media found yet.'),
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $trigger = (string) ($form_state->getTriggeringElement()['#name'] ?? 'stage_uploaded_sets');
    if ($trigger === 'process_staged_sets') {
      $this->processStagedSets($form_state);
      return;
    }

    $this->stageUploadedSets($form_state);
  }

  private function stageUploadedSets(FormStateInterface $form_state): void {
    $mediaStorage = $this->entityTypeManager->getStorage('media');
    $uploadedSets = $this->extractUploadedSets();
    if ($uploadedSets === []) {
      $this->messenger()->addError($this->t('No files selected.'));
      return;
    }

    if (!$this->imageBundleExists()) {
      $this->messenger()->addError($this->t('Media bundle "@bundle" is not available.', [
        '@bundle' => self::INTAKE_MEDIA_BUNDLE,
      ]));
      return;
    }

    $setId = $this->generateSetId();
    $setDirectory = 'public://ai-intake/' . $setId;
    $this->fileSystem->prepareDirectory(
      $setDirectory,
      FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
    );

    $created = 0;
    $setCount = 0;
    foreach ($uploadedSets as $uploads) {
      if ($uploads === []) {
        continue;
      }

      $setCount++;
      $setId = $this->generateSetId();
      $setDirectory = 'public://ai-intake/' . $setId;
      $this->fileSystem->prepareDirectory(
        $setDirectory,
        FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS,
      );

      $realSetDirectory = $this->fileSystem->realpath($setDirectory);
      if (!$realSetDirectory) {
        continue;
      }

      foreach ($uploads as $upload) {
        if (!$upload instanceof UploadedFile || $upload->getError() !== UPLOAD_ERR_OK) {
          continue;
        }
        if (!$this->isAcceptedImageUpload($upload)) {
          continue;
        }

        $safeName = $this->fileSystem->createFilename((string) $upload->getClientOriginalName(), $setDirectory);
        $basename = basename($safeName);
        $upload->move($realSetDirectory, $basename);

        $file = \Drupal\file\Entity\File::create([
          'uri' => $setDirectory . '/' . $basename,
          'status' => 1,
        ]);
        $file->setPermanent();
        $file->save();

        $filename = (string) $file->getFilename();
        $media = $mediaStorage->create([
          'bundle' => self::INTAKE_MEDIA_BUNDLE,
          'name' => $filename,
          'uid' => (int) $this->currentUser->id(),
          'status' => 1,
          'field_media_image' => [
            'target_id' => (int) $file->id(),
            'alt' => $filename,
            'title' => $filename,
          ],
        ]);
        $media->save();
        $created++;
      }
    }

    $this->messenger()->addStatus($this->t('Staged @count image(s) across @sets set(s). Run "Process staged sets into listings" to materialize listings.', [
      '@count' => (string) $created,
      '@sets' => (string) $setCount,
    ]));
    $form_state->setRebuild(TRUE);
  }

  private function processStagedSets(FormStateInterface $form_state): void {
    $setStates = $this->loadIntakeSetStates();
    if ($setStates === []) {
      $this->messenger()->addWarning($this->t('No intake sets were found.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $readySetCount = 0;
    $processedSetCount = 0;
    $skippedSetCount = 0;
    $deletedMediaCount = 0;
    $mediaStorage = $this->entityTypeManager->getStorage('media');

    foreach ($setStates as $setState) {
      if ($setState['status'] === 'ready') {
        $readySetCount++;
        /** @var int[] $fileIds */
        $fileIds = $setState['file_ids'];
        $this->intakeSetListingMaterializer->materializeNewBookListing($fileIds);
        /** @var int[] $mediaIds */
        $mediaIds = $setState['media_ids'];
        if ($mediaIds !== []) {
          $mediaToDelete = $mediaStorage->loadMultiple($mediaIds);
          if ($mediaToDelete !== []) {
            $mediaStorage->delete($mediaToDelete);
            $deletedMediaCount += count($mediaToDelete);
          }
        }
        $processedSetCount++;
        continue;
      }
      $skippedSetCount++;
    }

    if ($readySetCount === 0) {
      $this->messenger()->addWarning($this->t('No ready staged sets were found. Ready sets require all intake images to be unlinked.'));
      $form_state->setRebuild(TRUE);
      return;
    }

    $this->messenger()->addStatus($this->t('Processed @processed staged set(s) into listings, removed @media intake media record(s), and skipped @skipped set(s) that were already done or partially linked.', [
      '@processed' => (string) $processedSetCount,
      '@media' => (string) $deletedMediaCount,
      '@skipped' => (string) $skippedSetCount,
    ]));
    $form_state->setRebuild(TRUE);
  }

  /**
   * @return array<int,array<int,string>>
   */
  private function buildRecentRows(): array {
    if (!$this->imageBundleExists()) {
      return [];
    }

    $ids = $this->entityTypeManager
      ->getStorage('media')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', self::INTAKE_MEDIA_BUNDLE)
      ->condition('uid', (int) $this->currentUser->id())
      ->sort('created', 'DESC')
      ->range(0, 25)
      ->execute();

    if ($ids === []) {
      return [];
    }

    $rows = [];
    /** @var \Drupal\media\MediaInterface[] $mediaEntities */
    $mediaEntities = $this->entityTypeManager->getStorage('media')->loadMultiple($ids);
    foreach ($mediaEntities as $media) {
      $fileName = '';
      $setLabel = '';
      $imageField = $media->get('field_media_image');
      if (!$imageField->isEmpty()) {
        $file = $imageField->entity;
        if ($file instanceof FileInterface) {
          $fileName = (string) $file->getFilename();
          $setLabel = $this->extractSetLabelFromFileUri((string) $file->getFileUri());
        }
      }

      $rows[] = [
        $setLabel !== '' ? $setLabel : (string) $this->t('Ungrouped'),
        (string) $media->id(),
        (string) $media->label(),
        $fileName,
        $this->dateFormatter->format((int) $media->get('created')->value, 'short'),
      ];
    }

    return $rows;
  }

  /**
   * @return array<int,array<int,string>>
   */
  private function buildStagedSetRows(): array {
    $rows = [];
    foreach ($this->loadIntakeSetStates() as $setState) {
      $rows[] = [
        (string) $setState['set_id'],
        (string) count($setState['file_ids']),
        (string) $setState['status_label'],
      ];
    }

    return $rows;
  }

  /**
   * @return array<int,array{set_id:string,file_ids:int[],media_ids:int[],status:string,status_label:string}>
   */
  private function loadIntakeSetStates(): array {
    if (!$this->imageBundleExists()) {
      return [];
    }

    $mediaIds = $this->entityTypeManager
      ->getStorage('media')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('bundle', self::INTAKE_MEDIA_BUNDLE)
      ->condition('uid', (int) $this->currentUser->id())
      ->sort('created', 'DESC')
      ->range(0, 1000)
      ->execute();
    if ($mediaIds === []) {
      return [];
    }

    /** @var \Drupal\media\MediaInterface[] $mediaEntities */
    $mediaEntities = $this->entityTypeManager->getStorage('media')->loadMultiple($mediaIds);

    $setFiles = [];
    $setMediaIds = [];
    foreach ($mediaEntities as $media) {
      if (!$media instanceof MediaInterface) {
        continue;
      }

      $imageField = $media->get('field_media_image');
      if ($imageField->isEmpty()) {
        continue;
      }

      $file = $imageField->entity;
      if (!$file instanceof FileInterface) {
        continue;
      }

      $setId = $this->extractSetLabelFromFileUri((string) $file->getFileUri());
      if ($setId === '') {
        continue;
      }
      $setFiles[$setId] ??= [];
      $setFiles[$setId][] = [
        'file_id' => (int) $file->id(),
        'filename' => mb_strtolower((string) $file->getFilename()),
        'created' => (int) ($media->get('created')->value ?? 0),
      ];
      $setMediaIds[$setId] ??= [];
      $setMediaIds[$setId][] = (int) $media->id();
    }
    if ($setFiles === []) {
      return [];
    }

    $allFileIds = [];
    foreach ($setFiles as $files) {
      foreach ($files as $fileInfo) {
        $fileId = (int) ($fileInfo['file_id'] ?? 0);
        if ($fileId <= 0) {
          continue;
        }
        $allFileIds[$fileId] = $fileId;
      }
    }
    if ($allFileIds === []) {
      return [];
    }

    if (!$this->entityTypeManager->hasDefinition('listing_image')) {
      return [];
    }

    $linkedFileIds = [];
    $listingImageIds = $this->entityTypeManager
      ->getStorage('listing_image')
      ->getQuery()
      ->accessCheck(FALSE)
      ->condition('file', array_values($allFileIds), 'IN')
      ->execute();
    if ($listingImageIds !== []) {
      $listingImages = $this->entityTypeManager->getStorage('listing_image')->loadMultiple($listingImageIds);
      foreach ($listingImages as $listingImage) {
        $fileId = (int) ($listingImage->get('file')->target_id ?? 0);
        if ($fileId > 0) {
          $linkedFileIds[$fileId] = $fileId;
        }
      }
    }

    $states = [];
    ksort($setFiles);
    foreach ($setFiles as $setId => $files) {
      // Preserve operator-visible capture order by filename (natural sort),
      // with stable fallbacks for mixed naming.
      usort($files, static function (array $a, array $b): int {
        $nameCompare = strnatcasecmp((string) ($a['filename'] ?? ''), (string) ($b['filename'] ?? ''));
        if ($nameCompare !== 0) {
          return $nameCompare;
        }
        $aCreated = (int) ($a['created'] ?? 0);
        $bCreated = (int) ($b['created'] ?? 0);
        if ($aCreated === $bCreated) {
          return ((int) ($a['file_id'] ?? 0)) <=> ((int) ($b['file_id'] ?? 0));
        }
        return $aCreated <=> $bCreated;
      });
      $fileIds = array_values(array_unique(array_map(
        static fn (array $item): int => (int) ($item['file_id'] ?? 0),
        $files
      )));
      $fileIds = array_values(array_filter($fileIds, static fn (int $id): bool => $id > 0));
      if ($fileIds === []) {
        continue;
      }

      $linkedCount = 0;
      foreach ($fileIds as $fileId) {
        if (isset($linkedFileIds[$fileId])) {
          $linkedCount++;
        }
      }

      $status = 'ready';
      $statusLabel = (string) $this->t('Ready');
      if ($linkedCount === count($fileIds)) {
        $status = 'done';
        $statusLabel = (string) $this->t('Already processed');
      }
      elseif ($linkedCount > 0) {
        $status = 'partial';
        $statusLabel = (string) $this->t('Partially processed');
      }

      $states[] = [
        'set_id' => $setId,
        'file_ids' => $fileIds,
        'media_ids' => array_values(array_unique(array_map('intval', $setMediaIds[$setId] ?? []))),
        'status' => $status,
        'status_label' => $statusLabel,
      ];
    }

    return $states;
  }

  private function imageBundleExists(): bool {
    $bundle = $this->entityTypeManager->getStorage('media_type')->load(self::INTAKE_MEDIA_BUNDLE);
    return $bundle !== NULL;
  }

  private function extractSetLabelFromFileUri(string $uri): string {
    $prefix = 'public://ai-intake/';
    if (!str_starts_with($uri, $prefix)) {
      return '';
    }

    $tail = substr($uri, strlen($prefix));
    if ($tail === FALSE || $tail === '') {
      return '';
    }

    $parts = explode('/', $tail, 2);
    return (string) ($parts[0] ?? '');
  }

  private function generateSetId(): string {
    return str_replace('.', '-', uniqid('set-', true));
  }

  /**
   * @return array<string,UploadedFile[]>
   */
  private function extractUploadedSets(): array {
    $requestFiles = \Drupal::request()->files->all();
    $sets = $requestFiles['intake_sets'] ?? [];

    $out = [];
    if (is_array($sets)) {
      foreach ($sets as $setKey => $value) {
        $uploads = [];
        $this->appendUploadedFiles($value, $uploads);
        if ($uploads !== []) {
          $out[(string) $setKey] = $uploads;
        }
      }
    }

    // Fallback for inputs posted under different trees.
    if ($out === []) {
      $this->collectUploadedSetsFromTree($requestFiles, $out);
      foreach ($out as $setKey => $uploads) {
        if ($uploads === []) {
          unset($out[$setKey]);
        }
      }
    }

    return $out;
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
   * @param array<string,mixed> $tree
   * @param array<string,UploadedFile[]> $out
   */
  private function collectUploadedSetsFromTree(array $tree, array &$out): void {
    foreach ($tree as $key => $value) {
      $setKey = (is_string($key) && preg_match('/^set_\\d+$/', $key) === 1) ? $key : NULL;
      if ($setKey !== NULL) {
        $uploads = [];
        $this->appendUploadedFiles($value, $uploads);
        if (!isset($out[$setKey])) {
          $out[$setKey] = [];
        }
        if ($uploads !== []) {
          $out[$setKey] = array_merge($out[$setKey], $uploads);
        }
      }

      if (is_array($value)) {
        $this->collectUploadedSetsFromTree($value, $out);
      }
    }
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
