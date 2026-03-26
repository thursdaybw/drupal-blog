# Add Explicit Post-Review Shelving And Publishing Fields To Listings

Date opened: 2026-03-25
Owner: bevan

Why:
- The new workflow needs first-class storage for post-review fulfillment state.
- Querying `status = shelved` plus publication state is too ambiguous to drive operational queues safely.
- The data model needs to represent post-review shelving and publishing independently.

Definition of done:
- [ ] Add the chosen explicit fields to `bb_ai_listing` for post-review shelving and publishing state.
- [ ] Add update hooks for existing installs.
- [ ] Preserve or backfill meaningful state for existing listings where current data allows it.
- [ ] Keep field naming precise and aligned with the agreed state model.
- [ ] Add kernel coverage for storage and migration behavior.

Next action:
- Implement the schema after the state table is agreed, with the narrowest viable field set.

Links:
- Depends on: `Define Listing Workflow State Model With Parallel Shelving And Publishing`
