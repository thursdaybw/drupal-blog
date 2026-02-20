# Compute Orchestrator Architecture

The module orchestrates Vast.ai infrastructure through a REST workflow that mirrors the legacy CLI provisioning path while maintaining Drupal-internal state.

1. **Entry point** – `compute:test-vast` Drush command (`src/Command/VastTestCommand.php`). Each run:
   - resolves a strictness policy (`strict`, `balanced`, `aggressive`) from `state.compute_orchestrator.strictness` and builds Vast filters accordingly.
   - injects the policy into the REST client to bias offer selection toward high-reliability hosts and (optionally) previously successful hosts.
   - launches vLLM via the `createOptions` payload (`onstart_cmd`, `onstart`, `args_str`) so the container starts the TinyLlama (or later VL) image on port 8000.

2. **REST client** – `VastRestClient` (`src/Service/VastRestClient.php` plus the interface). Responsibilities include:
   - calling Vast bundles/offers API, creating/starting instances, and tracking state through `__construct(ClientInterface $http_client, BadHostRegistry $badHosts, WorkloadReadinessAdapterManager $adapterManager, SshProbeExecutor $probeExecutor)`.
   - wait loop: fetch instance info, probe SSH reachability, and delegate workload readiness verification to the plugin-defined adapter so warm-up and HTTP binding are validated via reusable SSH commands. The loop respects both the core timeout and the adapter-specific startup window.
   - host reputation + exclusions: maintains host statistics (`host_stats`) and a global infra-fatal blacklist (`global_bad_hosts`) in Drupal `state`; merges them with the persistent blacklist before filtering offers; sorts offers by success history and price when the strictness policy requests it.
   - failure handling: Workload readiness failures are classified via the plugin (infra fatal, workload fatal, warm-up); only infra fatal errors add to the global blacklist/host stats, while warm-up delays generate diagnostics without blacklisting.

3. **Support services and plugin infrastructure** –
   - `BadHostRegistry` + `BadHostsCommand`: same whitelist/clear helpers backed by `compute_orchestrator.bad_hosts`.
   - Plugin infrastructure:
     * `WorkloadReadinessAdapterManager` (`src/Plugin/WorkloadReadinessAdapterManager.php`) discovers readiness plugins wired by annotation.
     * `VllmReadinessAdapter` plugin (`src/Plugin/WorkloadReadinessAdapter/VllmReadinessAdapter.php`) drives probe commands (`curl`, `ps`, `nvidia-smi`, logs) and classifies failures (`FailureClass` values) before the client reacts.
     * `SshProbeExecutor` (`src/Service/SshProbeExecutor.php`) standardizes remote command execution with timeout/control so every adapter can reuse SSH capability.
     * `WorkloadReadinessException` indicates fatal boot failures from adapter classification and contains the `FailureClass` to inform blacklist logic.

4. **Persistence and configuration**
   - Environment variables: `VAST_API_KEY` (required), `VAST_SSH_KEY_PATH` (recommended for SSH probes); defaults to `~/.ssh/id_rsa_vastai`.
   - Strictness policy: stored in `compute_orchestrator.strictness`, controls reliability thresholds, port filters, and whether to prioritize hosts with recorded success.
   - Diagnostics/logging: readiness failures include SSH probe outputs (`ss`, `ps`, `nvidia-smi`, `/tmp/vllm.log`) along with timestamped messages to track warm-up delays versus fatal errors.

5. **Flow summary** – filters → offer ranking → create instance → wait for SSH + workload adapter readiness → destroy on success / record success or infra failure (blacklist + stats).
