# Compute Orchestrator Usage Guide

## Environment prerequisites
- The Vast API key is read from Drupal config: `compute_orchestrator.settings:vast_api_key`.
- For production and local development, keep `compute_orchestrator.settings` in `config_ignore` and override the value in `settings.php`:
  ```php
  $vast_api_key = getenv('VAST_API_KEY');
  if (is_string($vast_api_key) && $vast_api_key !== '') {
    $config['compute_orchestrator.settings']['vast_api_key'] = $vast_api_key;
  }
  ```
- `VAST_API_KEY` must still be present in the runtime environment if `settings.php` uses that override.
- `VAST_SSH_PRIVATE_KEY_CONTAINER_PATH` should point to the private key registered with Vast (default `~/.ssh/id_rsa_vastai`). Export it inside `ddev ssh` or configure `.ddev/.env` so the key is available to both the REST probes and your manual SSH attempts.

## Running the validation command
```
ddev drush cr
# inside ddev ssh or direct drush run if env vars are set:
export VAST_SSH_PRIVATE_KEY_CONTAINER_PATH=/home/bevan/.ssh/id_rsa_vastai
drush compute:test-vast
drush compute:test-vast --workload=qwen-vl
drush compute:test-vast --workload=qwen-vl --image=thursdaybw/vllm-qwen-stable:dev
drush compute:provision-vllm-generic --workload=qwen-vl --image=thursdaybw/vllm-generic:2026-04-generic-node --preserve
```
- `compute:test-vast` provisions a workload-specific validation instance, waits for SSH/vLLM readiness, then destroys the instance on success.
- `qwen-vl` now defaults to `thursdaybw/vllm-qwen-stable:dev`.
- `--image` can override the workload default without changing code.
- `compute:provision-vllm-generic` is the separate operator path for the generic pooled-node image. It boots the node idle, starts the selected runtime over SSH, then waits for vLLM readiness.


## Pool operator vocabulary

- `lease_status` says whether a client may acquire the record. `available` means reusable; it does not mean running.
- `runtime_state` says whether the provider runtime is running, stopped, starting, destroyed, or unknown.
- `release` returns a lease to the pool. It does not stop or destroy the instance.
- `reap` stops an idle `available` runtime after the post-lease grace period. The pool record remains available for future acquire/restart.
- `remove` forgets a pool record in Drupal state. It does not destroy the Vast instance.
- `destroy` deletes the Vast instance at the provider and removes the pool record.
- `last_phase` and `last_action` are transitional last-operation fields for operator diagnostics. They should agree with the canonical `lease_status` and `runtime_state` fields.

## Managing the state-backed pool
```
ddev drush compute:vllm-pool-clear
ddev drush compute:vllm-pool-register 34414828 --image=thursdaybw/vllm-generic:2026-04-generic-node
ddev drush compute:vllm-pool-list
ddev drush compute:vllm-pool-acquire --workload=qwen-vl
ddev drush compute:vllm-pool-acquire --workload=whisper --no-fresh
ddev drush compute:vllm-pool-release 34414828
ddev drush compute:vllm-pool-reap-idle
ddev drush compute:vllm-pool-remove 34414828
```
- `compute:vllm-pool-clear` resets the pool inventory so the "nothing leased" scenario can be tested deterministically.
- `compute:vllm-pool-register` records an already-leased Vast contract in the pool inventory without requiring it to be the current active Drupal runtime. This is the operator path for testing "sleeping pooled instance" and "already-running pooled instance" scenarios against real contracts.
- `compute:vllm-pool-list` reads the Drupal-state inventory and prints explicit lease/runtime fields.
- `compute:vllm-pool-acquire` applies the pool lease policy:
  - reuse already-known pooled instances first
  - prefer waking a sleeping pooled instance over creating a fresh one
  - if waking a sleeping instance stalls in Vast scheduling, mark it `rented_elsewhere`, stop it again, and continue to the next candidate
  - only provision a fresh generic instance when no pooled candidate is usable
