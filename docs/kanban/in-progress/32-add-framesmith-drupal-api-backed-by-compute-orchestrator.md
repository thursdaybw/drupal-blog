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

### Pool lifecycle integration design issue

### Task model vs runtime model clarification

The Framesmith task model is not considered superfluous.

Current architectural clarification:
- keep the Framesmith task model because it represents application/job state
- do not let the task model become a second competing runtime-lifecycle model
- keep `compute_orchestrator` pool records as the canonical infrastructure/runtime source of truth

Framesmith task model should own:
- task id and project/video linkage
- uploaded asset references
- Framesmith job status and history
- transcript/result references
- user-facing failure/debug context
- future draft/project workflow state

`compute_orchestrator` pool model should own:
- contract id / pooled instance identity
- lease status
- workload mode / current model
- availability for reuse
- release / reap / destroy lifecycle
- operational runtime state and cost-control actions

Bridge rule:
- Framesmith tasks may store lease snapshots for audit/debug
- but those snapshots must be clearly non-authoritative
- tasks should link to the canonical pool contract/record identity instead of implying a separate runtime lifecycle

Why this matters:
- Framesmith is expected to grow durable project state later (drafts, return later, repeated work on the same project)
- that future reinforces the need for a real task/project model
- it also reinforces the need to keep job/application state separate from infrastructure lifecycle state

Latest real-compute smoke uncovered an important design mismatch.

What was observed:
- the real Framesmith transcription path did use the existing `compute_orchestrator` pool lifecycle rather than a separate competing Vast implementation
- the task progressed through `acquiring_runtime` and captured a real pooled whisper lease snapshot
- the lease metadata appeared on the Framesmith task record (`lease` / `released_lease` snapshots)
- the expected global Drupal state keys were not updated, and the pool-admin mental model was therefore not the same as the task-level observability model

Why this matters:
- pool administration currently establishes one operational source of truth around pool records, lease status, release, reap/stop, and destroy
- Framesmith task debugging currently surfaces lease snapshots on the task itself
- that split makes it look like there may be two competing acquisition paths, even when Framesmith is actually standing on the existing pool manager
- it also makes cost-control actions harder to reason about in incident situations because the operator has to correlate task-local snapshots with pool-level records manually

Proposed direction:
- keep `VllmPoolManager` / pool records as the canonical operational source of truth for runtime lifecycle
- treat task-level `lease` / `released_lease` fields as audit/debug snapshots only
- explicitly link Framesmith task records to the canonical pool contract/record identity so admin tooling and task debugging point at the same instance
- make stop/reap/destroy expectations visible from the Framesmith task context without creating a second lease-management model

Follow-up engineering implication:
- after the immediate smoke-test fixes, add an integration pass that aligns Framesmith task observability with the pool-admin workflow so runtime acquisition, release, reap, and destroy are all clearly the same underlying lifecycle

### Reaped-instance reuse bug

Latest real-compute investigation uncovered a pool-behaviour bug that now needs active attention.

Observed behaviour:
- an available pooled whisper instance was reaped/stopped to save cost
- a later acquire did not restart and reuse that stopped instance
- instead the pool manager provisioned a fresh fallback instance, which burned additional Vast credits

Current diagnosis:
- the pool model is conflating lease availability with runtime power state
- reaping/stopping appears to move an otherwise reusable instance into an effectively unavailable state
- acquire logic then skips it and falls through to fresh provisioning

Required design correction:
- keep lease semantics and runtime power state on separate axes
- `lease_status` should answer lease availability (`available`, `leased`, etc.)
- a separate runtime/power state should answer whether the instance is `running`, `stopped`, `starting`, or similar
- a stopped instance can still be `available` for acquire

Expected behaviour after fix:
- release: instance becomes available without being stopped
- reap: instance is stopped but remains available for future acquire/restart
- acquire: prefers available running instances, then available stopped instances that can be restarted, then fresh fallback only when no reusable capacity exists

Implementation follow-up:
- add focused tests for stopped-instance reuse after reap
- update pool admin help text so it explicitly reflects the lease-state/runtime-state split and the intended restart-on-acquire behaviour

### Headless compute_orchestrator direction

Recent investigation clarified that the Framesmith path is intentionally diverging from Drupal Batch API orchestration.

