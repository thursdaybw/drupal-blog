# Compute Orchestrator API Reference

## Services
### `Drupal\compute_orchestrator\Service\VastRestClient`
Encapsulates Vast.ai REST calls with added orchestration logic.
- `searchOffersStructured(array $filters, int $limit = 20): array` – POST `/api/v0/bundles/` with filters.
- `createInstance(string $offerId, string $image, array $options = []): array` – PUT `/api/v0/asks/<offer>/` to allocate.
- `showInstance(string $instanceId): array`, `destroyInstance(string $instanceId): array` – GET/DELETE `/api/v0/instances/<id>/`.
- `selectBestOffer(...)` / `provisionInstanceFromOffers(...)` – retries offers (with blacklists, host stats, diagnostics) until one runs and passes SSH/vLLM probes.
- `waitForRunningAndSsh(string $instanceId, int $timeoutSeconds = 180): array` – polls `showInstance`, performs SSH readiness and cURL probes, logs timestamps, and surfaces detailed diagnostics when Port 8000 and 8080 refuse.

Full method signatures are defined in `VastRestClientInterface` (`src/Service/VastRestClientInterface.php`).

### `Drupal\compute_orchestrator\Service\BadHostRegistry`
- `all(): array` – returns the persisted `compute_orchestrator.bad_hosts` list (string IDs).
- `add(string $hostId): void` – appends unique host ids.
- `clear(): void` – removes the registry entries.

## Commands
### `compute:test-vast`
- Uses `VastRestClient` to provision a test instance.
- Accepts no arguments. Logs details and destroys the instance on success.

### `compute:bad-hosts [--clear]`
- Lists or clears the persistent bad host list.
- Helpful during validation when you want to start fresh.
