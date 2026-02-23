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

## Vast.ai Provisioning Contract

### Design Principle

The orchestrator does not depend on Vast templates or GUI behaviors.

Provisioning is performed using an explicit, deterministic REST payload.

No template merging.
No hidden defaults.
No GUI-derived portal wiring.

---

### REST Contract Shape

Vast REST expects:

* `image` as string
* `env` as a JSON object (dictionary)
* Docker flags expressed as keys inside `env`
* Port mappings represented as:

  ```json
  "-p 8000:8000": "1"
  ```

The value `"1"` is a flag marker and has no semantic meaning beyond presence.

---

### Port Exposure Model

To expose a service:

1. Add port mapping inside `env`:

   ```json
   "-p 8000:8000": "1"
   ```

2. Bind service inside container to:

   ```
   --host 0.0.0.0
   ```

3. Detect external mapping via `instance_info.ports` in API response.

The orchestrator does not rely on:

* `PORTAL_CONFIG`
* `OPEN_BUTTON_PORT`
* `template_hash_id`
* Jupyter proxy modes

---

### Profiles

#### vLLM Node

* Image: `vllm/vllm-openai`
* Exposes port 8000
* Uses onstart to launch OpenAI-compatible server
* Host binding: 0.0.0.0

#### Whisper Node

* Custom Docker image
* Explicit port mapping
* No template usage
* No vLLM assumptions

---

### Non-Goals

The orchestrator does not:

* Use Vast portal reverse proxy
* Use GUI templates
* Use Jupyter runtype
* Depend on implicit env merging

---

### Security Note

Direct port exposure is used for development only.

Production must use:

* SSH tunnel
  or
* Reverse proxy
  or
* Authenticated gateway

## SSH Key Injection Failure Handling

### Context

When provisioning Vast instances with `runtype=ssh`, SSH access depends on Vast automatically installing the account’s registered public key into the container at boot.

This key installation is performed by Vast’s control plane during instance initialization.

Our orchestration layer does **not** manually inject keys.

### Observed Failure Mode

Occasionally, a provisioned instance will:

* Reach `cur_state=running`
* Report `actual_status=running`
* Expose an SSH endpoint
* Reject authentication with:

```
Permission denied (publickey)
```

In this state:

* The SSH daemon is running
* Network connectivity exists
* The container is alive
* The authorized key was not installed correctly

This condition does not self-heal.

Retrying SSH on the same instance will continue to fail indefinitely.

Destroying and reprovisioning typically resolves the issue.

### Classification

This is an **infrastructure provisioning defect**, not a workload warmup state.

It must be classified as `INFRA_FATAL`.

It is distinct from:

* `Connection refused` (SSH daemon not yet listening)
* `Timeout` (network not ready)
* Workload warmup (vLLM not yet serving)

### Required Orchestrator Behavior

When SSH probe returns:

```
Permission denied (publickey)
```

The system must:

1. Immediately classify as infrastructure fatal.
2. Abort waiting loop.
3. Destroy the instance (unless `preserve_on_failure=true`).
4. Blacklist the host for this run.
5. Retry provisioning with next best offer.

The orchestrator must **not** continue polling in this state.

### Detection Rule

Within `waitForRunningAndSsh()`:

If SSH probe failure contains case-insensitive substring:

```
permission denied
```

Then throw a fatal exception.

This prevents indefinite polling loops.

### Rationale

Instances are ephemeral and provisioned on heterogeneous hosts.

SSH key injection is an external side effect performed by Vast.

The orchestration layer must assume:

* Remote initialization steps can fail independently.
* A running container does not imply a usable instance.
* SSH authentication failure after daemon availability is terminal.

### Future Hardening

Possible improvements:

* Add host-level reliability scoring for SSH key injection failures.
* Record metric: `ssh_auth_failures`.
* Escalate host to global blacklist after N auth failures.
* Distinguish between:

  * Transport failure
  * Auth failure
  * Daemon unavailable
* Reduce polling delay after fatal SSH detection.

---

# 9. Vision-Language Inference Layer (Book Processing)

## 9.1 Design Principle

Vision-language inference is treated as a stateless adapter layer.

The orchestrator provisions compute.
Controllers define use-case contracts.
The model is an implementation detail.

The system does not:

