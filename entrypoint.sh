#!/bin/bash

echo "=== Checking if SFTP_KEY_BASE64 is set ==="
if [ -z "$SFTP_KEY_BASE64" ]; then
    echo "SFTP_KEY_BASE64 is not set!"
    exit 1
else
    echo "SFTP_KEY_BASE64 is set."
fi

echo "=== Decoding and writing SSH key ==="
mkdir -p /home/http/.ssh
echo "$SFTP_KEY_BASE64" | base64 -d > /home/http/.ssh/id_rsa_sftp
chmod 600 /home/http/.ssh/id_rsa_sftp
chown www-data:www-data /home/http/.ssh/id_rsa_sftp

ls -l /home/http/.ssh/id_rsa_sftp  # Confirm it's there


echo "=== Populating known_hosts ==="
ssh-keyscan -H github.com >> /home/http/.ssh/known_hosts
ssh-keyscan -H hk-s020.rsync.net >> /home/http/.ssh/known_hosts
chmod 644 /home/http/.ssh/known_hosts
chown www-data:www-data /home/http/.ssh/known_hosts

set -x

# Define required variables
SSH_KEY="/home/http/.ssh/id_rsa_sftp"
SSHFS_MOUNT_POINT="/var/tmp/sftp_mount"
BIND_MOUNT_POINT="/var/www/html/sites/default/files/receipts"

echo "=== Checking if SSHFS is already mounted ==="
if ! mountpoint -q "$SSHFS_MOUNT_POINT"; then
    echo "=== Creating SSHFS mount point ==="
    mkdir -p "$SSHFS_MOUNT_POINT"
    chown www-data:www-data "$SSHFS_MOUNT_POINT"

    echo "=== Ensuring bind mount point exists ==="
    mkdir -p "$BIND_MOUNT_POINT"
    chown www-data:www-data "$BIND_MOUNT_POINT"

    echo "=== Mounting SSHFS ==="
    #sshfs -o IdentityFile="$SSH_KEY",StrictHostKeyChecking=no,UserKnownHostsFile=/dev/null,allow_other,uid=33,gid=33,reconnect,sshfs_sync \
    sshfs -o IdentityFile="$SSH_KEY",allow_other,uid=33,gid=33,reconnect,sshfs_sync \
          20187@hk-s020.rsync.net:/data1/home/20187/receipts "$SSHFS_MOUNT_POINT"
else
    echo "SSHFS already mounted."
fi

echo "=== Checking if bind mount is already in place ==="
if ! mountpoint -q "$BIND_MOUNT_POINT"; then
    echo "=== Binding SSHFS mount to web directory ==="
    mount --bind "$SSHFS_MOUNT_POINT" "$BIND_MOUNT_POINT"
else
    echo "Bind mount already exists."
fi

ssh-keyscan -H hk-s020.rsync.net >> /home/http/.ssh/known_hosts

#exec runuser -u www-data -- apache2-foreground
exec apache2-foreground


