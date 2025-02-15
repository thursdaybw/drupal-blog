#!/bin/bash
set -e

SSH_KEY="/home/http/.ssh/id_rsa_sftp"
SSHFS_MOUNT_POINT="/var/tmp/receipts"
BIND_MOUNT_POINT="/var/www/html/sites/default/files/receipts"

## Ensure the SSHFS mount point exists
mkdir -p "$SSHFS_MOUNT_POINT"
chown www-data:www-data "$SSHFS_MOUNT_POINT"

# Ensure the bind mount point exists
#mkdir -p "$BIND_MOUNT_POINT"

#if ! mountpoint -q "$SSHFS_MOUNT_POINT"; then
#    echo "Mounting SSHFS at $SSHFS_MOUNT_POINT..."
#    sshfs -o IdentityFile="$SSH_KEY",StrictHostKeyChecking=no,UserKnownHostsFile=/dev/null,allow_other,uid=33,gid=33,reconnect,sshfs_sync \
#          20187@hk-s020.rsync.net:/data1/home/20187/receipts "$SSHFS_MOUNT_POINT"
#fi

#if ! mountpoint -q "$BIND_MOUNT_POINT"; then
#    echo "Binding $SSHFS_MOUNT_POINT to $BIND_MOUNT_POINT..."
#    mount --bind "$SSHFS_MOUNT_POINT" "$BIND_MOUNT_POINT"
#fi

exec apache2-foreground