* Allow the model to define business rules
* Allow grading logic to live inside prompts
* Depend on model-specific formatting quirks

Controllers enforce strict response schemas.

---

## 9.2 Endpoint Separation

The system now exposes distinct domain endpoints:

### `/compute/book-extract`

Purpose: extract identity metadata.

Input:

* `images[]` (cover and/or title page)

Output contract:

```
{
  "title": string,
  "author": string,
  "raw": string
}
```

Failure:

* `parse_failed`
* `invalid_upstream_json`
* `upstream_http_error`

Characteristics:

* Low image count (typically 1–2)
* Faster inference time
* Strict schema validation
* Deterministic contract

---

### `/compute/book-condition`

Purpose: extract observable physical condition signals.

Input:

* `images[]` (arbitrary count)

Output contract:

```
{
  "condition_grade": string,
  "visible_issues": string[],
  "raw": string
}
```

Characteristics:

* Higher image count
* Larger prompt token usage
* Slower inference
* Soft classification domain

Important:
The model reports observable signals only.
Final grading logic may later move to PHP to preserve deterministic business rules.

---

## 9.3 JSON Extraction Strategy

Instruction-tuned models frequently wrap JSON in Markdown fences:

````
```json
{ ... }
````

```

The controller uses `extractFirstJsonObject()` to:

1. Locate first `{`
2. Locate last `}`
3. Decode substring
4. Validate expected schema

This strategy is:

- Model-agnostic
- Fence-tolerant
- Resistant to prefixed/suffixed commentary

Controllers must never trust raw string equality.
Always extract JSON object boundaries.

---

## 9.4 Token Budget Implications

Multi-image requests significantly increase prompt token usage.

Example:

- 7 images → ~9600 prompt tokens
- Latency ~60–75 seconds on 7B VLM

Design implications:

- Condition endpoint should tolerate longer SLAs
- Metadata endpoint should remain image-minimal
- Future optimization may include image downscaling or image count caps

---

## 9.5 Dev-Mode Public Inference

For early-stage development:

- vLLM is exposed publicly via `-p 8000:8000`
- Host and port are stored in Drupal state:
  - `compute.vllm_host`
  - `compute.vllm_port`
  - `compute.vllm_url`

Controllers read from `compute.vllm_url` as single source of truth.

Security Warning:
This configuration is not production-safe.

Future production mode must use:
- SSH tunnel
- Reverse proxy
- Authenticated gateway
- Or private network-only binding

---

## 9.6 Domain Boundary Rule

Controllers define domain contracts.

The model provides:
- Extraction
- Classification
- Signal detection

The model does not:
- Define grading systems
- Set pricing logic
- Enforce marketplace constraints
- Decide business rules

Business logic remains deterministic and testable in PHP.

---

## 9.7 Future Expansion

Planned endpoints:

- `/compute/book-bundle-extract`
- `/compute/book-metadata-extended`
- `/compute/book-condition-structured`

Each endpoint must:

- Define explicit schema
- Validate required keys
- Reject mismatched responses
- Remain independent of prompt experimentation

**TODO: Add Early GPU Sanity Check After SSH**

**Problem:** Some cheap GPU hosts look valid when selected, but they are not set up properly to run GPU containers. The container runtime fails when trying to attach the GPU, which causes long startup delays and wasted credits before the workload crashes.


**What is happening:** The host may have a GPU installed, but the driver is not correctly exposed to containers, or the CUDA library (`libcuda.so`) is missing. When vLLM starts, Triton tries to use the GPU and fails with errors like “cannot find -lcuda” or device injection errors.

**Why this matters:** Right now, we only discover this after waiting for the model to download and start. That wastes time and money. We want to fail fast.

**Error seen:** `Container start failed: Error response from daemon: failed to create task for container: failed to create shim task: OCI runtime create failed: could not apply required modification to OCI specification: error modifying OCI spec: failed to inject CDI devices: unresolvable CDI device`

**Simple fix:** After SSH becomes available, run two quick checks before starting vLLM:

* `nvidia-smi`
* `ldconfig -p | grep libcuda` (or check that `libcuda.so` exists)

If either check fails, immediately destroy the instance and mark the host as infrastructure fatal. This avoids full model startup attempts on broken GPU hosts.
