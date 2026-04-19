<?php

declare(strict_types=1);

$etm = \Drupal::entityTypeManager();
$fs = \Drupal::service('file_system');
$out = [
  'files' => 0,
  'media' => 0,
  'listing_images' => 0,
  'listings' => 0,
];

$fileStorage = $etm->getStorage('file');
$fids = $fileStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('uri', 'public://ai-intake/%', 'LIKE')
  ->execute();
$fids = array_values(array_map('intval', $fids));
$out['files'] = count($fids);

$mediaStorage = $etm->getStorage('media');
$mids = $mediaStorage->getQuery()
  ->accessCheck(FALSE)
  ->condition('bundle', 'ai_listing_intake')
  ->execute();
$mids = array_values(array_map('intval', $mids));
$out['media'] = count($mids);

$listingImageIds = [];
$listingIds = [];
if ($etm->hasDefinition('listing_image') && $fids !== []) {
  $liStorage = $etm->getStorage('listing_image');
  $listingImageIds = $liStorage->getQuery()
    ->accessCheck(FALSE)
    ->condition('file', $fids, 'IN')
    ->execute();
  $listingImageIds = array_values(array_map('intval', $listingImageIds));
  $out['listing_images'] = count($listingImageIds);
  if ($listingImageIds !== []) {
    $lis = $liStorage->loadMultiple($listingImageIds);
    foreach ($lis as $li) {
      $ownerType = (string) ($li->get('owner')->target_type ?? '');
      $ownerId = (int) ($li->get('owner')->target_id ?? 0);
      if ($ownerType === 'bb_ai_listing' && $ownerId > 0) {
        $listingIds[$ownerId] = $ownerId;
      }
    }
  }
}
$out['listings'] = count($listingIds);

if ($etm->hasDefinition('bb_ai_listing') && $listingIds !== []) {
  $listingStorage = $etm->getStorage('bb_ai_listing');
  $entities = $listingStorage->loadMultiple(array_values($listingIds));
  if ($entities !== []) {
    $listingStorage->delete($entities);
  }
}

if ($mids !== []) {
  $medias = $mediaStorage->loadMultiple($mids);
  if ($medias !== []) {
    $mediaStorage->delete($medias);
  }
}

if ($etm->hasDefinition('listing_image') && $listingImageIds !== []) {
  $liStorage = $etm->getStorage('listing_image');
  $entities = $liStorage->loadMultiple($listingImageIds);
  if ($entities !== []) {
    $liStorage->delete($entities);
  }
}

if ($fids !== []) {
  $files = $fileStorage->loadMultiple($fids);
  if ($files !== []) {
    $fileStorage->delete($files);
  }
}

$aiIntakeReal = $fs->realpath('public://ai-intake');
if ($aiIntakeReal !== FALSE && is_dir($aiIntakeReal)) {
  $fs->deleteRecursive('public://ai-intake');
}
$chunksReal = $fs->realpath('temporary://ai-intake-chunks');
if ($chunksReal !== FALSE && is_dir($chunksReal)) {
  $fs->deleteRecursive('temporary://ai-intake-chunks');
}

print json_encode(['deleted' => $out], JSON_PRETTY_PRINT) . PHP_EOL;
