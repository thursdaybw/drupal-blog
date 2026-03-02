<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Command;

use Drupal\Core\State\StateInterface;
use Drupal\compute_orchestrator\Service\VastRestClientInterface;
use Drush\Commands\DrushCommands;

final class VllmInstancePoolCommand extends DrushCommands {

  private const DEFAULT_POOL_FILE = '/var/www/html/vllm-pool.json';

  public function __construct(
    private readonly StateInterface $state,
    private readonly VastRestClientInterface $vastClient,
  ) {
    parent::__construct();
  }

  /**
   * Export current vLLM instance state to a keyed JSON file.
   *
   * @command compute:vllm-pool-export
   * @param string|null $instanceId
   *   Vast instance ID to store under. Defaults to the current contract_id.
   * @param string|null $file
   *   JSON file path. Defaults to /var/www/html/vllm-pool.json.
   */
  public function export(?string $instanceId = NULL, ?string $file = NULL): void {
    $instance = $this->loadCurrentInstanceState();
    if ($instance === NULL) {
      $this->output()->writeln('<error>No current vLLM instance state found.</error>');
      return;
    }

    $file = $this->resolvePoolFile($file);
    $document = $this->readPoolDocument($file);
    $instanceId = $this->resolveInstanceId($instanceId, $instance);
    if ($instanceId === '') {
      $this->output()->writeln('<error>No instance ID provided and current state has no contract_id.</error>');
      return;
    }

    $document['instances'][$instanceId] = [
      'host' => $instance['host'],
      'port' => $instance['port'],
      'url' => $instance['url'],
      'contract_id' => $instance['contract_id'],
      'image' => $instance['image'],
      'model' => $instance['model'],
      'set_at' => $instance['set_at'],
      'exported_at' => time(),
    ];

    $this->writePoolDocument($file, $document);
    $this->output()->writeln(sprintf('Exported current vLLM instance to %s as %s.', $file, $instanceId));
  }

  /**
   * Import one named vLLM instance from a JSON file into Drupal state.
   *
   * @command compute:vllm-pool-import
   * @param string $instanceId
   *   Vast instance ID to load.
   * @param string|null $file
   *   JSON file path. Defaults to /var/www/html/vllm-pool.json.
   */
  public function import(string $instanceId, ?string $file = NULL): void {
    $file = $this->resolvePoolFile($file);
    $document = $this->readPoolDocument($file);
    $instances = $document['instances'] ?? [];

    if (!is_array($instances) || !isset($instances[$instanceId]) || !is_array($instances[$instanceId])) {
      $this->output()->writeln(sprintf('<error>Instance ID %s not found in %s.</error>', $instanceId, $file));
      return;
    }

    $instance = $instances[$instanceId];
    $host = trim((string) ($instance['host'] ?? ''));
    $port = trim((string) ($instance['port'] ?? ''));
    $url = trim((string) ($instance['url'] ?? ''));
    $contractId = trim((string) ($instance['contract_id'] ?? ''));
    $image = trim((string) ($instance['image'] ?? ''));
    $model = trim((string) ($instance['model'] ?? ''));
    $setAt = (int) ($instance['set_at'] ?? time());

    $liveEndpoint = $this->loadLiveEndpoint($contractId);
    if ($liveEndpoint !== NULL) {
      $host = $liveEndpoint['host'];
      $port = $liveEndpoint['port'];
      $url = $liveEndpoint['url'];
    }

    if ($host === '' || $port === '' || $url === '') {
      $this->output()->writeln(sprintf('<error>Instance ID %s in %s is missing host, port, or url.</error>', $instanceId, $file));
      return;
    }

    $this->state->set('compute.vllm_host', $host);
    $this->state->set('compute.vllm_port', $port);
    $this->state->set('compute.vllm_url', $url);
    $this->state->set('compute.vllm_contract_id', $contractId);
    $this->state->set('compute.vllm_image', $image);
    $this->state->set('compute.vllm_model', $model);
    $this->state->set('compute.vllm_set_at', $setAt);

    $this->output()->writeln(sprintf('Loaded vLLM instance %s from %s into Drupal state.', $instanceId, $file));
  }

  /**
   * List saved instance keys from a JSON file.
   *
   * @command compute:vllm-pool-list
   * @param string|null $file
   *   JSON file path. Defaults to /var/www/html/vllm-pool.json.
   */
  public function list(?string $file = NULL): void {
    $file = $this->resolvePoolFile($file);
    $document = $this->readPoolDocument($file);
    $instances = $document['instances'] ?? [];

    if (!is_array($instances) || $instances === []) {
      $this->output()->writeln(sprintf('No saved instances in %s.', $file));
      return;
    }

    foreach ($instances as $name => $instance) {
      if (!is_array($instance)) {
        continue;
      }

      $host = (string) ($instance['host'] ?? '');
      $port = (string) ($instance['port'] ?? '');
      $contractId = (string) ($instance['contract_id'] ?? '');
      $model = (string) ($instance['model'] ?? '');
      $this->output()->writeln(sprintf('%s: %s:%s contract=%s model=%s', $name, $host, $port, $contractId, $model));
    }
  }

