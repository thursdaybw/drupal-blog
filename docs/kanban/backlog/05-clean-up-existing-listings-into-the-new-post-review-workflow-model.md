# Clean Up Existing Listings Into The New Post-Review Workflow Model

Date opened: 2026-03-25
Owner: bevan

Why:
- Existing listings already contain mixed linear-status assumptions and some historical dirty states.
- The new workflow will not be trustworthy unless current listings are mapped into it deliberately.
- One-time cleanup rules need to be explicit so old ambiguous rows do not poison the new queues.

Definition of done:
- [ ] Define deterministic mapping rules from current listing state into the new model.
- [ ] Classify ambiguous rows that cannot be migrated automatically.
- [ ] Provide a dry-run report before applying migration rules on production data.
- [ ] Apply the cleanup or migration path safely for existing listings.
- [ ] Verify workbench queue counts are coherent after migration.

Next action:
- Draft the mapping table from current combinations of listing status, publication state, and location presence into the new workflow fields.

Links:
- Depends on: `Update Inference, Publication, And Reporting Code To Stop Assuming A Linear Status Pipeline`
