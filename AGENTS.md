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

### 12. Strict SRP at method level
- Treat SRP as a method-level rule, not just a class-level aspiration.
- Split control flow into small, named methods whenever a block starts doing more than one thing.
- Prefer granular methods that each answer one plain-English question, such as:
  - "Is this failure retryable?"
  - "Record the successful stop outcome."
  - "Wait before asking the provider for a state change."
  - "Keep this failed record eligible for the next reap."
- Avoid long methods that mix provider I/O, retry policy, state mutation, result formatting, and logging.
- A method should usually sit at one level of abstraction. Do not mix low-level API mechanics with high-level orchestration in the same method.
- If a comment is needed to explain a subsection inside a method, strongly consider extracting that subsection into a named method and putting the explanation on that method.

### 13. Pedagogical comments and docblocks
- Comments must explain why the code exists, what operational constraint it protects, or what future maintainers must not accidentally break.
- Do not write comments that merely repeat the code in English.
- Public and important private methods should have human-readable docblocks when the reason for the method is not obvious from the name alone.
- For edge cases, retries, provider quirks, cleanup code, safety guards, and money-costing operations, add plain-text pedagogical comments that explain:
  - the external behavior or failure mode being defended against;
  - why the chosen state transition is safe;
  - what would go wrong if the guard, retry, or delay were removed.
- Comments should be useful to a tired human reading production code during an incident.
- Prefer comments like:
  - "A Vast 429 means the stop request was throttled, not that the instance is unusable. Keep it available so the next cron pass still sees an expensive live instance to stop."
- Avoid comments like:
  - "Set lease_status to available."

## Definition of Done for new work
- Boundaries are explicit and respected.
- Interfaces and adapters are coherent.
- SRP has been checked at class and method level, with mixed responsibilities extracted into named methods.
- Why-based comments/docblocks explain non-obvious edge cases, provider quirks, retries, cleanup, safety guards, and cost controls.
- Tests cover the changed behavior.
- Docs/architecture notes updated.
- No unrelated files changed in the commit.
