# Compute Orchestrator Architecture

The module orchestrates Vast.ai infrastructure through a REST workflow that mirrors the legacy CLI provisioning path while maintaining Drupal-internal state.

1. **Entry point** – `compute:test-vast` Drush command (`src/Command/VastTestCommand.php`). Each run:
   - resolves a strictness policy (`strict`, `balanced`, `aggressive`) from `state.compute_orchestrator.strictness` and builds Vast filters accordingly.
   - injects the policy into the REST client to bias offer selection toward high-reliability hosts and (optionally) previously successful hosts.
   - launches vLLM via the `createOptions` payload (`onstart_cmd`, `onstart`, `args_str`) so the container starts the TinyLlama (or later VL) image on port 8000.

2. **REST client** – `VastRestClient` (`src/Service/VastRestClient.php` plus the interface). Responsibilities include:
   - calling Vast bundles/offers API, creating/starting instances, and tracking state through `__construct(ClientInterface $http_client, BadHostRegistry $badHosts)`.
   - wait loop: fetch instance info, probe SSH readiness (`sshLoginCheck`), probe the vLLM HTTP endpoint (`vllmReadyCheckViaSsh`), log configured timestamps, and surface diagnostics when probes fail.
   - host reputation + exclusions: stores host statistics (`host_stats`) and a global infra-fatal blacklist (`global_bad_hosts`) via Drupal `state`; merges them with the persistent bad-host registry before filtering offers; sorts offers by success count and price when `prefer_success_hosts` is enabled.
   - failure handling: fatal infra failures (CUDA / OCI / GPU errors) trigger host stats update, global blacklist addition, and re-use of the blacklist list to avoid retries; only infra fatal messages are added to visible bad-host registry.

3. **Support services** –
   - `BadHostRegistry` (`src/Service/BadHostRegistry.php`): maintains `compute_orchestrator.bad_hosts` state and supports the `compute:bad-hosts` Drush command for listing/clearing entries.
   - `BadHostsCommand` (`src/Command/BadHostsCommand.php`): Drush command to inspect or clear the manual blacklist.

4. **Persistence and configuration**
   - Environment variables: `VAST_API_KEY` (required), `VAST_SSH_KEY_PATH` (recommended for SSH probes). The module uses filesystem key defaults (`~/.ssh/id_rsa_vastai`).
   - Strictness policy: stored in Drupal state and controls reliability thresholds, port filters, and whether to favor hosts with historical success.
   - Diagnostics: when vLLM probes fail, the module logs remote `ss`, `ps`, and `/tmp/vllm.log` snippets to assist debugging.

5. **Flow summary** – filters → offer ranking → create instance → wait for SSH/vLLM → destroy on success/record stats on failure.
