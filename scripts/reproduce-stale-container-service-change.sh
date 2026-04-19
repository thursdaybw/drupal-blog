#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MODULE_DIR="$ROOT/html/modules/custom/codex_container_repro"
MODULE_NAME="codex_container_repro"
HELPER_PATH="/var/www/html/scripts/force-drupal-container-rebuild.php"

cleanup() {
  set +e
  write_base_version >/dev/null 2>&1
  ddev exec php "$HELPER_PATH" >/dev/null 2>&1
  ddev exec bash -lc "cd /var/www/html && drush pm:uninstall -y ${MODULE_NAME}" >/dev/null 2>&1
  rm -rf "$MODULE_DIR"
  ddev exec php "$HELPER_PATH" >/dev/null 2>&1
}

if [[ -e "$MODULE_DIR" ]]; then
  echo "Refusing to run: $MODULE_DIR already exists." >&2
  exit 1
fi

trap cleanup EXIT

write_common_files() {
  mkdir -p "$MODULE_DIR/src/Command" "$MODULE_DIR/src/Service"

  cat > "$MODULE_DIR/${MODULE_NAME}.info.yml" <<'YML'
name: Codex Container Repro
type: module
description: Temporary repro module for stale compiled container behavior.
package: Development
core_version_requirement: ^10
YML

  cat > "$MODULE_DIR/drush.services.yml" <<'YML'
services:
  codex_container_repro.commands:
    class: Drupal\codex_container_repro\Command\ContainerReproCommand
    arguments: ['@codex_container_repro.target']
    tags:
      - { name: drush.command }
YML

  cat > "$MODULE_DIR/src/Command/ContainerReproCommand.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\codex_container_repro\Command;

use Drupal\codex_container_repro\Service\TargetService;
use Drush\Commands\DrushCommands;

final class ContainerReproCommand extends DrushCommands {

  public function __construct(
    private readonly TargetService $targetService,
  ) {
    parent::__construct();
  }

  /**
   * @command codex-container-repro:ping
   */
  public function ping(): string {
    return $this->targetService->describe();
  }

}
PHP

  cat > "$MODULE_DIR/src/Service/FirstDependency.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\codex_container_repro\Service;

final class FirstDependency {}
PHP

  cat > "$MODULE_DIR/src/Service/SecondDependency.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\codex_container_repro\Service;

final class SecondDependency {}
PHP

  cat > "$MODULE_DIR/src/Service/NewDependency.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\codex_container_repro\Service;

final class NewDependency {}
PHP
}

