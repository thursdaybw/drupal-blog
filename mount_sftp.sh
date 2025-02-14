#!/bin/bash
set -e

# Ensure the mount point exists
mkdir -p /var/www/html/sites/default/files/receipts

# Check if it's already mounted
if ! mountpoint -q /var/www/html/sites/default/files/receipts; then
    sshfs -o IdentityFile=/root/.ssh/id_rsa_sftp,StrictHostKeyChecking=no,UserKnownHostsFile=/dev/null,allow_other \
          20187@hk-s020.rsync.net:/data1/home/20187/receipts \
          /var/www/html/sites/default/files/receipts
fi

# Start Apache
exec apache2-foreground
