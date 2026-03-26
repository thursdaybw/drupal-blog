# Make Stock Cull Picker Operational For Shelf Workflow

Date opened: 2026-03-19
Owner: bevan

Why:
- The current picker helps find cull candidates but still requires opening each listing, running a bottom-of-form action, then navigating back.
- The picker already has selection checkboxes, but no bulk action uses them, so the shelf-walking workflow is still too slow.
- The current flow gets the job done, but it is not efficient enough for repeated stock culls.

Definition of done:
- [ ] Add at least one picker-level bulk action that applies the stacked cull outcome to selected listings.
- [ ] Bulk action supports both `archive` and `lost` outcomes, or there is one clear operational bulk path with the other deferred explicitly.
- [ ] Bulk action preserves picker filters and returns to the picker after completion.
- [ ] Picker shows per-row or bulk result feedback for already-unpublished marketplace rows versus real failures.
- [ ] Add browser or kernel test coverage for the picker bulk action flow.

Next action:
- Design the first picker bulk action path so selected rows can be culled without opening each listing review page.

Links:
- Route: `/admin/ai-listings/reports/stock-cull/picker`
- Related commits: `e9ca5ab`, `b9369d1`, `f831f30`
