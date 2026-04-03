<?php

declare(strict_types=1);

namespace Drupal\ai_listing\Controller;

use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AiListingBulkIntakeUploadController implements ContainerInjectionInterface {
  private const INTAKE_MEDIA_BUNDLE = 'ai_listing_intake';

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly FileSystemInterface $fileSystem,
    private readonly AccountProxyInterface $currentUser,
  ) {}

  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('file_system'),
      $container->get('current_user'),
    );
  }

  public function uploadChunk(Request $request): JsonResponse {
    if (!$this->imageBundleExists()) {
      return $this->errorResponse('Intake media bundle is not available.', Response::HTTP_BAD_REQUEST);
    }

    $setId = trim((string) $request->request->get('set_id', ''));
    $uploadId = trim((string) $request->request->get('upload_id', ''));
    $chunkIndex = (int) $request->request->get('chunk_index', -1);
    $chunkCount = (int) $request->request->get('chunk_count', 0);
    $chunkStart = (int) $request->request->get('chunk_start', -1);
    $fileSize = (int) $request->request->get('file_size', 0);
    $originalFilename = trim((string) $request->request->get('file_name', 'upload.bin'));
    if ($setId === '' || $uploadId === '' || $chunkIndex < 0 || $chunkCount < 1 || $chunkIndex >= $chunkCount || $chunkStart < 0 || $fileSize < 1) {
      return $this->errorResponse('Invalid upload chunk metadata.', Response::HTTP_BAD_REQUEST);
    }

    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $setId)) {
      return $this->errorResponse('Invalid set identifier.', Response::HTTP_BAD_REQUEST);
    }
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $uploadId)) {
      return $this->errorResponse('Invalid upload identifier.', Response::HTTP_BAD_REQUEST);
    }

    $uploadedChunk = $request->files->get('chunk');
    if (!$uploadedChunk instanceof \Symfony\Component\HttpFoundation\File\UploadedFile) {
      return $this->errorResponse('Missing chunk file.', Response::HTTP_BAD_REQUEST);
    }
    if ($uploadedChunk->getError() !== UPLOAD_ERR_OK) {
      return $this->errorResponse('Chunk upload failed.', Response::HTTP_BAD_REQUEST);
    }

    $uid = (int) $this->currentUser->id();
    $tempDirectoryUri = sprintf('temporary://ai-intake-chunks/%d/%s', $uid, $setId);
    $this->fileSystem->prepareDirectory($tempDirectoryUri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $tempDirectoryReal = $this->fileSystem->realpath($tempDirectoryUri);
    if ($tempDirectoryReal === FALSE || $tempDirectoryReal === '') {
      return $this->errorResponse('Could not prepare temporary upload directory.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $chunkPath = $tempDirectoryReal . '/' . $uploadId . '.part';
    $statePath = $tempDirectoryReal . '/' . $uploadId . '.json';
    $safeFilename = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $originalFilename) ?: 'upload.bin';

    $expectedChunk = 0;
    $expectedOffset = 0;
    $completed = FALSE;
    $completedMediaId = 0;
    $completedFileId = 0;
    if (is_file($statePath)) {
      $state = json_decode((string) file_get_contents($statePath), TRUE);
      $expectedChunk = (int) ($state['next_chunk_index'] ?? 0);
      $expectedOffset = (int) ($state['next_offset'] ?? 0);
      $completed = (bool) ($state['completed'] ?? FALSE);
      $completedMediaId = (int) ($state['media_id'] ?? 0);
      $completedFileId = (int) ($state['file_id'] ?? 0);
    }

    // Idempotency: if this upload was already finalized, acknowledge replayed
    // chunk requests as successful completion.
    if ($completed) {
      return new JsonResponse([
        'ok' => TRUE,
        'status' => 'file_staged',
        'set_id' => $setId,
        'media_id' => $completedMediaId,
        'file_id' => $completedFileId,
        'file_name' => $safeFilename,
        'duplicate' => TRUE,
        'bytes_accepted' => 0,
      ]);
    }

    // Primary ordering contract is by byte offset, so retries/replays are
    // idempotent even when request/response delivery is unstable.
    if ($chunkStart < $expectedOffset) {
      return new JsonResponse([
        'ok' => TRUE,
        'status' => 'chunk_received',
        'duplicate' => TRUE,
        'bytes_accepted' => 0,
      ]);
    }
    if ($chunkStart > $expectedOffset) {
      return $this->errorResponse(sprintf('Out-of-order chunk by offset. Expected %d, got %d.', $expectedOffset, $chunkStart), Response::HTTP_CONFLICT);
    }

    // Keep index checks as a secondary safety net.
    if ($chunkIndex > $expectedChunk) {
      return $this->errorResponse(sprintf('Out-of-order chunk index. Expected %d, got %d.', $expectedChunk, $chunkIndex), Response::HTTP_CONFLICT);
    }

    $mode = ($chunkIndex === 0) ? 'wb' : 'ab';
    $destination = fopen($chunkPath, $mode);
    if ($destination === FALSE) {
      return $this->errorResponse('Failed to open chunk destination.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }
    $chunkData = (string) file_get_contents((string) $uploadedChunk->getRealPath());
    fwrite($destination, $chunkData);
    fclose($destination);

    $bytesAccepted = filesize($chunkPath);
    if ($bytesAccepted === FALSE) {
      $bytesAccepted = 0;
    }
    $bytesAccepted = max(0, $bytesAccepted - $expectedOffset);

    $nextOffset = $expectedOffset + $bytesAccepted;
    file_put_contents($statePath, json_encode([
      'next_chunk_index' => $chunkIndex + 1,
      'next_offset' => $nextOffset,
      'chunk_count' => $chunkCount,
      'file_name' => $safeFilename,
      'file_size' => $fileSize,
    ], JSON_THROW_ON_ERROR));

    if ($chunkIndex !== ($chunkCount - 1)) {
      return new JsonResponse([
        'ok' => TRUE,
        'status' => 'chunk_received',
        'bytes_accepted' => $bytesAccepted,
      ]);
    }

    // Final chunk received but file is not fully assembled yet.
    if ($nextOffset < $fileSize) {
      return new JsonResponse([
        'ok' => TRUE,
        'status' => 'chunk_received',
        'bytes_accepted' => $bytesAccepted,
      ]);
    }

    $setDirectoryUri = 'public://ai-intake/' . $setId;
    $this->fileSystem->prepareDirectory($setDirectoryUri, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS);
    $setDirectoryReal = $this->fileSystem->realpath($setDirectoryUri);
    if ($setDirectoryReal === FALSE || $setDirectoryReal === '') {
      return $this->errorResponse('Could not prepare set directory.', Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    $safeDestinationUri = $setDirectoryUri . '/' . $safeFilename;
    $safeDestinationUri = $this->fileSystem->createFilename($safeFilename, $setDirectoryUri);
    $safeDestinationReal = $setDirectoryReal . '/' . basename($safeDestinationUri);
    if (!@rename($chunkPath, $safeDestinationReal)) {
      if (!@copy($chunkPath, $safeDestinationReal)) {
        return $this->errorResponse('Failed to finalize uploaded file.', Response::HTTP_INTERNAL_SERVER_ERROR);
      }
      @unlink($chunkPath);
    }
    @unlink($statePath);

    $file = \Drupal\file\Entity\File::create([
      'uri' => $safeDestinationUri,
      'status' => 1,
    ]);
    $file->setPermanent();
    $file->save();

    $filename = (string) $file->getFilename();
    $media = $this->entityTypeManager->getStorage('media')->create([
      'bundle' => self::INTAKE_MEDIA_BUNDLE,
      'name' => $filename,
      'uid' => $uid,
      'status' => 1,
      'field_media_image' => [
        'target_id' => (int) $file->id(),
        'alt' => $filename,
        'title' => $filename,
      ],
    ]);
    $media->save();

    file_put_contents($statePath, json_encode([
      'next_chunk_index' => $chunkCount,
      'next_offset' => $fileSize,
      'chunk_count' => $chunkCount,
      'file_name' => $safeFilename,
      'file_size' => $fileSize,
      'completed' => TRUE,
      'media_id' => (int) $media->id(),
      'file_id' => (int) $file->id(),
    ], JSON_THROW_ON_ERROR));

    return new JsonResponse([
      'ok' => TRUE,
      'status' => 'file_staged',
      'set_id' => $setId,
      'media_id' => (int) $media->id(),
      'file_id' => (int) $file->id(),
      'file_name' => $filename,
      'bytes_accepted' => $bytesAccepted,
    ]);
  }

  private function imageBundleExists(): bool {
    return $this->entityTypeManager->getStorage('media_type')->load(self::INTAKE_MEDIA_BUNDLE) !== NULL;
  }

  private function errorResponse(string $message, int $status): JsonResponse {
    return new JsonResponse([
      'ok' => FALSE,
      'error' => $message,
    ], $status);
  }

}