write_base_version() {
  write_common_files

  cat > "$MODULE_DIR/${MODULE_NAME}.services.yml" <<'YML'
services:
  codex_container_repro.first:
    class: Drupal\codex_container_repro\Service\FirstDependency
  codex_container_repro.second:
    class: Drupal\codex_container_repro\Service\SecondDependency
  codex_container_repro.target:
    class: Drupal\codex_container_repro\Service\TargetService
    arguments:
      - '@codex_container_repro.first'
      - '@codex_container_repro.second'
      - '@logger.factory'
YML

  cat > "$MODULE_DIR/src/Service/TargetService.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\codex_container_repro\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

final class TargetService {

  public function __construct(
    private readonly FirstDependency $first,
    private readonly SecondDependency $second,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function describe(): string {
    return 'base';
  }

}
PHP
}

write_append_version() {
  write_common_files

  cat > "$MODULE_DIR/${MODULE_NAME}.services.yml" <<'YML'
services:
  codex_container_repro.first:
    class: Drupal\codex_container_repro\Service\FirstDependency
  codex_container_repro.second:
    class: Drupal\codex_container_repro\Service\SecondDependency
  codex_container_repro.new_dependency:
    class: Drupal\codex_container_repro\Service\NewDependency
  codex_container_repro.target:
    class: Drupal\codex_container_repro\Service\TargetService
    arguments:
      - '@codex_container_repro.first'
      - '@codex_container_repro.second'
      - '@logger.factory'
      - '@codex_container_repro.new_dependency'
YML

  cat > "$MODULE_DIR/src/Service/TargetService.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\codex_container_repro\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

final class TargetService {

  public function __construct(
    private readonly FirstDependency $first,
    private readonly SecondDependency $second,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
    private readonly NewDependency $newDependency,
  ) {}

  public function describe(): string {
    return 'append';
  }

}
PHP
}

write_insert_before_version() {
  write_common_files

  cat > "$MODULE_DIR/${MODULE_NAME}.services.yml" <<'YML'
services:
  codex_container_repro.first:
    class: Drupal\codex_container_repro\Service\FirstDependency
  codex_container_repro.second:
    class: Drupal\codex_container_repro\Service\SecondDependency
  codex_container_repro.new_dependency:
    class: Drupal\codex_container_repro\Service\NewDependency
  codex_container_repro.target:
    class: Drupal\codex_container_repro\Service\TargetService
    arguments:
      - '@codex_container_repro.first'
      - '@codex_container_repro.second'
      - '@codex_container_repro.new_dependency'
      - '@logger.factory'
YML

  cat > "$MODULE_DIR/src/Service/TargetService.php" <<'PHP'
<?php

declare(strict_types=1);

namespace Drupal\codex_container_repro\Service;

use Drupal\Core\Logger\LoggerChannelFactoryInterface;

final class TargetService {

  public function __construct(
    private readonly FirstDependency $first,
    private readonly SecondDependency $second,
    private readonly NewDependency $newDependency,
    private readonly LoggerChannelFactoryInterface $loggerFactory,
  ) {}

  public function describe(): string {
    return 'insert-before';
  }

}
PHP
}

run_drush_list() {
  local output status
  set +e
  output="$(ddev exec bash -lc 'cd /var/www/html && drush list' 2>&1)"
  status=$?
  set -e
  DRUSH_LIST_OUTPUT="$output"
  return "$status"
}

assert_drush_list_passes() {
  local label="$1"
  if ! run_drush_list; then
    echo "[$label] Expected drush list to pass, but it failed:" >&2
    echo "$DRUSH_LIST_OUTPUT" >&2
    exit 1
  fi
}

assert_drush_list_fails_with() {
  local label="$1"
  local pattern="$2"
  if run_drush_list; then
    echo "[$label] Expected drush list to fail, but it passed." >&2
    exit 1
  fi
  if [[ "$DRUSH_LIST_OUTPUT" != *"$pattern"* ]]; then
    echo "[$label] drush list failed, but did not match expected pattern: $pattern" >&2
    echo "$DRUSH_LIST_OUTPUT" >&2
    exit 1
  fi
}

printf 'Preparing base module...\n'
write_base_version

ddev exec bash -lc "cd /var/www/html && drush en -y ${MODULE_NAME}" >/dev/null

ddev exec php "$HELPER_PATH" >/dev/null
assert_drush_list_passes 'control/base'
printf 'PASS control/base: current base version bootstraps with rebuilt container.\n'

printf 'Testing append-at-end constructor change against stale container...\n'
write_append_version
assert_drush_list_fails_with 'append/stale' 'Too few arguments to function Drupal\codex_container_repro\Service\TargetService::__construct()'
printf 'PASS append/stale: stale container reuses old args and fails with missing trailing argument.\n'

printf 'Restoring base version so the next scenario starts from a valid cached container...\n'
write_base_version
ddev exec php "$HELPER_PATH" >/dev/null
assert_drush_list_passes 'control/reset-base'
printf 'PASS control/reset-base: base version bootstraps again after reset.\n'

printf 'Testing insert-before-existing constructor change against stale container...\n'
write_insert_before_version
assert_drush_list_fails_with 'insert-before/stale' 'Argument #3 ($newDependency) must be of type'
printf 'PASS insert-before/stale: stale container shifts logger into the new slot and fails positionally.\n'

printf '\nSummary:\n'
printf '  - control/base passed\n'
printf '  - append-at-end fails under stale container with missing trailing argument\n'
printf '  - insert-before-existing fails under stale container with shifted positional type mismatch\n'
