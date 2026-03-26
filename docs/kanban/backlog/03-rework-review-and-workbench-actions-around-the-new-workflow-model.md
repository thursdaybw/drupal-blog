# Rework Review And Workbench Actions Around The New Workflow Model

Date opened: 2026-03-25
Owner: bevan

Why:
- The current review and workbench actions assume a fixed order after review.
- Once shelving and publishing are parallel, the UI must stop implying one required sequence.
- The workbench needs explicit queues for what actually remains to be done.

Definition of done:
- [ ] Review form transitions move listings into the new post-review ready state instead of a linear shelf-first status.
- [ ] Workbench exposes explicit filters or queues for listings ready for shelving.
- [ ] Workbench exposes explicit filters or queues for listings ready for publishing.
- [ ] Workbench exposes explicit filters or queues for listings complete in both dimensions.
- [ ] Bulk location update updates shelving-related state only.
- [ ] Publishing actions update publishing-related state only.
- [ ] Add tests covering both orderings: shelve-then-publish and publish-then-shelve.

Next action:
- Identify every current form, button, and workbench query that reads or writes `ready_to_shelve`, `ready_to_publish`, or `shelved`.

Links:
- Depends on: `Add Explicit Post-Review Shelving And Publishing Fields To Listings`
