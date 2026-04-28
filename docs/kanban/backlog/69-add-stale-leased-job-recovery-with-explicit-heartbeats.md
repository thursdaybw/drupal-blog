# Add stale leased-job recovery with explicit heartbeats

Date opened: 2026-04-28
Owner: bevan
Status: Backlog
Capture state: Capture
Source: html/modules/custom/compute_orchestrator/USAGE.md
Confidence: medium
Size guess: unknown
Urgency: unknown
Product area: compute_orchestrator

## Context

Usage notes say long-running jobs should keep instances leased until finished, and idle reaper intentionally ignores `leased` records. Stale leased-job recovery needs explicit lease ownership/heartbeat fields before automatic stop behaviour.

## Acceptance criteria

- [ ] Define lease owner and heartbeat fields.
- [ ] Define stale lease thresholds and operator confirmation rules.
- [ ] Add safe recovery path for abandoned leased jobs.
- [ ] Ensure active inference/transcription is never interrupted by idle reap.
- [ ] Add tests for stale lease recovery and non-interruption of active leases.

## Grooming questions

- [ ] Is this still true after the recent production stabilization?
- [ ] Is this already covered by an existing kanban card?
- [ ] Should this remain a standalone card, merge into another card, or be rejected?
- [ ] What evidence would promote this from `Capture` to `Groomed`?
