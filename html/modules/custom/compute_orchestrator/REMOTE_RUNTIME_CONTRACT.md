# Remote runtime orchestration contract

Status: draft contract for GitHub issue #10
Date: 2026-04-28

## Purpose

`compute_orchestrator` is becoming shared platform infrastructure. Framesmith and AI listings should be able to use it without living inside the same Drupal module or sharing Drupal state.

This document defines the remote contract that external clients should depend on. The first implementation may still live inside the current Drupal module, but the contract should be shaped as if Framesmith and AI listings are separate clients.

The goal is runtime portability: product applications request, use, renew, and release runtime leases without knowing about Vast.ai provider details, Drupal Batch, Drush launchers, or internal pool state mutation rules.

## Contract boundary

`compute_orchestrator` owns runtime orchestration:

- lease acquisition and release;
- workload preparation;
- runtime readiness;
- provider lifecycle;
- pool state;
- lease expiry and renewal;
- idle reap behaviour;
- operator diagnostics.

Client products own product task state:

- Framesmith owns transcription jobs, uploaded media, transcript output, retries, and user-facing task history;
- AI listings owns listing inference batches, listing field updates, review workflow, and marketplace-specific state.

A generic job facility may be designed later. It is not part of this first remote runtime contract.

## Supported workloads

Initial workloads:

- `whisper` for Framesmith transcription;
- `qwen-vl` for AI listing image inference.

Workload names are client-visible contract values. Provider image names, container commands, port mappings, and readiness probes are internal details.

## Resource model

### Runtime lease

A runtime lease is the external client-facing claim on a prepared runtime.

Minimum fields:

```json
{
  "lease_id": "vast:12345",
  "lease_token": "opaque-token",
  "workload": "whisper",
  "model": "openai/whisper-large-v3",
  "endpoint_url": "http://203.0.113.10:22097",
  "lease_status": "leased",
  "runtime_state": "running",
  "expires_at": "2026-04-28T12:15:00Z"
}
```

Current implementation note: `lease_id` can initially map to the pool record `contract_id`, and `lease_token` can initially map to the existing pool record lease token.

### Lease status

Client-visible values:

- `leased` — client may use the runtime;
- `released` — lease has been returned to the pool;
- `expired` — lease timed out and must not be used;
- `unavailable` — no usable runtime is available;
- `provisioning` — orchestration is still preparing a runtime;
- `failed` — orchestration failed to prepare or maintain the runtime.

Internal pool values may differ during transition, but remote responses should use the client-visible vocabulary above.

### Runtime state

Client-visible values:

- `starting`;
- `running`;
- `stopped`;
- `destroyed`;
- `unknown`.

Provider-specific values such as Vast status strings should remain diagnostics, not contract control values.

## Operations

The first transport should be Hypertext Transfer Protocol with JSON request and response bodies.

### Acquire runtime lease

Request a runtime for a workload.

```http
POST /api/compute-orchestrator/runtime-leases
```

Request body:

```json
{
  "workload": "whisper",
  "model": null,
  "client": "framesmith",
  "purpose": "transcription",
  "allow_provision": true,
  "lease_ttl_seconds": 900,
  "idempotency_key": "framesmith-task-uuid"
}
```

Successful response:

```json
{
  "lease": {
    "lease_id": "vast:12345",
    "lease_token": "opaque-token",
    "workload": "whisper",
    "model": "openai/whisper-large-v3",
    "endpoint_url": "http://203.0.113.10:22097",
    "lease_status": "leased",
    "runtime_state": "running",
    "expires_at": "2026-04-28T12:15:00Z"
  },
  "diagnostics": {
    "source": "existing_pool_member",
    "last_operation": "lease: leased"
  }
}
```

Rules:

- The client must not mutate pool state directly.
- The client must store the returned `lease_id` and `lease_token` with its own product task state.
- `allow_provision=false` means fail if no existing usable pool member can be prepared.
- `idempotency_key` should prevent duplicate leases for the same client task once implemented.

### Get lease status

```http
GET /api/compute-orchestrator/runtime-leases/{lease_id}
```

Response:

```json
{
  "lease": {
    "lease_id": "vast:12345",
    "workload": "whisper",
    "model": "openai/whisper-large-v3",
    "endpoint_url": "http://203.0.113.10:22097",
    "lease_status": "leased",
    "runtime_state": "running",
    "expires_at": "2026-04-28T12:15:00Z"
  },
  "diagnostics": {
    "last_seen_at": "2026-04-28T12:03:00Z",
    "last_operation": "lease: renewed"
  }
}
```

Rules:

- Do not return provider credentials.
- Do not return private SSH material.
- Provider identifiers may be returned as diagnostics only when useful for operators.

