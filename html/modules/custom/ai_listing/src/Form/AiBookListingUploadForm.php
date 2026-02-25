<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\ai_listing\Entity\AiBookListing;
use Drupal\file\Entity\File;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\DependencyInjection\DependencySerializationTrait;

final class AiBookListingUploadForm extends FormBase {

  use DependencySerializationTrait;

  private FileSystemInterface $fileSystem;
  private EntityTypeManagerInterface $entityTypeManager;

  public function __construct(
    FileSystemInterface $fileSystem,
    EntityTypeManagerInterface $entityTypeManager
  ) {
    $this->fileSystem = $fileSystem;
    $this->entityTypeManager = $entityTypeManager;
  }

  public static function create(ContainerInterface $container): self {
    return new static(
      $container->get('file_system'),
      $container->get('entity_type.manager')
    );
  }

  public function getFormId(): string {
    return 'ai_book_listing_upload_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {

    $form['images'] = [
      '#type' => 'managed_file',
      '#title' => 'Book Images',
      '#upload_location' => 'public://ai-listings/tmp/',
      '#multiple' => TRUE,
      '#required' => TRUE,
    ];

    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => 'Save Listing',
    ];

    return $form;
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {

    $fileIds = $form_state->getValue('images');

    if (empty($fileIds)) {
      return;
    }

    // Create listing first so we have UUID.
    $listing = AiBookListing::create([
      'status' => 'new',
    ]);

    $listing->save();

    $uuid = $listing->uuid();
    $targetDirectory = "public://ai-listings/{$uuid}";

    $this->fileSystem->prepareDirectory(
      $targetDirectory,
      FileSystemInterface::CREATE_DIRECTORY |
      FileSystemInterface::MODIFY_PERMISSIONS
    );

    foreach ($fileIds as $fid) {

      $file = File::load($fid);

      if (!$file) {
        continue;
      }

      $originalUri = $file->getFileUri();
      $filename = basename($originalUri);
      $newUri = $targetDirectory . '/' . $filename;

      $this->fileSystem->move($originalUri, $newUri);

      $file->setFileUri($newUri);
      $file->setPermanent();
      $file->save();

      $listing->get('images')->appendItem($file->id());
    }

    $listing->save();

    $this->messenger()->addStatus('Listing created.');

    $form_state->setRedirect('entity.ai_book_listing.add_form');
  }

}
