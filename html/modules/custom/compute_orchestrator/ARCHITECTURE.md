# Compute Orchestrator Architecture

Future model target: Qwen/Qwen2-VL-7B-Instruct
i
Coding models: DeepSeek-Coder or Qwen2.5-Coder

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

## 8. Future Hardening (Post-MVP Stabilisation)

The first successful provisioning + readiness cycle confirms the core orchestration pipeline is operational. Further resilience work is intentionally deferred to avoid destabilising a working baseline.

The following hardening tracks are documented for later architectural expansion:

### 8.1 Socket-level readiness probes

Current readiness relies on HTTP probes (`/v1/models`) executed via SSH.

Future improvement:

* Add port bind detection (e.g. `ss -lntp` or `netstat`) to confirm the API server has bound before HTTP success.
* Prevents false negatives where HTTP fails while the server is still initialising.

Rationale: distinguishes network bind from application readiness.

---

### 8.2 Partial API boot detection

vLLM may expose its port before the model finishes loading.

Future adapter enhancement:

* Validate `/v1/models` response content, not just HTTP success.
* Confirm expected model presence.

Rationale: avoids early “ready” classification during incomplete engine warm-up.

---

### 8.3 Forward-progress signal tightening

Current logic treats these as progress:

* Log growth
* Process diffs
* GPU output changes

Future refinement:

* Require semantic progress markers, e.g.

  * model loading stages
  * CUDA graph capture milestones
  * KV cache allocation

Rationale: prevents infinite warm-up loops on hosts that are technically alive but stalled.

---

### 8.4 Stall classification escalation

Introduce multi-signal stall detection:

Example criteria:

* No log growth
* No GPU memory delta
* No process state change

Over sustained interval → classify as fatal.

Rationale: differentiate slow warm-up from dead engine initialisation.

---

### 8.5 Host capability learning

Extend host reputation model beyond infra fatal errors.

Future signals:

* Repeated workload fatal failures on same GPU class
* CUDA incompatibility patterns
* Driver/runtime mismatches

Action:

* Down-rank or blacklist incompatible GPU families automatically.

Rationale: reduces repeated failed provisioning attempts on unsuitable hardware.

---

### 8.6 Warm-up budget isolation

Current readiness loop uses shared timeout constructs.

Future split:

* Infra readiness window (SSH + container boot)
* Workload warm-up window (engine initialisation)

Rationale: GPU model compilation and CUDA graph capture can exceed traditional service startup budgets.

---

### Status

All above items are architectural backlog.

They are documented but intentionally not implemented following the first successful provisioning run to preserve system stability.