Architectural intent:
- Framesmith should eventually become a standalone project, not a Drupal-bound frontend
- `compute_orchestrator` should serve that future by acting as a headless compute service behind an API
- for that reason, Framesmith should not depend on Drupal Batch API or browser-mediated Drupal execution patterns

Important distinction:
- the existing qwen-vl/admin path on main is still using Drupal Batch API and remains appropriate for Drupal-admin-driven workflows today
- the Framesmith path is future-facing headless orchestration, so it needs a service/job execution model rather than Drupal UI batch execution
- the current problem is therefore not “Framesmith should use Batch API”, but “the replacement async/background execution model is not yet robust enough”

Implications for planning:
- keep the Batch API path for existing Drupal-admin workflows until the headless execution path is mature and proven
- after `compute_orchestrator` reaches a reliable headless-service model, plan a deliberate retirement/migration of Batch API orchestration so there is a single execution model
- treat the current detached Drush launcher as a temporary bridge, not the desired long-term async execution foundation

### Portability / lift-out design goal

A new cross-cutting design goal should be tracked explicitly: `compute_orchestrator` should be only circumstantially inside Drupal.

Desired future property:
- core orchestration logic should be structured so it could later be lifted out of Drupal into its own service/application with limited rewrite
- Drupal should ideally provide one host environment, UI, and integration shell around that logic rather than being the hard-wired center of the design

Practical architectural consequences:
- prefer narrow adapters around Drupal-specific concerns (state, logging, commands, forms, controllers, file handling, queue/execution wiring)
- keep workload orchestration, lease logic, task lifecycle logic, and external API/client logic as framework-light as possible
- define interfaces at boundaries so alternative implementations could later swap in:
  - a different queue/worker backend
  - a different UI/admin surface
  - a different persistence layer
  - a different host framework or standalone daemon/service shell
- avoid letting Drupal Batch API, forms, or container conventions leak deeply into orchestration domain logic

Planning follow-up:
- identify current Drupal-coupled seams in `compute_orchestrator`
- define the target split between framework-agnostic orchestration core and Drupal adapters
- record a backlog item for introducing a more robust headless async execution model to replace the current detached Drush bridge
- later, once that model is proven, create a migration plan to retire Batch API execution paths onto the shared headless execution model

### Vast.ai coupling boundary

A further portability concern is now explicit: `compute_orchestrator` is currently very Vast.ai-shaped.

Risk:
- even if orchestration logic becomes more headless and less Drupal-bound, it may still remain tightly coupled to Vast.ai assumptions
- that would limit future ability to support a different compute provider, a self-hosted cluster, local workers, or an abstraction layer over multiple backends

Planning goal:
- identify which parts of the current system are genuinely generic orchestration concerns versus which are Vast.ai-specific provider concerns
- draw a clearer boundary so provider-specific lifecycle/search/provision/instance-state behaviour is isolated behind interfaces/adapters

Likely Vast.ai-coupled seams to inventory:
- offer search and host-selection logic
- create/start/stop/destroy instance lifecycle calls
- Vast-specific instance state vocabulary and transitions
- port/URL/public-IP extraction logic
- boot/readiness assumptions shaped around Vast responses
- error handling and retry logic tied to Vast API semantics (rate limits, queued scheduling, rented-elsewhere detection)
- pool reconciliation logic that currently interprets Vast instance payloads directly

Target architectural direction:
- keep orchestration domain logic provider-agnostic where possible
- isolate provider-specific translation into dedicated adapters/contracts
- make it possible in future to swap or add:
  - another cloud/provider backend
  - a local/dev compute backend
  - a queue/worker fleet not based on Vast instances

Planning follow-up:
- create an inventory of current Vast.ai-specific assumptions in `compute_orchestrator`
- define the intended provider boundary and contracts
- identify which current services should become provider adapters rather than orchestration-domain services
- record follow-on backlog work for progressive provider decoupling

### Detached-runner observability / task log visibility

Recent synchronous debugging proved that the actual Framesmith runner body works correctly end to end:
- task state transitions work when the runner is invoked directly
- pooled whisper runtime acquisition works
- remote transcription works
- pooled runtime release works

That isolates the current blocker to the detached launch/handoff bridge rather than the job body.

New planning insight:
- directing detached runner output to `/dev/null` is the wrong long-term trade-off
- the headless async execution path needs first-class observability, not blind background execution
- detached runner stdout/stderr should be captured in a task-scoped way so failures can be inspected through tooling and later surfaced in the UI