  /**
   * @return array{host:string,port:string,url:string,contract_id:string,image:string,model:string,set_at:int}|null
   */
  private function loadCurrentInstanceState(): ?array {
    $host = trim((string) $this->state->get('compute.vllm_host', ''));
    $port = trim((string) $this->state->get('compute.vllm_port', ''));
    $url = trim((string) $this->state->get('compute.vllm_url', ''));
    $contractId = trim((string) $this->state->get('compute.vllm_contract_id', ''));
    $image = trim((string) $this->state->get('compute.vllm_image', ''));
    $model = trim((string) $this->state->get('compute.vllm_model', ''));
    $setAt = (int) $this->state->get('compute.vllm_set_at', 0);

    if ($host === '' || $port === '' || $url === '') {
      return NULL;
    }

    return [
      'host' => $host,
      'port' => $port,
      'url' => $url,
      'contract_id' => $contractId,
      'image' => $image,
      'model' => $model,
      'set_at' => $setAt,
    ];
  }

  /**
   * @return array{instances:array<string,mixed>}
   */
  private function readPoolDocument(string $file): array {
    if (!is_file($file)) {
      return ['instances' => []];
    }

    $contents = file_get_contents($file);
    if ($contents === FALSE || trim($contents) === '') {
      return ['instances' => []];
    }

    $decoded = json_decode($contents, TRUE);
    if (!is_array($decoded)) {
      throw new \RuntimeException(sprintf('Pool file %s does not contain valid JSON.', $file));
    }

    if (!isset($decoded['instances']) || !is_array($decoded['instances'])) {
      $decoded['instances'] = [];
    }

    return $decoded;
  }

  private function resolvePoolFile(?string $file): string {
    $resolvedFile = trim((string) $file);
    if ($resolvedFile !== '') {
      return $resolvedFile;
    }

    return self::DEFAULT_POOL_FILE;
  }

  /**
   * @return array{host:string,port:string,url:string}|null
   */
  private function loadLiveEndpoint(string $contractId): ?array {
    if ($contractId === '') {
      return NULL;
    }

    try {
      $info = $this->vastClient->showInstance($contractId);
    }
    catch (\Throwable $exception) {
      $this->output()->writeln(sprintf(
        '<comment>Unable to refresh live endpoint for contract %s: %s</comment>',
        $contractId,
        $exception->getMessage()
      ));
      return NULL;
    }

    $host = trim((string) ($info['public_ipaddr'] ?? ''));
    $port = $this->extractPublicPort($info);
    if ($host === '' || $port === '') {
      $this->output()->writeln(sprintf(
        '<comment>Contract %s has no current public vLLM endpoint. Using saved pool values.</comment>',
        $contractId
      ));
      return NULL;
    }

    return [
      'host' => $host,
      'port' => $port,
      'url' => 'http://' . $host . ':' . $port,
    ];
  }

  private function extractPublicPort(array $instanceInfo): string {
    $ports = $instanceInfo['ports'] ?? [];
    if (!is_array($ports)) {
      return '';
    }

    foreach ($ports as $key => $value) {
      if (!str_contains((string) $key, '8000')) {
        continue;
      }

      if (!is_array($value) || !isset($value[0]['HostPort'])) {
        continue;
      }

      return trim((string) $value[0]['HostPort']);
    }

    return '';
  }

  /**
   * @param array{host:string,port:string,url:string,contract_id:string,image:string,model:string,set_at:int} $instance
   */
  private function resolveInstanceId(?string $instanceId, array $instance): string {
    $normalizedInstanceId = trim((string) $instanceId);
    if ($normalizedInstanceId !== '') {
      return $normalizedInstanceId;
    }

    return trim((string) ($instance['contract_id'] ?? ''));
  }

  /**
   * @param array<string,mixed> $document
   */
  private function writePoolDocument(string $file, array $document): void {
    $directory = dirname($file);
    if (!is_dir($directory) && !mkdir($directory, 0775, TRUE) && !is_dir($directory)) {
      throw new \RuntimeException(sprintf('Unable to create directory %s.', $directory));
    }

    $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($json === FALSE) {
      throw new \RuntimeException('Unable to encode pool document as JSON.');
    }

    if (file_put_contents($file, $json . PHP_EOL) === FALSE) {
      throw new \RuntimeException(sprintf('Unable to write pool file %s.', $file));
    }
  }

}
