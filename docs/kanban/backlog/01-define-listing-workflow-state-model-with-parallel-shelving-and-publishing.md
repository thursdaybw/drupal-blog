# Define Listing Workflow State Model With Parallel Shelving And Publishing

Date opened: 2026-03-25
Owner: bevan

Why:
- The current `status` field is modeling a linear pipeline, but post-review work is no longer linear.
- After review, shelving and publishing can happen in either order because location is no longer coupled to SKU.
- Extending the current single status enum further will create ambiguous states like `published_unshelved` and `shelved_unpublished`.

Definition of done:
- [ ] Document the target listing lifecycle model and name each state/flag clearly.
- [ ] Separate core lifecycle progression (`new`, `ready_for_inference`, `ready_for_review`, post-review ready state) from post-review operational fulfillment.
- [ ] Define explicit post-review dimensions for shelving and publishing instead of encoding both into one status value.
- [ ] List each allowed transition, the triggering UI/action, and the resulting state changes.
- [ ] Identify which current statuses become transitional, migrated, or deleted under the new model.

Next action:
- Write the state table for current versus target workflow, including publish-before-shelve and shelve-before-publish paths.

Links:
- Context: bulk intake now creates `new` listings and inference consumes `ready_for_inference`.
- Context: shelving currently drives `ready_to_publish`, but publish may now happen before shelving.
