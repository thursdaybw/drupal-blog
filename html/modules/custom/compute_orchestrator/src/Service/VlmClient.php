<?php

declare(strict_types=1);

namespace Drupal\compute_orchestrator\Service;

use Drupal\Core\File\FileSystemInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\Process\Process;

final class VlmClient {

  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly FileSystemInterface $fileSystem,
  ) {}

  /**
   * @param string[] $imagePaths
   * @return array{raw:string, parsed:?array}
   */
  public function infer(string $promptText, array $imagePaths): array {

    $vllmUrl = (string) \Drupal::state()->get('compute.vllm_url', '');
    $vllmHost = (string) \Drupal::state()->get('compute.vllm_host', '');
    $vllmPort = (string) \Drupal::state()->get('compute.vllm_port', '');

    if ($vllmUrl === '') {
      if ($vllmHost === '' || $vllmPort === '') {
        throw new \RuntimeException('vLLM not configured.');
      }
      $vllmUrl = 'http://' . $vllmHost . ':' . $vllmPort;
    }

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

    return [
      'raw' => $content,
      'parsed' => $parsed,
    ];
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
