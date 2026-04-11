<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Process\Process;

/**
 * Minimal client for a vision-language model served via vLLM.
 *
 * Uses the OpenAI-compatible API for chat completions with images.
 */
final class VlmClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
    private readonly VllmPoolRepositoryInterface $poolRepository,
    private readonly StateInterface $state,
  ) {}

  /**
   * Executes VLM inference with a prompt and one or more images.
   *
   * @param string $promptText
   *   Prompt text.
   * @param string[] $imagePaths
   *   Local image file paths.
   *
   * @return array{raw:string, parsed:?array}
   *   Raw model output plus best-effort parsed JSON object.
   */
  public function infer(string $promptText, array $imagePaths): array {
    $runtime = $this->resolveActivePooledRuntime();
    $vllmUrl = $runtime['url'];
    $vllmModel = $runtime['model'];

    $tmpDir = 'temporary://compute_orchestrator';
    $this->fileSystem->prepareDirectory($tmpDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $tmpRealDir = $this->fileSystem->realpath($tmpDir);

    $imageBase64List = [];

    foreach ($imagePaths as $inPath) {
      $outPath = $tmpRealDir . '/img.' . bin2hex(random_bytes(8)) . '.jpg';

      $this->convertToJpeg1024($inPath, $outPath);

      $imageBase64List[] = base64_encode((string) file_get_contents($outPath));

      @unlink($outPath);
    }

    $content = [
      [
        'type' => 'text',
        'text' => $promptText,
      ],
    ];

    foreach ($imageBase64List as $b64) {
      $content[] = [
        'type' => 'image_url',
        'image_url' => [
          'url' => 'data:image/jpeg;base64,' . $b64,
        ],
      ];
    }

    $payload = [
      'model' => $vllmModel,
      'messages' => [
        [
          'role' => 'user',
          'content' => $content,
        ],
      ],
      'max_tokens' => 400,
    ];

    $url = rtrim($vllmUrl, '/') . '/v1/chat/completions';
    $timeoutSeconds = $this->getInferenceTimeoutSeconds();

    $resp = $this->httpClient->request('POST', $url, [
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'json' => $payload,
      'timeout' => $timeoutSeconds,
      'connect_timeout' => min(10, $timeoutSeconds),
      'http_errors' => FALSE,
    ]);

    $statusCode = $resp->getStatusCode();
    $body = (string) $resp->getBody();
    if ($statusCode >= 400) {
      $summary = mb_substr(trim($body), 0, 500);
      throw new \RuntimeException(sprintf(
        'vLLM request failed with HTTP %d: %s',
        $statusCode,
        $summary !== '' ? $summary : '(empty response body)'
      ));
    }

    $data = json_decode($body, TRUE);

    $content = (string) ($data['choices'][0]['message']['content'] ?? '');
    $parsed = $this->extractFirstJsonObject($content);

    return [
      'raw' => $content,
      'parsed' => $parsed,
    ];
  }

  /**
   * Resolves endpoint/model from the active pooled lease.
   *
   * @return array{url:string, model:string}
   *   Resolved runtime endpoint and model.
   */
  private function resolveActivePooledRuntime(): array {
    $activeContract = trim((string) $this->state->get('compute_orchestrator.vllm_pool.active_contract_id', ''));
    if ($activeContract === '') {
      throw new \RuntimeException('No active pooled vLLM lease is set. Acquire a lease before inference.');
    }

    $record = $this->poolRepository->get($activeContract);
    if ($record === NULL) {
      throw new \RuntimeException('Active pooled contract ' . $activeContract . ' is not registered in pool inventory.');
    }

    if (($record['lease_status'] ?? '') !== 'leased') {
      throw new \RuntimeException('Active pooled contract ' . $activeContract . ' is not currently leased.');
    }

    $url = trim((string) ($record['url'] ?? ''));
    if ($url === '') {
      $host = trim((string) ($record['host'] ?? ''));
      $port = trim((string) ($record['port'] ?? ''));
      if ($host === '' || $port === '') {
        throw new \RuntimeException('Active pooled contract ' . $activeContract . ' has no reachable vLLM endpoint.');
      }
      $url = 'http://' . $host . ':' . $port;
    }

    $model = trim((string) ($record['current_model'] ?? 'Qwen/Qwen2-VL-7B-Instruct'));
    if ($model === '') {
      $model = 'Qwen/Qwen2-VL-7B-Instruct';
    }

    return [
      'url' => $url,
      'model' => $model,
    ];
  }

  /**
   * Converts an input image to a 1024px JPEG suitable for vLLM image payloads.
   */
  private function convertToJpeg1024(string $inPath, string $outPath): void {
    $p = new Process([
      '/usr/bin/convert',
      $inPath,
      '-auto-orient',
      '-resize',
      '1024x1024>',
      '-strip',
      '-quality',
      '85',
      $outPath,
    ]);
    $p->setTimeout(60);
    $p->run();

    if (!$p->isSuccessful()) {
      throw new \RuntimeException('convert failed: ' . trim($p->getErrorOutput() ?: $p->getOutput()));
    }
  }

  /**
   * Extracts the first JSON object from arbitrary model text output.
   */
  private function extractFirstJsonObject(string $text): ?array {
    $start = strpos($text, '{');
    if ($start === FALSE) {
      return NULL;
    }
    $end = strrpos($text, '}');
    if ($end === FALSE || $end <= $start) {
      return NULL;
    }
    $json = substr($text, $start, ($end - $start) + 1);
    $decoded = json_decode($json, TRUE);
    return is_array($decoded) ? $decoded : NULL;
  }

  /**
   * Returns the current inference timeout (seconds) from state.
   */
  private function getInferenceTimeoutSeconds(): int {
    $value = (int) $this->state->get('compute_orchestrator.vllm.infer_timeout', 90);
    if ($value < 5) {
      return 5;
    }

    return $value;
  }

}
