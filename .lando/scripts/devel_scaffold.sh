#!/bin/bash

# Project root from LANDO_MOUNT
project_root="$LANDO_MOUNT"

# Preflight Checks
if [ ! -f "$project_root/composer.json" ]; then
  echo "Error: composer.json not found in project root."
  exit 1
fi

if [ ! -r "$project_root/.lando/devel_scaffold" ]; then
  echo "Error: Permission issue with .lando/devel_scaffold."
  exit 1
fi

mkdir -p "$project_root/devel"

# Main Operation
function copy_files {
  src="$1"
  dest="$2"

  for file in "$src"/*; do
    if [ -d "$file" ]; then
      mkdir -p "$dest/$(basename "$file")"
      copy_files "$file" "$dest/$(basename "$file")"
    else
      if [ -e "$dest/$(basename "$file")" ]; then
        read -p "$dest/$(basename "$file") already exists. Skip, Overwrite, or Cancel? (s/o/c): " choice
        case "$choice" in
          s) continue ;;
          o) cp "$file" "$dest" ;;
          c) exit 1 ;;
        esac
      else
        cp "$file" "$dest"
      fi
      echo "Copied: $file to $dest"
    fi
  done
}

copy_files "$project_root/.lando/devel_scaffold" "$project_root"

# Post-Operation
cd "$project_root" && composer install
echo "Ran composer install"
