# Review compute_orchestrator architecture and Drupal coupling

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

`compute_orchestrator` is now production-proven as the shared runtime control path for both:

- Framesmith / Whisper transcription; and
- AI listing / Qwen image inference.

That validates the pragmatic host-site implementation, but it also makes the architecture boundary more important. The long-term direction is that `compute_orchestrator` should be only circumstantially inside Drupal: Drupal is the current host shell, UI, config surface, and integration environment, not necessarily the permanent conceptual center of the orchestration system.

This concern was captured during the Framesmith integration work but was buried inside the completed Phase 1 card. It needs its own trackable architecture/refactor card.

## Problem

The current implementation works, but parts of `compute_orchestrator` may be too tightly coupled to Drupal and Vast.ai implementation details:

- Drupal controllers, forms, Batch API, Drush commands, state storage, logging, and service-container conventions may leak into orchestration logic.
- The detached worker path currently uses Drush as the process entrypoint, which is acceptable as a temporary adapter but should not become the conceptual job contract.
- Framesmith task storage currently lives inside `compute_orchestrator`, raising an ownership question: are tasks owned by Framesmith, by a generic compute job model, or by the host Drupal app?
- Vast.ai assumptions may be embedded too deeply in pool lifecycle logic, instance identity, offer selection, ports, SSH, readiness, and provider status translation.

If these seams are not identified deliberately, the later split into `compute_orchestrator`, Framesmith, and AI listing projects will require a larger rewrite than necessary.

## Desired direction

Keep the working Drupal-hosted production path, but make the core orchestration model progressively more framework-light:

- Drupal should provide adapters around host concerns:
  - config/settings forms;
  - admin UI;
  - route/controllers;
  - Drush commands;
  - state/entity storage;
  - Drupal logging/watchdog;
  - Batch API integration for Drupal-admin workflows.
- Core orchestration should be structured around portable concepts:
  - acquire/release lease;
  - switch workload;
  - runtime readiness;
  - provider lifecycle;
  - task/job execution;
  - probe history/observability;
  - pool record canonical state.
- Provider-specific code should sit behind explicit provider interfaces/adapters rather than leaking Vast.ai semantics everywhere.
- Drush should remain an implementation adapter for the current host, not the long-term async execution contract.

## Acceptance criteria

- [ ] Inventory current Drupal-coupled seams in `html/modules/custom/compute_orchestrator`.
- [ ] Inventory current Vast.ai-coupled seams.
- [ ] Inventory task/job ownership seams, especially Framesmith task storage versus generic compute job state.
- [ ] Inventory launcher/worker seams, especially Drush-launched child processes.
- [ ] Define a target architecture split:
  - framework-light orchestration core;
  - Drupal adapters;
  - provider adapters;
  - workload adapters;
  - task/job storage adapters;
  - launcher/worker adapters.
- [ ] Identify which existing interfaces are already good seams and which need extraction or renaming.
- [ ] Identify the minimum refactors that reduce coupling without destabilizing the working prod path.
- [ ] Decide which Drupal-admin flows may continue using Batch API for now and which headless flows should avoid Batch API.
- [ ] Produce a short architecture note or ADR.
- [ ] Create focused implementation cards for any approved refactors.

## Initial seam inventory prompts

Review at least these areas:

- `VllmPoolManager`
- `VllmPoolRepositoryInterface` / `VllmPoolRepository`
- `GenericVllmRuntimeManagerInterface` / `GenericVllmRuntimeManager`
- `Vast*Client*` interfaces and implementations
- `SshProbeExecutor` and SSH context/value objects
- `FramesmithTranscriptionRunner`
- `FramesmithTranscriptionLauncherInterface` / Drush launcher implementation
- `FramesmithTranscriptionTaskStoreInterface` / task store implementation
- `VllmPoolBatch`
- `VllmPoolAdminForm`
- Drush command classes
- controller classes
- watchdog/logging usage
- Drupal `state` usage

## Related follow-ups

- Pool state semantics: `docs/kanban/backlog/normalize-vllm-pool-record-state-fields.md`
- Durable Framesmith task persistence: `docs/kanban/backlog/41-decide-durable-framesmith-task-persistence-model.md`
- Project split boundaries: `docs/kanban/backlog/40-define-long-term-project-boundaries-for-compute-framesmith-and-ai-listing.md`
- Vast pool operator runbook: `docs/kanban/backlog/38-write-vast-pool-operator-runbook-and-manual-recovery-guide.md`
- SSH/probe logging: `docs/kanban/in-progress/31-capture-compute-orchestrator-ssh-probe-history-to-local-jsonl-log.md`
- Vast price cap/provider selection: `docs/kanban/in-progress/30-cap-generic-vllm-vast-offer-selection-by-max-hourly-price.md`

## Source notes

This card extracts the architecture concerns from the completed Framesmith Phase 1 card, especially the sections titled:

- `Headless compute_orchestrator direction`
- `Architectural portability note`
- `Vast.ai coupling boundary`
- `Drush launcher / worker boundary`
- `Task CRUD / storage ownership boundary`
