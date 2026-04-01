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
- [ ] Add temporary sync notes that map host implementation points to standalone counterparts.
- [ ] Define and document the retirement path for the host-site intake once standalone is fully cut over.

Notes:
- Primary design/implementation card lives in `bb-ai-listing` backlog:
- `67-design-and-implement-a-reliable-high-volume-bulk-image-intake-flow.md`.
- This host card exists to prevent fork drift during transition.

Next action:
- Once the standalone design spike is complete, create a host-site implementation checklist directly tied to the chosen architecture and queue model.
