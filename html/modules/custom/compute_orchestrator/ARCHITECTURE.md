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
   - `BadHostRegistry` + `BadHostsCommand`: whitelist/clear helpers backed by `compute_orchestrator.bad_hosts`.
   - Plugin infrastructure:
     * `WorkloadReadinessAdapterManager` (`src/Plugin/WorkloadReadinessAdapterManager.php`) discovers readiness plugins wired by annotation.
     * `VllmReadinessAdapter` plugin drives probe commands (`curl`, `ps`, `nvidia-smi`, logs, plus the new `executor_echo`) and classifies failures (`FailureClass` values). Its warm-up logic reports progress only after the adapter confirms each SSH command succeeded.
     * `SshProbeExecutor` (`src/Service/SshProbeExecutor.php`) now accepts an `SshConnectionContext`/`SshProbeRequest` pair, logs the exact SSH invocation at debug level, keeps transport vs command errors separate, and wraps each probe in `set -euo pipefail` so quoting remains deterministic.
     * `WorkloadReadinessException` indicates fatal boot failures from adapter classification and contains the `FailureClass` to inform blacklist logic.

4. **Persistence and configuration**
   - Environment variables: `VAST_API_KEY` (required), `VAST_SSH_KEY_PATH` (recommended for SSH probes); defaults to `~/.ssh/id_rsa_vastai`.
   - Strictness policy: stored in `compute_orchestrator.strictness`, controls reliability thresholds, port filters, and whether to prioritize hosts with recorded success.
   - Diagnostics/logging: readiness failures include SSH probe outputs (`curl`, `ps`, `nvidia-smi`, `/tmp/vllm.log`) along with timestamped messages to track warm-up delays versus fatal errors.

5. **Flow summary** – filters → offer ranking → create instance → wait for SSH + workload adapter readiness → destroy on success / record success or infra failure (blacklist + stats).

6. **Probe execution reliability**
   - Each readiness loop builds an `SshConnectionContext` and issues `SshProbeRequest`s so the newly refactored executor can reuse connection metadata across probes while keeping command/timeout data explicit.
   - Debug logs now show the full SSH invocation (with the key path redacted) and payload quoting, eliminating ambiguity about what command actually ran.
   - Probe responses distinguish transport failures (timeouts/exceptions) from command failures, include the exit code, and surface exceptions so the failure summary no longer disguises SSH or curl issues.
   - The `executor_echo` probe (`printf '__PROBE_OK__'`) acts as a sanity check — whenever it fails, the adapter reports an `UNKNOWN` classification so the readiness loop keeps trying rather than assuming the workload crashed.

7. **Todo / Gotchas**
   - The existing progress detection hooks are still very eager — log/process/gpu diffs currently count as “forward progress,” so warm-up loops may continue even though nothing semantically changes. Once the executor is consistently reporting transport vs command results, revisit the adapter to require more concrete signals (e.g., HTTP port bind or `/v1/models` success) before calling that “progress.”


One more thing: your current vLLM adapter progress detection is too “easy to satisfy”

Right now it treats “gpu stdout changed” or “processes stdout changed” as progress. On some hosts these can fluctuate without real progress.

Once executor is fixed, tighten progress detection to:

log tail changed OR

transition closer to ready (port open, api routes logged, etc.)

But don’t do that yet, first make the executor truthful.

If you want, paste SshProbeExecutor.php as it exists now, and I’ll point to the exact quoting and logging edits to make, anchored to your file.
