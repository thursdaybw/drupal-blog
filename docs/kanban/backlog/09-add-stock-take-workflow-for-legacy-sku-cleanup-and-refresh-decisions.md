# Add Stock-Take Workflow For Legacy SKU Cleanup And Refresh Decisions

Date opened: 2026-03-19
Owner: bevan

Why:
- Many legacy listings have no `storage_location` field set even though the location still exists inside SKU.
- Stock take is the natural point to extract that location, correct local data, and decide whether a listing should be refreshed.
- The workflow needs to support update-location, end-and-relist, and keep operational history coherent.

Definition of done:
- [ ] Add a stock-take workflow that can extract missing location from legacy SKU into `storage_location`.
- [ ] Workflow supports deciding whether to keep live, update location only, or end-and-relist.
- [ ] Workflow records the operational action in listing history.
- [ ] Legacy listings can be progressively cleaned up during stock take instead of requiring a one-shot migration.
- [ ] Add tests for legacy SKU location extraction and the chosen stock-take transition path.

Next action:
- Define the first stock-take action surface that updates `storage_location` from legacy SKU when the field is currently empty.

Links:
- Context: stock take, cull, and refresh workflows are currently blocked by location being embedded in legacy SKU.
