<?php

namespace Drupal\custom_content_update\Drush\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\default_content\ExporterInterface;
use Drush\Drush;

class CustomContentUpdateCommands extends DrushCommands {

  protected $entityTypeManager;
  protected $defaultContentExporter;

  public function __construct(EntityTypeManagerInterface $entityTypeManager, ExporterInterface $defaultContentExporter) {
    parent::__construct();
    $this->entityTypeManager = $entityTypeManager;
    $this->defaultContentExporter = $defaultContentExporter;
    $this->logger = Drush::logger();
  }

  /**
   * Drush command to update default content UUIDs in the module info file.
   *
   * @command custom_content_update:update
   * @param string $module
   *   The machine name of the module to update.
   * @param string|null $entity_type
   *   The entity type to filter by (optional).
   * @usage custom_content_update:update my_module node
   *   Update default content UUIDs in the module info file.
   */
  public function update($module, $entity_type = 'node') {
    $this->updateDefaultContent($module, $entity_type);
  }

  /**
   * Drush command to update default content UUIDs and export content.
   *
   * @command custom_content_update:update_and_export
   * @param string $module
   *   The machine name of the module to update and export content for.
   * @param string|null $entity_type
   *   The entity type to filter by (optional).
   * @usage custom_content_update:update_and_export my_module node
   *   Update default content UUIDs in the module info file and export content.
   */
  public function updateAndExport($module, $entity_type = 'node') {
    // Update default content UUIDs.
    $this->updateDefaultContent($module, $entity_type);

    // Export content for the module.
    $this->contentExportModule($module, $entity_type);
  }

  /**
   * Update default content UUIDs in the specified module's info file.
   */
  protected function updateDefaultContent($module, $entity_type = 'node') {
    // Validate entity type.
    if (!$this->entityTypeManager->hasDefinition($entity_type)) {
      $this->logger()->error(dt('The "@entity_type" entity type does not exist.', ['@entity_type' => $entity_type]));
      return;
    }

    // Load existing module info file.
    $module_path = \Drupal::service('extension.list.module')->getPath($module);
    $info_file = $module_path . "/$module.info.yml";
    if (!file_exists($info_file)) {
      $this->logger()->error(dt('The module info file does not exist.'));
      return;
    }

    $info = Yaml::parseFile($info_file);
    $existing_uuids = isset($info['default_content'][$entity_type]) ? $info['default_content'][$entity_type] : [];

    // Find all content of the specified entity type.
    $entity_storage = $this->entityTypeManager->getStorage($entity_type);
    $entities = $entity_storage->loadMultiple();
    $new_uuids = [];
    foreach ($entities as $entity) {
      $new_uuids[] = $entity->uuid();
    }

    // Update the module's info file.
    $info['default_content'][$entity_type] = $new_uuids;
    file_put_contents($info_file, Yaml::dump($info));

    $this->logger()->success(dt('Updated default content UUIDs in the module info file.'));
  }

  /**
   * Export content for the specified module and entity type.
   */
  protected function contentExportModule($module, $entity_type = 'node') {
    $module_folder = \Drupal::moduleHandler()
      ->getModule($module)
      ->getPath() . '/content';

    // Export referenced entities.
    $entities = \Drupal::entityQuery($entity_type)
      ->accessCheck(FALSE)
      ->execute();
    foreach ($entities as $entity_id) {
      $this->exportEntityWithReferences($module, $entity_type, $entity_id, $module_folder);
    }

    $this->logger()->success(dt('Exported content for the module.'));
  }

  /**
   * Export an entity and its references, including encoding file data.
   */
  protected function exportEntityWithReferences($module, $entity_type, $entity_id, $module_folder) {
    $entity = $this->entityTypeManager->getStorage($entity_type)->load($entity_id);
    if ($entity) {
      $exported_content = $this->defaultContentExporter->exportContent($entity_type, $entity_id, $module_folder);

      // Locate and encode file data.
      if ($entity_type == 'file' || $entity->hasField('field_media_image')) {
        $file_path = $entity_type == 'file' ? $entity->getFileUri() : $entity->get('field_media_image')->entity->getFileUri();
        if (file_exists($file_path)) {
          $file_content = file_get_contents($file_path);
          $encoded_file_content = base64_encode($file_content);
          $exported_content['_embedded_file'] = [
            'filename' => $entity->label(),
            'filedata' => $encoded_file_content,
          ];
        }
      }

      // Write the updated content to the YAML file.
      $yaml_content = Yaml::dump($exported_content);
      file_put_contents($module_folder . '/' . $entity_type . '_' . $entity_id . '.yml', $yaml_content);
    }
  }

  /**
   * Import an entity and its references, including decoding file data.
   */
  public function importEntityWithReferences($module, $entity_type, $entity_id, $module_folder) {
    $yaml_file = $module_folder . '/' . $entity_type . '_' . $entity_id . '.yml';
    if (file_exists($yaml_file)) {
      $content = Yaml::parseFile($yaml_file);

      // Decode and save file data.
      if (isset($content['_embedded_file'])) {
        $decoded_file_content = base64_decode($content['_embedded_file']['filedata']);
        $file_path = 'public://' . $content['_embedded_file']['filename'];
        file_put_contents($file_path, $decoded_file_content);
        $content['uri'] = 'public://' . $content['_embedded_file']['filename'];
      }

      // Import the content using the default content importer.
      $this->defaultContentExporter->importContent($entity_type, $content, $module_folder);
    }
  }
}

