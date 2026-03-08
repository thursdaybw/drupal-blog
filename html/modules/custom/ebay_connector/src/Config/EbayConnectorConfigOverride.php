<?php

declare(strict_types=1);

namespace Drupal\ebay_connector\Config;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigCollectionInfo;
use Drupal\Core\Config\ConfigCrudEvent;
use Drupal\Core\Config\ConfigFactoryOverrideBase;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\ConfigRenameEvent;
use Drupal\Core\Config\StorageInterface;

final class EbayConnectorConfigOverride extends ConfigFactoryOverrideBase implements ConfigFactoryOverrideInterface {

  public function loadOverrides($names): array {
    if (!in_array('ebay_connector.settings', $names, TRUE)) {
      return [];
    }

    $overrides = [];

    $environment = $this->readEnv('EBAY_CONNECTOR_ENVIRONMENT');
    if ($environment !== '') {
      $overrides['environment'] = $environment;
    }

    $prodClientId = $this->readEnv('EBAY_CONNECTOR_PRODUCTION_CLIENT_ID');
    if ($prodClientId !== '') {
      $overrides['production']['client_id'] = $prodClientId;
    }

    $prodClientSecret = $this->readEnv('EBAY_CONNECTOR_PRODUCTION_CLIENT_SECRET');
    if ($prodClientSecret !== '') {
      $overrides['production']['client_secret'] = $prodClientSecret;
    }

    $prodRuName = $this->readEnv('EBAY_CONNECTOR_PRODUCTION_RU_NAME');
    if ($prodRuName !== '') {
      $overrides['production']['ru_name'] = $prodRuName;
    }

    $sandboxClientId = $this->readEnv('EBAY_CONNECTOR_SANDBOX_CLIENT_ID');
    if ($sandboxClientId !== '') {
      $overrides['sandbox']['client_id'] = $sandboxClientId;
    }

    $sandboxClientSecret = $this->readEnv('EBAY_CONNECTOR_SANDBOX_CLIENT_SECRET');
    if ($sandboxClientSecret !== '') {
      $overrides['sandbox']['client_secret'] = $sandboxClientSecret;
    }

    $sandboxRuName = $this->readEnv('EBAY_CONNECTOR_SANDBOX_RU_NAME');
    if ($sandboxRuName !== '') {
      $overrides['sandbox']['ru_name'] = $sandboxRuName;
    }

    if ($overrides === []) {
      return [];
    }

    return ['ebay_connector.settings' => $overrides];
  }

  public function getCacheSuffix(): string {
    return 'EbayConnectorConfigOverride';
  }

  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  public function getCacheableMetadata($name): CacheableMetadata {
    return new CacheableMetadata();
  }

  public function addCollections(ConfigCollectionInfo $collection_info): void {}

  public function onConfigSave(ConfigCrudEvent $event): void {}

  public function onConfigDelete(ConfigCrudEvent $event): void {}

  public function onConfigRename(ConfigRenameEvent $event): void {}

  private function readEnv(string $name): string {
    $value = getenv($name);
    return is_string($value) ? trim($value) : '';
  }

}
