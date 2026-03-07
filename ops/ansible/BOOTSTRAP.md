# Ansible Host Bootstrap

This file tracks one-time manual steps that must be done on the VPS before
Ansible can manage system-level configuration safely.

## Bootstrap sudo for bevan

Why:
- Ansible tasks for nginx, TLS, firewall, and service management need sudo.
- We want non-interactive runs, so `sudo -n` must work for `bevan`.

### 1) Create sudoers entry (on VPS)

Run as `root` (or via a root shell), then use `visudo`:

```bash
visudo -f /etc/sudoers.d/bevan-ansible
```

Add:

```sudoers
bevan ALL=(ALL) NOPASSWD: ALL
```

Set secure permissions:

```bash
chmod 0440 /etc/sudoers.d/bevan-ansible
```

### 2) Verify from laptop

```bash
ansible -i ops/ansible/inventory.ini bb-drupal-prod -m ansible.builtin.command -a "sudo -n true"
```

Expected:
- command succeeds with return code 0.

