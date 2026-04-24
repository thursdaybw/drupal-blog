# Add Framesmith Drupal API backed by compute_orchestrator

Date opened: 2026-04-24
Owner: bevan

## Outcome

Framesmith can run end-to-end transcription through Drupal endpoints backed by `compute_orchestrator`, without using the legacy `video_forge` Vast CLI provisioning path.

## Why

Framesmith is currently served from `html/framesmith` as a standalone frontend under the same Drupal host. The fastest reliable path is not a full service extraction yet. It is a pragmatic host-site integration:

Framesmith frontend → Drupal API → `compute_orchestrator` → pooled Vast runtime

This restores Framesmith functionality while preserving the later path to extract Framesmith, AI Listings, and `compute_orchestrator` into separate projects/services.

## Scope

- Do not modify `video_forge`.
- Do not extract services yet.
- Keep the short-term API in this Drupal host.
- Use `compute_orchestrator` for pooled `whisper` runtime acquire/release.
- Reuse SSH-based transcription execution initially if needed.
- Keep task paths isolated so reused pooled instances do not leak state between jobs.

## Definition of done

- [x] Add Framesmith-facing Drupal routes for transcription start/upload/status/result.
- [x] Route implementation requests a `whisper` runtime from `compute_orchestrator`.
- [x] Route implementation records enough task/lease state to recover or release leases.
- [ ] Transcription execution uses per-task remote paths, e.g. `/tmp/framesmith/{task_id}`.
- [ ] Successful completion releases the compute lease or leaves it in an intentional reusable state.
- [ ] Failure paths record useful status and do not orphan active leases silently.
- [ ] `html/framesmith` frontend is wired away from legacy `video_forge` endpoints.
- [ ] End-to-end transcription is validated in dev.
- [ ] Production cutover plan is documented before deploy.

## Execution model decision

Framesmith transcription must not depend on cron-triggered queue processing.

The previous task model introduced avoidable latency because queued work only began when cron ran the queue worker. In practice this could delay provisioning and transcription start by up to roughly a minute, creating a poor user experience and an artificial throughput bottleneck.

For Framesmith Phase 1:

- keep task records for status, polling, recovery, and result lookup
- do not rely on cron to begin transcription work
- do not use Drupal Batch API as the primary execution model
- do not introduce a separate always-on worker service

Instead, the Drupal API should launch a one-shot detached Drupal command for each transcription task. That command is responsible for:

1. acquiring a `whisper` runtime via `compute_orchestrator`
2. preparing isolated remote task paths such as `/tmp/framesmith/{task_id}`
3. executing transcription
4. persisting status and results back to the task record
5. releasing or recovering the compute lease on completion/failure

Target request flow:

`browser upload → Drupal API request starts work immediately → acquire whisper runtime now → begin remote execution now → poll live task state`

## Implementation note

Preferred implementation shape:

- thin Drupal controller/routes
- task state service
- launcher service that spawns a detached Drush command
- Drush command performs the long-running orchestration

This preserves immediate task kickoff without requiring cron or a separate daemon.

Reference:
- Product ADR: `/home/bevan/workspace/bevans-bench-product/docs/architecture/adr/2026-04-24-framesmith-transcription-execution-model.md`

## Testing strategy

Do not burn Vast.ai credits during normal development or automated testing.

Framesmith API and orchestration work should be tested primarily with fake or mocked compute-orchestrator dependencies, following the existing patterns already used in `compute_orchestrator` unit tests.

### Preferred approach

- unit test orchestration logic with fake or mocked implementations of Vast-facing interfaces
- kernel test the Drupal API contract with fake services injected into the container
- keep real Vast-backed smoke testing manual and opt-in only

### Practical testing shape

1. Extract long-running transcription orchestration into a dedicated runner service so the Drush command stays thin.
2. Unit test that runner service with fake dependencies rather than real Vast provisioning.
3. Kernel test the Framesmith API routes and task/status/result payloads with fake runner or launcher services.
4. Keep detached-process behaviour out of most tests except for limited manual verification.

### What to fake

Prefer fake PHP service implementations over a fake external HTTP server.

Use fakes or mocks for:

- `VllmPoolManager` or the lower Vast-facing interfaces beneath it
- runtime management / readiness dependencies
- SSH execution layers
- task/result persistence seams where helpful

### Why

This matches the existing `compute_orchestrator` testing style, keeps tests deterministic, avoids unnecessary infrastructure complexity, and prevents accidental Vast.ai credit usage.

## Flexible task guide

Use this as a working guide, not a rigid sequence. Tasks may move, split, merge, or change shape as implementation details become clearer.

### Current guiding tasks

- [x] Record the execution model decision: immediate kickoff, no cron in the critical path.
- [x] Add the initial Framesmith API skeleton: start, upload, status, result.
- [x] Add a detached launch path using a one-shot Drush command.
- [x] Extract orchestration from the Drush command into a dedicated runner service.
- [x] Add fake-backed unit tests for the runner service.
- [x] Add kernel tests for the Framesmith API contract using fake services.
- [x] Replace stub runner progress with real task lifecycle transitions.
- [x] Wire runner acquisition to `compute_orchestrator` for `whisper`.
- [ ] Define isolated remote working paths such as `/tmp/framesmith/{task_id}`.
- [x] Add remote execution and result collection flow.
- [ ] Persist transcript result payloads in a frontend-consumable shape.
- [ ] Add lease release and failure recovery handling.
- [ ] Repoint `html/framesmith/script.js` away from legacy `video_forge` endpoints.
- [ ] Validate end-to-end locally without real Vast provisioning by default.
- [ ] Run an explicit manual real-compute smoke test only when the fake-backed path is stable.
- [ ] Document production cutover and rollback notes.

