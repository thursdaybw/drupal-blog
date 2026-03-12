# Marketplace Orders

## Purpose
Application boundary for marketplace order ingestion and internal pick-pack workflows.

## Layering
- `src/Contract`: application ports.
- `src/Model`: immutable application DTOs.
- `src/Service`: application use-cases.
- `src/Infrastructure`: default adapter implementations.

## Notes
The current wired marketplace adapter is eBay (`EbayMarketplaceOrdersPort`), and the module persists normalized orders into dedicated tables.

See: `docs/marketplace-orders-architecture.md`

## Drush
- `drush marketplace-orders:sync --marketplace=ebay --since=<iso8601|timestamp>`
