Place the production Vast SSH private key in this directory as:

- `id_rsa_vastai`

This file is intentionally gitignored. Deployment copies it to:

- `/home/bevan/workspace/bb-platform-prod/secrets/id_rsa_vastai`

and mounts it read-only into the app container at:

- `/home/http/.ssh/id_rsa_vastai`