### Renew lease

```http
POST /api/compute-orchestrator/runtime-leases/{lease_id}/renew
```

Request body:

```json
{
  "lease_token": "opaque-token",
  "lease_ttl_seconds": 900
}
```

Rules:

- Renewal requires the current lease token.
- Renewal extends the expiry time and records a coherent last operation.
- Renewal must fail if the lease is released, expired, destroyed, or token-mismatched.

### Release lease

```http
POST /api/compute-orchestrator/runtime-leases/{lease_id}/release
```

Request body:

```json
{
  "lease_token": "opaque-token",
  "reason": "framesmith task completed"
}
```

Rules:

- Release returns the runtime to the reusable pool.
- Release does not stop or destroy the provider instance.
- Idle reap remains `compute_orchestrator` responsibility after the post-lease grace period.
- Release should be idempotent for already released leases when the same token/client context is presented.

### List runtime pool diagnostics

```http
GET /api/compute-orchestrator/runtime-pool
```

This operation is primarily for operators and trusted service clients.

Response:

```json
{
  "items": [
    {
      "lease_id": "vast:12345",
      "workload": "whisper",
      "lease_status": "available",
      "runtime_state": "stopped",
      "last_operation": "idle_reap: stopped"
    }
  ]
}
```

Rules:

- This is diagnostic, not the primary client task contract.
- Clients should not infer task state from the pool list.

## Error model

Common error response shape:

```json
{
  "error": {
    "code": "runtime_unavailable",
    "message": "No usable runtime is available for workload whisper.",
    "retryable": true,
    "diagnostics": {
      "phase": "acquire",
      "action": "select_pool_member"
    }
  }
}
```

Initial error codes:

- `invalid_request` — request validation failed;
- `unauthorized` — client is not authenticated;
- `forbidden` — client lacks permission for the workload or operation;
- `workload_unknown` — requested workload is not supported;
- `runtime_unavailable` — no runtime can currently be leased;
- `runtime_provisioning_failed` — provider or readiness preparation failed;
- `lease_not_found` — lease identifier is unknown;
- `lease_token_mismatch` — lease token does not match the active lease;
- `lease_expired` — lease has expired and cannot be renewed or used;
- `lease_already_released` — lease was already released;
- `provider_failure` — provider lifecycle operation failed;
- `readiness_failed` — runtime did not become ready for the requested workload.

## Authentication and authorization

Service-to-service clients must authenticate with OAuth bearer tokens through the existing Drupal `simple_oauth` / `consumers` stack.

Runtime lease routes must opt into OAuth route authentication:

```yaml
options:
  _auth: ['oauth2']
```

The authenticated service user or client must also have the Drupal permission:

```text
use compute runtime leases
```

This keeps authentication and authorization separate:

- OAuth authenticates the backend service client;
- the Drupal permission authorizes access to runtime lease operations;
- browser clients do not receive compute service credentials;
- compute lease tokens remain backend-owned task metadata;
- operator-only diagnostics should stay behind a stronger permission if diagnostic routes expand.

Initial service clients:

- Framesmith backend, for `whisper` runtime leases;
- AI listing backend, for `qwen-vl` runtime leases.

Future hardening may split authorization by workload, for example `use compute runtime leases for whisper` and `use compute runtime leases for qwen-vl`, if one shared permission becomes too broad.

## Compatibility with current implementation

Current internal service mapping:

- acquire lease: `VllmPoolManager::acquire()`;
- renew lease: `VllmPoolManager::renewLease()`;
- release lease: `VllmPoolManager::release()`;
- pool diagnostics: `VllmPoolManager::listInstances()`;
- idle reap: `VllmPoolManager::reapIdleAvailableInstances()`.

Current product adapter mapping:

- Framesmith currently uses `FramesmithRuntimeLeaseManagerInterface` and `FramesmithVllmPoolLeaseManager` in-process.
- Long-term Framesmith should own task state and call this remote contract as a client.
- AI listings should follow the same pattern for image inference batches.

## Out of scope for this first contract

- generic task/job storage;
- product task lifecycle;
- file upload or media storage;
- transcript storage;
- listing field updates;
- marketplace publishing state;
- provider selection policy details;
- direct Vast.ai access from client applications;
- Drupal Batch as a client-facing contract.

## First implementation slice

Recommended first code slice:

1. Add Drupal routes for acquire, get, renew, release, and list diagnostics.
2. Add a controller that delegates only to `VllmPoolManager`.
3. Normalize remote response fields so client-visible vocabulary is not raw pool record vocabulary.
4. Add unit or kernel-level coverage for request validation and response mapping.
5. Keep existing in-process Framesmith path working until the remote client is ready.