Desired direction:
- replace blind `/dev/null` redirection with deliberate detached-runner output capture
- store execution visibility as task-scoped runtime/operator information rather than as a surprise ad hoc root-level file dump
- make that information retrievable via admin/API/debug tooling
- later add an operator-facing viewport/panel in the UI for task execution logs and recent runner events

Architectural significance:
- this supports the headless-service direction better than hidden shell-side files do
- the conceptual object is “task execution log / runner visibility”, which should survive even if Drupal is later replaced as the UI shell
- Drupal can render that visibility now, but the logging/observability concept should belong to the compute/task model rather than to Drupal-specific batch or shell conventions

Planning follow-up:
- introduce task-scoped detached-runner output capture
- define where that visibility lives (task store, dedicated task-log store, or similar)
- expose it through debug/admin retrieval paths first
- later design the UI viewport for operator visibility

### Drush launcher as temporary adapter only

A further design constraint is now explicit: using `drush` as the launched worker command is in tension with the long-term portability goal for `compute_orchestrator`.

Clarified position:
- using a Drush command as the current worker entrypoint may be acceptable as a temporary implementation detail while `compute_orchestrator` still lives inside Drupal
- but Drush must not become the conceptual async job contract of the system
- instead, the spawn/worker boundary should be treated as a swappable adapter seam

Why this matters:
- a Drush-launched child process brings in Drupal bootstrap, Drush command wiring, and a Drupal-hosted process model as part of the execution boundary
- that may be part of the immediate detached-launch fragility in DDEV
- and it also works against the longer-term goal of lifting orchestration logic out of Drupal with limited rewrite

Desired direction:
- define the launcher/worker seam explicitly in framework-light terms (for example, “run task X”)
- keep the current Drush-based worker launch as one adapter implementation of that seam
- make it possible later to replace that adapter with alternatives such as:
  - standalone CLI worker
  - queue worker
  - supervised daemon/service
  - different host framework / process runner

Planning implication:
- avoid hard-wiring more Drush assumptions into orchestration domain logic
- when working on the current detached-launch bug, prefer solutions that strengthen the swappable launcher seam rather than deepening coupling to Drush-specific behaviour

### Task ownership remains unsettled; preserve optionality

Another boundary has now been identified more clearly: task CRUD/storage currently crosses concerns in a way that may reflect an unresolved ownership question rather than a settled design.

Current signal:
- Framesmith has a dedicated `FramesmithTranscriptionTaskStore` inside `compute_orchestrator`
- elsewhere, there is also a Drupal entity-backed task model around `video_forge_tasks`
- the naming itself suggests a cross-boundary concern: a Framesmith-specific task concept currently implemented inside `compute_orchestrator`

Important conclusion:
- task ownership is not yet fully settled
- and it does not need to be forced prematurely right now

The key design response is therefore:
- preserve optionality rather than pretending the final owner is already known
- treat task CRUD/lifecycle persistence as an adapter boundary
- avoid hard-wiring the system to a single host-specific storage assumption such as Drupal state, a Drupal entity, or a specific module-owned persistence mechanism

Planning implication:
- define a task contract boundary now
- defer the final ownership decision until the product and system shape make it clearer
- keep open the possibility of later evolving toward either:
  - Framesmith-owned tasks with `compute_orchestrator` acting as a compute backend/service
  - or a more generic compute job/task model owned by `compute_orchestrator`

The important thing now is not to decide too early, but to avoid making later clarification expensive.

### New pool-state hazard: stopped instance may be rented elsewhere while locally considered reusable

A new real-world failure mode has now been observed and must be treated as a first-class backlog/design concern.

Observed situation during Framesmith detached-runner probing:
- expected reusable stopped instance `35456908` had, in reality, been rented by someone else while stopped
- local pool state still treated it as if it were ours/reusable and scheduled it
- acquire then created a fresh fallback instance `35557834`, which became the actually running cost-incurring instance
- this leaves two concurrent hazards:
  - stale scheduled state on `35456908`, meaning it may auto-start later when the external renter releases it
  - fresh fallback instance `35557834` running now and burning money

Why this matters:
- “stopped” is not a safe local proxy for “still available to us later” in Vast.ai
- a local reusable/stopped record can go stale while externally changing ownership/rental status
- that stale state can produce delayed surprise starts and unexpected spend
- fallback acquisition can therefore stack on top of stale scheduled state instead of cleanly replacing it

