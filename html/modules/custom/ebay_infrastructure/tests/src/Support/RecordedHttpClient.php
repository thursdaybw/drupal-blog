<?php

declare(strict_types=1);

namespace Drupal\Tests\ebay_infrastructure\Support;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\UriInterface;

/**
 * Small fake HTTP client for eBay kernel tests.
 *
 * What this is for:
 * - queue fake eBay API responses
 * - capture the outbound requests made by the real SellApiClient
 * - let kernel tests assert on payloads without calling the real network
 *
 * Why it lives in ebay_infrastructure:
 * this is shared test support for anything that talks to eBay through the
 * infrastructure layer. Other modules can reuse it instead of each test
 * inventing its own fake HTTP pattern.
 */
final class RecordedHttpClient implements ClientInterface {

  /**
   * @var array<int,array{method:string,url:string,options:array}>
   */
  private array $requests = [];

  /**
   * @var array<int,\Psr\Http\Message\ResponseInterface>
   */
  private array $queuedResponses = [];

  public function queueResponse(ResponseInterface $response): void {
    $this->queuedResponses[] = $response;
  }

  public function queueJsonResponse(array $data, int $statusCode = 200): void {
    $this->queueResponse(
      new Response(
        $statusCode,
        ['Content-Type' => 'application/json'],
        json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
      )
    );
  }

  /**
   * @return array<int,array{method:string,url:string,options:array}>
   */
  public function getRequests(): array {
    return $this->requests;
  }

  public function clearRecordedRequests(): void {
    $this->requests = [];
  }

  public function findRequest(string $method, string $pathFragment): ?array {
    foreach ($this->requests as $request) {
      if ($request['method'] !== $method) {
        continue;
      }

      if (!str_contains($request['url'], $pathFragment)) {
        continue;
      }

      return $request;
    }

    return NULL;
  }

  public function findRequestByPath(string $method, string $exactPath): ?array {
    foreach ($this->requests as $request) {
      if ($request['method'] !== $method) {
        continue;
      }

      $path = parse_url($request['url'], PHP_URL_PATH);
      if ($path !== $exactPath) {
        continue;
      }

      return $request;
    }

    return NULL;
  }

  public function findJsonPayload(string $method, string $pathFragment): ?array {
    $request = $this->findRequest($method, $pathFragment);
    if ($request === NULL) {
      return NULL;
    }

    return $request['options']['json'] ?? NULL;
  }

  public function send(RequestInterface $request, array $options = []): ResponseInterface {
    return $this->request($request->getMethod(), (string) $request->getUri(), $options);
  }

  public function sendAsync(RequestInterface $request, array $options = []): PromiseInterface {
    return Create::promiseFor($this->send($request, $options));
  }

  public function request(string $method, $uri, array $options = []): ResponseInterface {
    $url = $uri instanceof UriInterface ? (string) $uri : (string) $uri;

    $this->requests[] = [
      'method' => $method,
      'url' => $url,
      'options' => $options,
    ];

    $response = array_shift($this->queuedResponses);
    if (!$response instanceof ResponseInterface) {
      throw new \RuntimeException('No fake HTTP response was queued for ' . $method . ' ' . $url);
    }

    return $response;
  }

  public function requestAsync(string $method, $uri, array $options = []): PromiseInterface {
    return Create::promiseFor($this->request($method, $uri, $options));
  }

  public function getConfig(?string $option = NULL) {
    return NULL;
  }

}
