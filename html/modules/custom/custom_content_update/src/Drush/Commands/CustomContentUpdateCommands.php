<?php

namespace Drupal\custom_content_update\Drush\Commands;

use Drush\Commands\DrushCommands;
use Symfony\Component\Yaml\Yaml;

class CustomContentUpdateCommands extends DrushCommands {

  /**
   * Drush command to update default content UUIDs in the specified module's info file.
   *
   * @command custom_content_update:update
   * @param string $module
   *   The machine name of the module to update.
   * @param string|null $entity_type
   *   The entity type to filter by (optional).
   * @usage custom_content_update:update my_module node
   *   Update default content UUIDs in the module info file.
   */
  public function updateDefaultContent($module, $entity_type = 'node') {
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
    $entity_storage = \Drupal::entityTypeManager()->getStorage($entity_type);
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

}