### Working rule

Keep the goal stable even if the plan changes:

`Framesmith transcription starts immediately, runs through compute_orchestrator, reports live task state, and does not depend on cron-triggered queue execution.`

## Execution mode note

The Framesmith backend now has an explicit transcription execution seam via `FramesmithTranscriptionExecutorInterface`.

Current implementation state:
- real executor path exists and posts audio to the leased Whisper runtime over HTTP
- fake-backed PHPUnit coverage exists for the runner and executor seam
- runtime fake mode for frontend/dev use is still required

### Required follow-up

Add a fake runtime executor for local/frontend testing so Framesmith can talk to the real Drupal API without acquiring pooled Vast/Whisper compute.

Requirements for fake mode:
- use the same Drupal API contract as real mode
- return deterministic transcript payloads
- never acquire real pooled compute when fake mode is enabled
- be explicitly selectable by configuration/environment
- remain easy to reason about in project tracking and operator docs

### Future tracking note: fake lease / fake Vast layer

Not needed for the immediate frontend/dev fake-mode milestone, but likely useful later as the project grows.

Potential future seam:
- fake `FramesmithRuntimeLeaseManagerInterface` for runtime/dev flows that want to simulate lease acquisition and release
- possibly deeper fake `compute_orchestrator` / Vast-facing services for more realistic orchestration tests

Likely future uses:
- simulate lease contention
- simulate startup delays and lease failures
- test stale lease recovery behaviour
- exercise more realistic orchestration paths without real Vast spend

Current position:
- fake transcription execution exists now
- fake lease / fake Vast behaviour is intentionally deferred until it becomes clearly useful

### Browser automation follow-up

### Browser automation progress note

Latest confirmed position:
- the served Framesmith API now works end to end in fake mode on the live DDEV site
- the frontend has been repointed to the new `/api/framesmith/transcription/...` API
- the remaining uncertainty is now browser-level final-state assertion behaviour, not backend architecture

What the browser work has already shown:
- the DTT browser smoke is reaching the real UI flow rather than failing purely at setup
- the correct visible milestones for assertions are the user-facing states:
  - `No video loaded`
  - `Video ready`
  - `Captions ready`
  - `Transcript` button enabled
  - transcript panel text visible
- one concrete issue found during browser work was that the generated fixture MP4 must live under the real DDEV docroot (`/var/www/html/html`) to be fetchable by `/framesmith/?fixture=...`

Immediate next focus:
- finish stabilising the DTT browser smoke test around visible UI milestones
- prefer assertions on status text and transcript-button enablement before the final transcript-panel text assertion
- use the existing fixture query-param path (`?fixture=...`) rather than Selenium file-upload automation where possible

### Frontend wiring dependency note

A browser-automation test remains part of the plan, but it is not the immediate next implementation step anymore.

What was discovered:
- the real served Framesmith app is currently available at `/framesmith/`
- the app UI is still wired to legacy `video_forge` transcription endpoints
- the new Framesmith Drupal API exists, but the frontend has not yet been repointed to it

Implication:
- a browser automation test written immediately would exercise the old path, not the new Framesmith API work
- therefore the browser smoke test plan is retained, but now depends on a frontend repoint phase first

Retained follow-up after repoint:
- run fake-mode browser automation against the real served `/framesmith/` UI
- drive the real Transcribe flow through DTT/WebDriver/Selenium
- assert transcript completion against the deterministic fake executor result
- then proceed to the later real-compute smoke test

Because frontend access is not always available from the user's current device, the next frontend verification step should prefer the existing Drupal browser-testing stack over manual walkthroughs.

Intended scope:
- switch Framesmith transcription executor mode to `fake`
- drive the real served frontend with DTT/WebDriver/Selenium after the frontend has been repointed to the new API
- upload the known WAV fixture or equivalent deterministic fixture
- wait for completion through the real UI flow
- assert the fake transcript result appears as expected
- keep this separate from the later real-compute smoke test

## Links

- Product execution card: `/home/bevan/workspace/bevans-bench-product/docs/kanban/backlog/15-make-framesmith-functional-using-compute-orchestrator.md`
- Product roadmap: `/home/bevan/workspace/bevans-bench-product/docs/roadmaps/compute-orchestrator-unification.md`
- Related host-site migration card: `docs/kanban/backlog/27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md`
- Pool API card: `docs/kanban/backlog/29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md`

## Next action

Finish stabilising the focused fake-mode browser-automation smoke test for `/framesmith/` using the existing Drupal browser-testing stack (DTT/WebDriver/Selenium), with visible UI milestones as the source of truth: `Video ready`, `Captions ready`, `Transcript` enabled, then transcript panel text. After that, run the first real end-to-end smoke test with the known-text WAV fixture.
