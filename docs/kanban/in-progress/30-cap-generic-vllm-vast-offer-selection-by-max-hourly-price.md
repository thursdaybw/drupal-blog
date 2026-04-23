# Cap generic vLLM Vast offer selection by max hourly price

Date opened: 2026-04-23
Owner: bevan

Why:
- Live pool testing selected a Vast offer at roughly $1.23/hr, which is too expensive for iterative dev validation.
- The code already supports a `maxPrice` filter in `VastRestClient::selectBestOffer()`, but the generic vLLM runtime does not pass one.
- We need a config-backed safety rail so dev and production can use different price ceilings without code edits.

Definition of done:
- [ ] Add a config-backed `max_hourly_price` setting to `compute_orchestrator.settings`.
- [ ] Expose the setting in the Compute Orchestrator admin settings form.
- [ ] `GenericVllmRuntimeManager::provisionFresh()` reads the configured cap and passes it to `selectBestOffer()`.
- [ ] Fresh provisioning throws a clear exception when no offers are available under the configured cap.
- [ ] The default cap is suitable for low-cost dev validation.

Next action:
- Add the setting, wire it into generic vLLM provisioning, and fail fast when the market has no offer under the cap.
