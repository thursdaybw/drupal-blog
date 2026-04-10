# Migrate Framesmith provisioning to compute_orchestrator pool control

## Problem

Framesmith (`video_forge`) still provisions Vast instances directly via legacy CLI flow, while BB AI Listing uses `compute_orchestrator` pool semantics. This creates two orchestration generations in one host app. The target pool contract has also shifted: we are no longer aiming for per-model Vast instance configs, but for generic pooled GPU nodes with persistent model cache and runtime model switching.

## Outcomes

- Framesmith uses `compute_orchestrator` for start/stop/reservation selection instead of bespoke Vast provisioning flow.
- host app carries one orchestration contract for both listing inference and transcription workloads.
- both workloads share the same generic GPU-node contract, with runtime model/process switching and persistent cache reuse
- easier extraction of Framesmith runtime from monolith once orchestration is unified.
- migration starts only after the generic vLLM runtime is proven as a BB AI Listing drop-in replacement.

## Acceptance criteria

- add a Framesmith adapter path that requests compute from `compute_orchestrator` pool APIs/services
- remove direct Vast offer-search/create-instance coupling from active Framesmith path
- ensure task lifecycle states map cleanly to compute-orchestrator events (requested, allocated, running, released, failed)
- update operational docs/runbooks for pool-first orchestration
- verify end-to-end transcription flow in dev and prod with pool-backed instance control
- ensure Framesmith can request Whisper runtime on a pooled node without requiring a dedicated Whisper-only Vast instance

## Implementation notes

- keep current direct-provision path behind a temporary fallback flag only during migration window
- avoid introducing new production-only shell behavior in queue workers
- dependency gate: do not begin active Framesmith cutover until product card `13` and `bb-ai-listing` card `16` complete pooled-node generic runtime milestones (Qwen drop-in, Whisper runtime, runtime switching contract)
- blocked on host-site card [`29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md`](/home/bevan/workspace/bevansbench.com/docs/kanban/backlog/29-finish-compute-orchestrator-pooled-instance-lease-and-switch-api.md), which must define and implement the lease/fallback policy for sleeping pooled instances before Framesmith adopts the pool

## Status update - 2026-04-10

- Do not start active Framesmith cutover yet.
- Generic image can run Qwen and Whisper, and Whisper transcription has been proven on a live Vast instance.
- Pool API work is underway in `bb-ai-listing`; one real Vast branch has been validated (`34414828` asleep/rented elsewhere), but reusable-running and runtime-switch live scenarios still need completion.

## Next action

- Wait for card `29` to be ported and live-validated in this repo.
- Then add a Framesmith adapter that requests `whisper` from `compute_orchestrator` instead of calling the legacy `video_forge` Vast provisioning path directly.
