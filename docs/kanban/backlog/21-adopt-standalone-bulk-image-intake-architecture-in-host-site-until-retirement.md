# Adopt Standalone Bulk Image Intake Architecture In Host Site Until Retirement

Date opened: 2026-04-01
Owner: bevan

Why:
- Bulk image intake on the live site is currently too slow and fragile for operator workflow.
- `bb-ai-listing` is the source-of-truth product track and will replace the embedded host-site integration.
- Until cutover, bevansbench.com still needs the same reliable bulk-intake behavior to support day-to-day listing operations.

Definition of done:
- [ ] Mirror the approved `bb-ai-listing` bulk-intake architecture in bevansbench.com without introducing divergence.
- [ ] Keep workflow parity with standalone UX expectations:
- [ ] stage many image sets first
- [ ] process all sets in one controlled queued run
- [ ] show per-set status, errors, and retry actions
- [ ] Apply the same guardrails and limits documented in standalone.
- [ ] Add/port automated tests for multi-set success, partial failure + retry, and payload guardrails.
- [ ] Add/port assertion coverage that staged-set materialization yields listing status `ready_for_image_selection`.
- [ ] Add browser-level automation for intake page flow (stage sets -> process sets -> assert listing/status outcomes).
- [ ] Define and run a parity profile that mirrors production upload/timeouts/body-size limits.
- [ ] Run at least one compose-profile or remote-like validation pass to account for non-local latency/runtime behavior.
- [ ] Prove the flow in the demo harness with an operator-witnessed end-to-end demo run before marking done.
- [ ] Add temporary sync notes that map host implementation points to standalone counterparts.
- [ ] Define and document the retirement path for the host-site intake once standalone is fully cut over.

Notes:
- Primary design/implementation card lives in `bb-ai-listing` backlog:
- `67-design-and-implement-a-reliable-high-volume-bulk-image-intake-flow.md`.
- This host card exists to prevent fork drift during transition.
- Scope update (2026-04-01): Added explicit listing-status correctness, browser automation, and production-parity validation gates.

Next action:
- Once the standalone design spike is complete, create a host-site implementation checklist directly tied to the chosen architecture and queue model.
