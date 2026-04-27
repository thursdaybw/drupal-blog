#!/bin/bash

echo "=== Preparing SSH directory ==="
mkdir -p /home/http/.ssh
chmod 700 /home/http/.ssh
chown www-data:www-data /home/http/.ssh

echo "=== Populating known_hosts ==="
ssh-keyscan -H github.com >> /home/http/.ssh/known_hosts
chmod 644 /home/http/.ssh/known_hosts
chown www-data:www-data /home/http/.ssh/known_hosts

# === Prepare Vast.ai SSH key from mounted secret source ===
VAST_KEY_SOURCE="/run/secrets/id_rsa_vastai"
VAST_KEY_RUNTIME="/home/http/.ssh/id_rsa_vastai"
if [ -f "$VAST_KEY_SOURCE" ]; then
  echo "🔧 Installing Vast.ai key from mounted secret source"
  cp "$VAST_KEY_SOURCE" "$VAST_KEY_RUNTIME"
  chmod 600 "$VAST_KEY_RUNTIME"
  chown www-data:www-data "$VAST_KEY_RUNTIME"
fi

if [ "$#" -eq 0 ]; then
  set -- apache2-foreground
fi

exec "$@"
