<?php
$uuid = '007e6a4f-badd-4db9-b89c-8244c4202fa6';
$ids = \Drupal::entityTypeManager()->getStorage('bb_ai_listing')->getQuery()->accessCheck(FALSE)->condition('uuid', $uuid)->execute();
$id = $ids ? (int) reset($ids) : 0;
if (!$id) {
  fwrite(STDERR, "missing\n");
  exit(2);
}
$listing = \Drupal::entityTypeManager()->getStorage('bb_ai_listing')->load($id);
$graph = \Drupal::service('bb_ai_listing_sync.export_graph_builder')->buildForListing($listing);
$ref = new ReflectionClass(\Drupal::service('bb_ai_listing_sync.graph_fingerprint'));
$m = $ref->getMethod('normalizeGraph');
$m->setAccessible(true);
echo json_encode($m->invoke(\Drupal::service('bb_ai_listing_sync.graph_fingerprint'), $graph), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
