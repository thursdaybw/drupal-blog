#!/bin/bash

echo "=== Preparing SSH directory ==="
mkdir -p /home/http/.ssh
chmod 700 /home/http/.ssh
chown www-data:www-data /home/http/.ssh

echo "=== Populating known_hosts ==="
ssh-keyscan -H github.com >> /home/http/.ssh/known_hosts
chmod 644 /home/http/.ssh/known_hosts
chown www-data:www-data /home/http/.ssh/known_hosts

# === Fix permissions for vastai key if present ===
VAST_KEY="/home/http/.ssh/id_rsa_vastai"
if [ -f "$VAST_KEY" ]; then
  echo "🔧 Fixing permissions on Vast.ai key"
  chmod 600 "$VAST_KEY"
  chown www-data:www-data "$VAST_KEY"
fi

#exec runuser -u www-data -- apache2-foreground
exec apache2-foreground
