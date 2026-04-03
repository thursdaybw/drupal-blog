# Harden bulk intake chunk transport for 50-set internet-latency runs

## Problem

The new chunked uploader is functionally correct, but long internet-latency runs (`50 sets x 2 files`, ~1.5 MiB/file) still produce transport instability under stress:

- transient `net::ERR_ABORTED` and `Failed to fetch`
- mid-run partial failures even with retries
- operator uncertainty about whether retries are recoverable or terminal

This is exactly the pre-prod reliability class we need to close before relying on the flow in production.

## Outcomes

- chunk transport is resilient for the target workflow envelope:
  - `70 sets x 2 files` at ~1.5 MiB/file minimum
- transient network faults do not force full-batch restart
- failures are explicit, recoverable, and bounded

## Acceptance criteria

- run `50 sets x 2 files` over `https://dev.bevansbench.com` completes with no terminal failed rows in three consecutive runs
- run `70 sets x 2 files` completes at least once without manual intervention
- duplicate/replayed chunk requests are idempotent and never cause false out-of-order failure
- any terminal failure response includes:
  - set id
  - file name
  - chunk index/count
  - server-side reason
- a user can continue from partial state via explicit recovery action (for example, retry failed sets)

## Decision gate: switch to tus

Switch this card from "harden current chunk path" to a dedicated tus ingress implementation when any one of these is true:

- after two additional hardening iterations, we still cannot complete `3` consecutive `50 x 2` runs over `https://dev.bevansbench.com`
- upload reliability work is consuming more delivery time than core listing/product features for one full sprint
- we need guaranteed pause/resume across refresh/crash for non-technical operators
- we plan to onboard additional users and cannot accept batch upload restarts as normal behavior

If the gate triggers, stop patching this transport path and open/execute a tus ingress card immediately.

## Implementation notes

- keep chunk endpoint idempotent for replay after client timeout/abort
- add bounded retry with jitter/backoff for both transport and non-2xx application errors
- support resume semantics per file (`upload_id` state persisted until completion)
- ensure chunk state cleanup does not race with in-flight retries

## Test plan

- automated kernel/controller coverage for:
  - chunk sequence happy path
  - duplicate chunk replay
  - repeated final-chunk replay after completion marker
- harness stress profile:
  - `HARNESS_BULK_INTAKE_SET_COUNT=50`
  - `HARNESS_BULK_INTAKE_FILES_PER_SET=2`
  - `HARNESS_BULK_INTAKE_TARGET_FILE_BYTES=1572864`
- archive artifacts and failure summaries for each stress run

## Iteration tracking

Track each stress run here so the tus decision is evidence-based.

| Iteration | Date (UTC) | Profile | Environment path | Result | Failure signature | Notes |
| --- | --- | --- | --- | --- | --- | --- |
| 1 | 2026-04-02 | 50 sets x 2 files (~1.5 MiB/file) | `dev.bevansbench.com` tunnel | Failed | set_17 transport failure (`Failed to fetch`) | total progress UI added during this cycle |
| 2 | 2026-04-02 | 50 sets x 2 files (~1.5 MiB/file) | `dev.bevansbench.com` tunnel | Failed | set_1 409 (`Out-of-order chunk. Expected 2, got 1`) | fixed via idempotent duplicate chunk handling |
| 3 | 2026-04-02 | 50 sets x 2 files (~1.5 MiB/file) | `dev.bevansbench.com` tunnel | Failed | set_13 transport failure after retries (`Failed to fetch`) | confirmed ordering bug fixed; transport instability remains |
| 4 | 2026-04-02 | 89 staged sets (real work batch) | production (`www.bevansbench.com`) | Passed | N/A | `Processed 89 staged set(s) into listings... skipped 0` |
| 5 | 2026-04-02 | 100 sets x 2 files (~1.5 MiB/file) | `dev.bevansbench.com` tunnel | Failed | set_98 stalled then terminal transport error (`Failed to fetch`, `ERR_FILE_NOT_FOUND`) | reached 97% completion before failure; added resume-on-retry requirement |

### Per-iteration data to capture

- total wall-clock time (start to terminal state)
- completed set count at failure or completion
- failed set ids and first failing chunk metadata
- number of retries consumed (if available)
- artifacts path from harness run

### Scope update (2026-04-02)

- Added recovery behavior requirement: a second `Stage uploaded sets` action must continue from failed sets only, without re-uploading already completed sets in the same page session.
