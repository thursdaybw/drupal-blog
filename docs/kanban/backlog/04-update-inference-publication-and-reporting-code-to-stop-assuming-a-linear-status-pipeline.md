# Update Inference, Publication, And Reporting Code To Stop Assuming A Linear Status Pipeline

Date opened: 2026-03-25
Owner: bevan

Why:
- Current automation and reporting logic likely assumes that one status implies everything else that came before it.
- That assumption will become false once post-review actions are parallel.
- Background processors, publish paths, and issue reports need to read the right dimension of state.

Definition of done:
- [ ] Inference code only depends on inference readiness states, not shelving or publishing assumptions.
- [ ] Publication code reads the explicit publish-readiness state instead of inferring from shelf-related status.
- [ ] Reports and workbench queries stop using mixed heuristics like `shelved + unpublished = ready to publish`.
- [ ] History and events remain coherent when publish and shelve happen in either order.
- [ ] Add or update tests for the affected processors and query services.

Next action:
- Trace every query and action that currently uses `status` as a proxy for publish or shelving readiness.

Links:
- Depends on: `Rework Review And Workbench Actions Around The New Workflow Model`
