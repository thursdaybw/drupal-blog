# AGENTS.md

## Purpose
This repository must be built with clean boundaries, deterministic core logic, and composable modules.

Default stance: prefer long-term correctness over short-term convenience.

## Core Standards

### 1. SOLID is non-negotiable
- **S**: One reason to change per class/file.
- **O**: Extend via new adapters/services, not by modifying stable policy classes.
- **L**: Subtypes must honor interface contracts without surprises.
- **I**: Small focused interfaces; avoid god interfaces.
- **D**: Application/domain depend on abstractions, not concrete infrastructure.

### 2. Clean Architecture boundaries
- Layers: `UI -> Application -> Domain <- Infrastructure`.
- Dependencies point inward only.
- Domain must not depend on Drupal runtime, network, filesystem, or external APIs.
- Infrastructure adapts to application ports; not the other way around.

### 3. Module responsibilities
- Keep module scope narrow and explicit.
- Do not turn service classes into broad “god clients”.
- If one service starts handling multiple API concerns, split it.

Example split direction for eBay infrastructure:
- `EbayInventoryClient`
- `EbayOfferClient`
- `EbayLocationClient`
- `EbayTaxonomyClient`
- `EbayFulfillmentOrdersClient`

### 4. Application use-cases
- Put orchestration in explicit use-case services.
- Keep use-cases deterministic and testable.
- Use DTO/value models across boundaries.
- Controllers/commands should delegate, not embed business logic.

### 5. Persistence discipline
- Repositories implement application ports.
- Repositories must be idempotent where sync/import semantics require it.
- Schema changes require update hooks for existing installs.
- Keep raw source payloads only where useful for audit/debug; prefer normalized fields for querying.

### 6. Read model vs write model
- Ingest broadly, filter in read models.
- Do not bake operational filters into ingestion pipelines unless explicitly required.
- Build dedicated query services for UI/reporting surfaces.

### 7. Naming and structure
- Name by responsibility and layer, not generic verbs.
- Avoid `Manager`, `Helper`, `Utils` unless the name is truly precise.
- Keep files calm, linear, and single-level in abstraction.

### 8. Testing requirements
- Every new application/infrastructure behavior needs tests.
- Kernel tests for DB/repository integration.
- Unit tests for mapping/normalization/business rules.
- Failing tests block deployment.

### 9. Operational safety
- No destructive commands without explicit user approval.
- Never edit contrib code directly; patch via composer patches when needed.
- Keep secrets out of VCS; inject through environment/settings.

### 10. Tooling and runtime discipline
- Use the repository runtime and wrappers by default.
- Do not assume host PHP matches the project runtime.
- For coding standards, use `./scripts/phpcs.sh`.
- For PHPUnit, use `ddev exec ./vendor/bin/phpunit ...` unless the repository provides a narrower wrapper.
- If a tool fails because of missing PHP extensions on the host, rerun it through the project runtime before drawing conclusions.

### 11. Incremental SOLID refactoring
- Apply SOLID incrementally where it improves clarity, testability, and change safety.
- Do not perform broad speculative refactors without a clear need.
- Be especially strict about SRP: each class should have one clear responsibility and one obvious reason to change.
- Name modules, classes, methods, and variables by intent.
- Prefer small, explicit abstractions when they remove ambiguity or make testing easier.

## Definition of Done for new work
- Boundaries are explicit and respected.
- Interfaces and adapters are coherent.
- Tests cover the changed behavior.
- Docs/architecture notes updated.
- No unrelated files changed in the commit.
