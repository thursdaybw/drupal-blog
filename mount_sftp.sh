#!/bin/bash
set -x
set -e

SSH_KEY="/home/http/.ssh/id_rsa_sftp"
SSHFS_MOUNT_POINT="/var/tmp/receipts"
BIND_MOUNT_POINT="/var/www/html/sites/default/files/receipts"

echo "=== Ensuring /home/http/.ssh/ exists ==="
mkdir -p /home/http/.ssh
chown www-data:www-data /home/http/.ssh
chmod 700 /home/http/.ssh

echo "=== Checking SSH key and known_hosts ==="
ls -la /home/http/.ssh/
cat /home/http/.ssh/known_hosts || echo "known_hosts is missing!"

if [ ! -f "$SSH_KEY" ]; then
  echo "ERROR: SSH key $SSH_KEY is missing. Exiting!"
  exit 1
fi

echo "=== Attempting SSHFS mount ==="
sshfs -o IdentityFile="$SSH_KEY",StrictHostKeyChecking=no,UserKnownHostsFile=/dev/null,allow_other,uid=33,gid=33,reconnect,sshfs_sync \
      20187@hk-s020.rsync.net:/data1/home/20187/receipts "$SSHFS_MOUNT_POINT"

echo "=== SSHFS and Mount Status ==="
mount | grep sshfs || echo "SSHFS mount not found"

exec apache2-foreground