Implications for design and backlog:
- acquire must explicitly detect and reject instances that are no longer actually ours to reuse, even if local state still says stopped/available/scheduled
- reconcile must be able to clear, quarantine, or otherwise neutralize stale scheduled records for instances rented elsewhere
- local scheduled state must not be allowed to remain a landmine that later auto-starts when remote conditions change
- dev/probe workflows should assume that a stopped Vast instance may be claimed by others before reuse

This is now a distinct operational/state-synchronization problem in addition to the SSH-readiness problem surfaced in the latest probe.

### Operational semantics must be the source of truth

A further design correction is now explicit and should govern follow-up work.

The problem is not merely that some names are a bit unclear. The deeper problem is allowing any gap at all between:
- what an operator means
- what a command says
- what the system state claims
- what the implementation actually does

Required principle:
- operational semantics are the source of truth
- implementation should reflect those semantics as directly as possible
- words must mean what they say
- the system must do what the operator asked, not something “nearby” in implementation space

Concrete implications:
- `stop` must mean stop
- `destroy` must mean destroy
- `remove from pool` must mean forget the local inventory record only
- `reconcile` must mean compare local belief to provider reality and correct local belief
- local state must never be treated as authoritative reality; it is at most cached belief pending provider verification

Why this matters:
- the recent incident was not just a one-off execution mistake; it exposed how easy it is for action names, local state, and real provider state to drift apart
- that drift pulled work away from the primary goal of getting orchestration working and into redesign/firefighting
- this must be corrected so the system supports direct, literal operation rather than interpretation

Planning consequence:
- follow-up design and implementation work should reduce semantic distance, not merely add more explanatory wording
- command names, UI labels, state labels, confirmations, and code paths should be reviewed under the test: “does this do exactly what it says?”
- this is not optional polish; it is required to make orchestration trustworthy under pressure

### Bad-host bootstrap failure handling is not complete

A further real-world requirement is now explicit.

Observed live failure mode:
- acquire selected or provisioned an instance
- Vast reported the instance as running
- SSH/bootstrap never became usable
- direct SSH checks also failed (`Connection closed by ... port ...`)
- the smoke therefore failed before transcription could complete
- the instance was left running until manually stopped

This shows that bad-host / bad-instance management is not actually complete for the acquire/bootstrap path, even if some related bad-host machinery already exists elsewhere.

Required behavior:
- if acquire proves an instance is a bad bootstrap target (for example repeated SSH bootstrap/login failure on an ostensibly running instance)
- the system must treat that instance as bad for this acquisition attempt
- stop and/or destroy it according to the operational policy for unusable fresh capacity
- record the bad host / bad instance so it is not immediately selected again
- then retry acquisition with another candidate or another fresh instance
- continue until success or a bounded, explicit retry threshold is reached

Operationally important consequence:
- a failed bootstrap on a paid fresh instance must not leave the orchestration flow dead-ended after one attempt
- and must not leave the failed instance running and burning money

Smoke-test consequence:
- a live smoke must only count as successful if it either:
  - completes transcription and verifies the instance is later stopped/reaped, or
  - fails after bounded retries while also verifying every bad/failed instance has been stopped or destroyed according to policy

This requirement should now be treated as part of the core orchestration contract, not as optional robustness work.

## Links

- Product execution card: `/home/bevan/workspace/bevans-bench-product/docs/kanban/backlog/15-make-framesmith-functional-using-compute-orchestrator.md`
- Product roadmap: `/home/bevan/workspace/bevans-bench-product/docs/roadmaps/compute-orchestrator-unification.md`
- Related host-site migration card: `docs/kanban/backlog/27-migrate-framesmith-provisioning-to-compute-orchestrator-pool-control.md`
- Pool API card: `docs/kanban/backlog/29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md`

## Next action

Get orchestration working again with the shortest trustworthy path while carrying forward the semantic corrections already exposed: fix the runtime-acquisition SSH/bootstrap blocker, implement real bad-host bootstrap handling (mark bad, stop/destroy unusable instances, retry another acquisition up to a bounded threshold), harden provider-truth verification during acquire/reuse, and reduce semantic drift so commands/actions/state do exactly what they say under operational pressure. Avoid further accidental coupling to Drush while keeping the launcher seam and task CRUD/storage seam explicitly swappable.
