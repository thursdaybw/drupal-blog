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

final class BookConditionController extends ControllerBase {

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

    $promptText = "Assess the physical condition of the book based only on visible damage in the provided images. Do not speculate about pages not shown. Return only JSON with keys condition_grade and visible_issues (array of short strings).";

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

    $resp = $this->httpClient->request('POST', $url, [
      'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
      ],
      'json' => $payload,
      'timeout' => 180,
      'http_errors' => false,
    ]);

    $body = (string) $resp->getBody();
    $data = json_decode($body, true);

    $content = (string) ($data['choices'][0]['message']['content'] ?? '');
    $parsed = $this->extractFirstJsonObject($content);

    if (is_array($parsed) && isset($parsed['condition_grade']) && isset($parsed['visible_issues'])) {
      return new JsonResponse([
        'condition_grade' => (string) $parsed['condition_grade'],
        'visible_issues' => array_values(array_map('strval', $parsed['visible_issues'])),
        'raw' => $content,
      ]);
    }

    return new JsonResponse([
      'error' => 'parse_failed',
      'raw' => $content,
    ], 200);
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

}
