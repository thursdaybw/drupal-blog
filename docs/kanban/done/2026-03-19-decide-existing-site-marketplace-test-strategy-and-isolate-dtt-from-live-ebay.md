# Decide Existing-Site Marketplace Test Strategy And Isolate DTT From Live eBay

Date opened: 2026-03-19
Owner: bevan

Why:
- Existing-site DTT coverage is now valuable for operational workflow QA, but the current dev environment still talks to live eBay.
- That makes lifecycle and marketplace tests more expensive, riskier, and harder to run routinely.
- The project needed a deliberate strategy: eBay sandbox, an internal marketplace stub, or a documented split between safe stubbed tests and deliberate live smoke tests.

Definition of done:
- [x] Decide and document the testing strategy for marketplace-backed existing-site tests.
- [x] Choose the default path for routine regression coverage.
- [x] Decide the role of live eBay tests.
- [x] Spin out implementation follow-up cards for the chosen strategy.

Decision:
- Use an internal eBay marketplace stub for routine regression tests.
- Do not use eBay sandbox as the primary testing path.
- Keep a small, deliberate live eBay smoke-test suite for real integration confidence.

Why this decision:
- An internal stub gives deterministic, fast, repeatable test behavior.
- eBay sandbox is less trustworthy than production and less controllable than a stub.
- Live eBay still has value, but only for a deliberately small smoke suite.

Evidence:
- Decision made on 2026-03-25 after reviewing the three options against current project constraints.
- Follow-up cards opened:
- `docs/kanban/backlog/10-build-an-ebay-marketplace-stub-for-routine-regression-tests.md`
- `docs/kanban/backlog/11-migrate-existing-site-marketplace-tests-to-the-ebay-stub.md`
- `docs/kanban/backlog/12-define-a-small-live-ebay-smoke-test-suite.md`

