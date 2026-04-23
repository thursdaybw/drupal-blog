# Compute Orchestrator API Reference

## Services
### `Drupal\compute_orchestrator\Service\VastRestClient`
Encapsulates Vast.ai REST calls with added orchestration logic.
- `searchOffersStructured(array $filters, int $limit = 20): array` – POST `/api/v0/bundles/` with filters.
- `createInstance(string $offerId, string $image, array $options = []): array` – PUT `/api/v0/asks/<offer>/` to allocate.
- `showInstance(string $instanceId): array`, `destroyInstance(string $instanceId): array` – GET/DELETE `/api/v0/instances/<id>/`.
- `selectBestOffer(...)` / `provisionInstanceFromOffers(...)` – retries offers (with blacklists, host stats, diagnostics) until one runs and passes SSH/vLLM probes.
- `waitForRunningAndSsh(string $instanceId, int $timeoutSeconds = 180): array` – polls `showInstance`, performs SSH readiness and cURL probes, logs timestamps, and surfaces detailed diagnostics when Port 8000 and 8080 refuse.

Full method signatures are defined in `VastRestClientInterface` (`src/Service/VastRestClientInterface.php`).

### `Drupal\compute_orchestrator\Service\VastInstanceLifecycleClient`
- `startInstance(string $instanceId): array` – requests Vast to transition a pooled instance to `running`.
- `stopInstance(string $instanceId): array` – requests Vast to transition a pooled instance to `stopped`.

### `Drupal\compute_orchestrator\Service\VllmWorkloadCatalog`
- normalizes supported generic workloads (`qwen-vl`, `whisper`)
- carries the temporary Qwen `max_model_len=16384` runtime contract until the generic image default is rebuilt

### `Drupal\compute_orchestrator\Service\GenericVllmRuntimeManager`
- provisions a fresh generic image instance for a given workload
- waits for SSH bootstrap on the generic image
- starts/stops the selected runtime over SSH
- waits for workload readiness through the existing readiness adapter path

### `Drupal\compute_orchestrator\Service\VllmPoolRepository`
- persists pooled instance inventory in Drupal state under `compute_orchestrator.vllm_pool.instances`

### `Drupal\compute_orchestrator\Service\VllmPoolManager`
- `registerInstance()` – stores an arbitrary leased Vast contract as a pooled member so real pool scenarios can be staged against sleeping or already-running instances
- `listInstances()` – returns the pool inventory
- `acquire()` – applies the lease decision tree:
  - prefer reusable pooled instances
  - wake sleeping pooled instances before creating fresh ones
  - skip/mark `rented_elsewhere` when Vast wake stays stuck in scheduling
  - provision a fresh generic instance only when the pool has no usable member
- `release()` – marks a leased instance available again
- `getIdleShutdownSeconds()` – returns the configured idle shutdown threshold, defaulting to 600 seconds
- `getMaxInstancesPerWorkload()` – returns the configured per-workload pool-size limit, defaulting to `5` with `0` meaning unlimited
- `reapIdleAvailableInstances()` – stops available running instances that have exceeded the idle threshold
- `remove()` / `clear()` – lets operators reset the pool inventory to exercise explicit empty-pool and single-candidate scenarios

Client applications should treat `acquire()` / `release()` as the public lease boundary. They should not write pool timestamps or lease state directly. `release()` records `last_used_at`, which starts the idle shutdown timer used by `reapIdleAvailableInstances()`.

### `Drupal\compute_orchestrator\Service\BadHostRegistry`
- `all(): array` – returns the persisted `compute_orchestrator.bad_hosts` list (string IDs).
- `add(string $hostId): void` – appends unique host ids.
- `clear(): void` – removes the registry entries.

## Commands
### `compute:test-vast`
- Uses `VastRestClient` to provision a test instance.
- Supports `--workload` and `--image` overrides.
- `--workload=qwen-vl` defaults to `thursdaybw/vllm-qwen-stable:dev`.
- Logs details and destroys the instance on success unless `--preserve` is set.

### `compute:provision-vllm-generic`
- Provisions a fresh generic image instance.
- Starts the requested runtime (`qwen-vl` or `whisper`) over SSH.
- Reports the ready public endpoint for operator validation.

### `compute:vllm-pool-register`
- Adds an arbitrary leased Vast contract to the reusable pool inventory.
- Used for real-world scenario setup when the instance already exists on Vast but is not the current active Drupal runtime.

### `compute:vllm-pool-list`
- Shows the state-backed pool inventory and current lease/runtime metadata.

### `compute:vllm-pool-acquire`
- Acquires a pooled runtime for the requested workload.
- Uses the sleeping-instance-first lease policy with fresh provisioning only as a final fallback.

### `compute:vllm-pool-release`
- Marks an acquired pooled instance available again without destroying it.

### `compute:vllm-pool-reap-idle`
- Stops available pooled instances that have been idle for the configured threshold.
- Defaults to `compute_orchestrator.vllm_pool.idle_shutdown_seconds`, or 600 seconds when unset.
- Supports `--idle-seconds` and `--dry-run`.

### `compute:vllm-pool-remove`
- Removes one tracked pooled instance from Drupal state.

### `compute:vllm-pool-clear`
- Clears the entire tracked pool inventory so the empty-pool fallback can be tested deterministically.

### `compute:bad-hosts [--clear]`
- Lists or clears the persistent bad host list.
- Helpful during validation when you want to start fresh.
