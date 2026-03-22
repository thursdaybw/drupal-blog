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
use Drupal\file\FileInterface;
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

    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>Upload backlog photos once, then attach them to listings later without waiting on per-listing uploads.</p>',
    ];

    $form['sets'] = [
      '#type' => 'container',
      '#attributes' => ['id' => 'ai-bulk-intake-sets-root'],
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
      ],
      '#name' => 'intake_sets[set_1][]',
    ];
    $form['sets']['add_set'] = [
      '#type' => 'html_tag',
      '#tag' => 'button',
      '#value' => $this->t('Start new set'),
      '#attributes' => [
        'type' => 'button',
        'data-ai-bulk-intake-add-set' => '1',
        'class' => ['button'],
      ],
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Intake images'),
      '#button_type' => 'primary',
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
    $listingCount = 0;

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

      $setFileIds = [];

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
        $setFileIds[] = (int) $file->id();
      }

      if ($setFileIds !== []) {
        $this->intakeSetListingMaterializer->materializeNewBookListing($setFileIds);
        $listingCount++;
      }
    }

    $this->messenger()->addStatus($this->t('Ingested @count image(s) across @sets set(s) and created @listings new listing(s).', [
      '@count' => (string) $created,
      '@sets' => (string) $setCount,
      '@listings' => (string) $listingCount,
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
        $setLabel ?? $this->t('Ungrouped')->render(),
        (string) $media->id(),
        (string) $media->label(),
        $fileName,
        $this->dateFormatter->format((int) $media->get('created')->value, 'short'),
      ];
    }

    return $rows;
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
