<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Process\Process;

final class BookExtractController extends ControllerBase {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('http_client'),
      $container->get('file_system'),
    );
  }

  public function extract(Request $request): JsonResponse {
    $images = $request->files->all('images');

    if (empty($images) || !is_array($images)) {
      return new JsonResponse([
        'error' => 'missing_files',
        'msg' => 'POST multipart form-data with files: images[]',
      ], 400);
    }

    $vllmUrl = (string) \Drupal::state()->get('compute.vllm_url', '');
    $vllmHost = (string) \Drupal::state()->get('compute.vllm_host', '');
    $vllmPort = (string) \Drupal::state()->get('compute.vllm_port', '');

    if ($vllmUrl === '') {
      if ($vllmHost === '' || $vllmPort === '') {
        return new JsonResponse([
          'error' => 'vllm_not_configured',
          'msg' => 'Missing compute.vllm_url and also missing compute.vllm_host/compute.vllm_port in Drupal state. Run drush compute:test-vast --preserve first.',
        ], 500);
      }
      $vllmUrl = 'http://' . $vllmHost . ':' . $vllmPort;
    }

    $tmpDir = 'temporary://compute_orchestrator';
    $this->fileSystem->prepareDirectory($tmpDir, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $tmpRealDir = $this->fileSystem->realpath($tmpDir);

    $imageBase64List = [];

    foreach ($images as $image) {
      $inPath = $image->getPathname();
      $outPath = $tmpRealDir . '/img.' . bin2hex(random_bytes(8)) . '.jpg';

      $this->convertToJpeg1024($inPath, $outPath);

      $imageBase64List[] = base64_encode((string) file_get_contents($outPath));

      @unlink($outPath);
    }

    $promptText = "Extract the book title and author. Return only JSON with keys title and author.";

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
      'model' => 'Qwen/Qwen2-VL-7B-Instruct',
      'messages' => [
        [
          'role' => 'user',
          'content' => $content,
        ],
      ],
      'max_tokens' => 400,
    ];

    $url = rtrim($vllmUrl, '/') . '/v1/chat/completions';

    $requestId = bin2hex(random_bytes(8));

    try {
      // Preflight: fail fast if endpoint is dead or stale.
      $preflight = $this->httpClient->request('GET', rtrim($vllmUrl, '/') . '/v1/models', [
        'headers' => [
          'Accept' => 'application/json',
          'X-Request-Id' => $requestId,
        ],
        'timeout' => 5,
        'connect_timeout' => 3,
        'http_errors' => false,
      ]);

      $preflightCode = $preflight->getStatusCode();
      if ($preflightCode < 200 || $preflightCode >= 300) {
        $preflightBody = (string) $preflight->getBody();
        return new JsonResponse([
          'error' => 'vllm_unhealthy',
          'status' => $preflightCode,
          'body' => $preflightBody,
          'url' => 'http://' . $vllmHost . ':' . $vllmPort . '/v1/models',
          'request_id' => $requestId,
        ], 502);
      }

      // POST with retries on transient upstream failures.
      [$statusCode, $body] = $this->postJsonWithRetry($url, $payload, $requestId);

      if ($statusCode < 200 || $statusCode >= 300) {
        return new JsonResponse([
          'error' => 'upstream_http_error',
          'status' => $statusCode,
          'body' => $body,
          'url' => $url,
          'request_id' => $requestId,
        ], 502);
      }

      $data = json_decode($body, true);
      if (!is_array($data)) {
        return new JsonResponse([
          'error' => 'invalid_upstream_json',
          'status' => $statusCode,
          'body' => $body,
          'url' => $url,
          'request_id' => $requestId,
        ], 502);
      }


      $content = (string) ($data['choices'][0]['message']['content'] ?? '');
      // Best-effort parse: vLLM sometimes wraps JSON in text.
      $parsed = $this->extractFirstJsonObject($content);

      if (is_array($parsed) && isset($parsed['title']) && isset($parsed['author'])) {
        return new JsonResponse([
          'title' => (string) $parsed['title'],
          'author' => (string) $parsed['author'],
          'raw' => $content,
        ]);
      }

      return new JsonResponse([
        'error' => 'parse_failed',
        'raw' => $content,
        'vllm_response' => $data,
        'request_id' => $requestId,
      ], 200);

    }

    catch (\Throwable $e) {
      return new JsonResponse([
        'error' => 'vllm_request_failed',
        'msg' => $e->getMessage(),
        'url' => $url,
        'request_id' => $requestId ?? '',
      ], 502);

    }
  }

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
   * Extract the first JSON object found in a string, decode it, return array|null.
   */
  private function extractFirstJsonObject(string $text): ?array {
    $start = strpos($text, '{');
    if ($start === false) {
      return null;
    }
    $end = strrpos($text, '}');
    if ($end === false || $end <= $start) {
      return null;
    }
    $json = substr($text, $start, ($end - $start) + 1);
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : null;
  }

  /**
   * POST JSON with small retry on transient upstream failures.
   *
   * @return array{0:int,1:string} [statusCode, body]
   */
  private function postJsonWithRetry(string $url, array $payload, string $requestId): array {
    $attempts = 0;
    $maxAttempts = 3;

    while (true) {
      $attempts++;

      try {
        $resp = $this->httpClient->request('POST', $url, [
          'headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-Request-Id' => $requestId,
          ],
          'json' => $payload,
          'timeout' => 180,
          'connect_timeout' => 10,
          'http_errors' => false,
        ]);

        $code = $resp->getStatusCode();
        $body = (string) $resp->getBody();

        // Treat these as transient.
        if (in_array($code, [429, 500, 502, 503, 504], true) && $attempts < $maxAttempts) {
          usleep(250000 * $attempts); // 250ms, 500ms
          continue;
        }

        // Non-2xx: bubble details up to caller via body+code.
        return [$code, $body];
      }
      catch (\Throwable $e) {
        if ($attempts < $maxAttempts) {
          usleep(250000 * $attempts);
          continue;
        }
        throw $e;
      }
    }
  }

}