- `compute:vllm-pool-release` releases the runtime lease and marks the record available again. It does not stop or destroy the instance.
- `compute:vllm-pool-reap-idle` stops running instances whose lease state is `available` after the idle shutdown window. The default is 600 seconds and can be changed with `drush state:set compute_orchestrator.vllm_pool.idle_shutdown_seconds 900`.
- Pool scale-out is now limited by `compute_orchestrator.vllm_pool.max_instances_per_workload` (default `5`). Set it to `0` for unlimited. When all matching instances are leased, acquire may provision another one until this limit is reached. You can change it with `drush state:set compute_orchestrator.vllm_pool.max_instances_per_workload 2`.
- `compute:vllm-pool-remove` deletes one tracked record from Drupal state without destroying the Vast instance.

## Client lease contract
Applications that use the GPU pool must not mutate pool timestamps directly.

The contract is:
1. acquire a lease with `VllmPoolManager::acquire()`
2. run the workload using the acquired runtime
3. release the lease with `VllmPoolManager::release()` in both success and failure paths

`compute_orchestrator` owns `lease_status`, `last_used_at`, `last_seen_at`, `last_stopped_at`, and runtime metadata. The idle timer starts when `release()` sets `last_used_at`, so the reaper measures "time since the GPU stopped being used" rather than "time since the GPU was acquired".

Long-running jobs should keep the instance leased until they finish. The idle reaper intentionally ignores `leased` records so it does not interrupt active inference or transcription work. Stale leased-job recovery should be handled separately with explicit lease ownership/heartbeat fields before any automatic stop behavior is added.

## Real-world pool scenario matrix
- `nothing leased`
  - `compute:vllm-pool-clear`
  - `compute:vllm-pool-acquire --workload=qwen-vl`
  - expected: fresh generic instance is provisioned as the final fallback
- `nothing awake but a leased sleeping instance exists`
  - `compute:vllm-pool-clear`
  - `compute:vllm-pool-register <contract> --image=thursdaybw/vllm-generic:2026-04-generic-node`
  - `compute:vllm-pool-acquire --workload=qwen-vl --no-fresh`
  - expected: the sleeping contract is started and reused
- `matching instance already available`
  - acquire once, then `compute:vllm-pool-release <contract>`
  - `compute:vllm-pool-acquire --workload=qwen-vl --no-fresh`
  - expected: the already-running contract is reused without fresh provisioning
- `same pooled instance, different workload`
  - start with the contract registered/running for `qwen-vl`
  - `compute:vllm-pool-acquire --workload=whisper --no-fresh`
  - expected: the runtime manager stops the current model server and starts Whisper on the same contract
- `model cache cold vs warm`
  - first acquire for a workload on a contract with no cached model: expect a slower runtime start
  - second acquire for the same workload on the same contract: expect a faster restart because the model is already cached on that instance disk
- `idle shutdown`
  - acquire and release a contract
  - run `compute:vllm-pool-reap-idle --dry-run` before the idle window expires
  - run `compute:vllm-pool-reap-idle` after the idle window expires
  - expected: the contract remains leased from Vast but is stopped/inactive until the next acquire wakes it

## Strictness levels
The command reads `compute_orchestrator.strictness` (default `strict`) from Drupal state.
- `strict` → reliability ≥ 0.995, port requirement ≥ 16, favors successful hosts.
- `balanced` → reliability ≥ 0.98, port requirement ≥ 8.
- `aggressive` → reliability ≥ 0.95, port requirement ≥ 4, does not favor success history.

Change level with `drush state:set compute_orchestrator.strictness balanced` (or `aggressive`).

## Inspecting host reputation
```
ddev drush compute:bad-hosts      # list persistently bad hosts
ddev drush compute:bad-hosts --clear  # reset list
```

The module also tracks host stats in `compute_orchestrator.host_stats` and infrastructure fatal hosts in `compute_orchestrator.global_bad_hosts`.

## Notes
- The command still defaults to `tinyllama` for lightweight infrastructure validation.
- The `qwen-vl` workload uses `Qwen/Qwen2-VL-7B-Instruct` with the custom image `thursdaybw/vllm-qwen-stable:dev`.
- When provisioning or pool acquisition succeeds, the active vLLM model is stored in Drupal state so inference requests target the currently leased runtime.
- Logs are timestamped and include SSH/vLLM diagnostics to help identify GPU/CUDA startup issues.
