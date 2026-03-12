# Marketplace Orders Architecture (eBay First, Marketplace-Agnostic)

## Context
Today, the eBay SKU is carrying two responsibilities:

1. Marketplace inventory identity (should be stable).
2. Warehouse storage location hint (changes over time).

This coupling creates operational pain: moving location forces unpublish/delete/republish behavior because SKU is a primary key in eBay inventory APIs.

## Goal
Decouple marketplace identity from warehouse operations by importing marketplace orders into our own system and generating pick/pack views from internal data.

Result:

- SKU becomes stable and location-agnostic.
- Storage location becomes internal mutable data.
- Pick/pack workflow no longer depends on eBay UI fields.

## Glossary and Invariants
- **Marketplace SKU**: External marketplace inventory identity. Invariant: stable once published.
- **Storage Location**: Internal warehouse location code. Invariant: mutable operational metadata.
- **External Order ID**: Marketplace-native order key. Invariant: unique within marketplace.
- **Order Line**: One purchasable line under an order. Invariant: unique within external order.
- **Sync Watermark**: Timestamp boundary for incremental pulls. Invariant: monotonic non-decreasing.

## Architectural Boundaries

### Domain (pure business rules)
Entities/value objects:

- `Order`
- `OrderLine`
- `SellableItem`
- `StorageLocation`
- `FulfillmentAllocation`

Rules:

- SKU is immutable marketplace identity once published.
- Storage location is mutable internal state.
- Picking operates on `OrderLine -> SellableItem -> StorageLocation`.

Domain must be deterministic and side-effect free.

### Application (use-case orchestration)
Use cases:

- `SyncMarketplaceOrdersSince` (pull + normalize + upsert)
- `UpsertMarketplaceOrder` (idempotent)
- `BuildPickPackQueue`
- `MarkOrderPicked`
- `MarkOrderPacked`
- `MarkOrderShipped`

Application coordinates ports and repositories; no marketplace SDK code or Drupal UI code here.

### Infrastructure (adapters)
Adapters:

- `EbayOrdersGateway` (API calls)
- `EbayOrderPayloadMapper` (external JSON -> internal DTO)
- Repository implementations for persistence
- Scheduler/Drush command wiring

Future marketplaces plug in here via the same application port.

### Interface (admin UX/read models)
- Orders view
- Pick/pack queue view
- Location visibility in internal UI

UI consumes application read models; it does not call marketplace APIs directly.

## Ports and Dependency Direction
Inner layers define ports, outer layers implement them.

- `MarketplaceOrdersPort` (application-defined)
- `OrderRepositoryPort`
- `InventoryLocationReadPort`

Dependencies point inward only.

## Data Model (minimum first slice)
Selected persistence model: dedicated SQL tables in `marketplace_orders.install`.

- `marketplace_order`
  - `id`
  - `marketplace` (`ebay`)
  - `external_order_id` (unique with marketplace)
  - `status`
  - `ordered_at`
  - `buyer_handle` (optional)
  - `totals_json` (optional)
  - `payload_hash` (for change detection)
  - `created`
  - `changed`

- `marketplace_order_line`
  - `id`
  - `order_id`
  - `external_line_id`
  - `sku`
  - `quantity`
  - `title_snapshot`
  - `price_snapshot`
  - `listing_uuid` (nullable until resolved)
  - `created`
  - `changed`

- `pick_allocation` (can start as a read-time join; persist later if needed)
  - `order_line_id`
  - `listing_uuid`
  - `storage_location`
  - `picked_at`
  - `packed_at`
  - `shipped_at`

Required uniqueness:

- `(marketplace, external_order_id)` on `marketplace_order`
- `(order_id, external_line_id)` on `marketplace_order_line`

## SKU and Location Policy
- SKU is marketplace identity and must be treated as stable.
- Location is internal operational metadata and must never require SKU mutation.
- Existing “location encoded in SKU” stays temporarily for backward compatibility.
- New flow removes this dependency once pick/pack UI is trusted.

## Operational Flow
1. Scheduler or command requests orders since watermark.
2. Gateway pulls marketplace pages.
3. Mapper converts to internal DTOs.
4. Application use case performs idempotent upsert.
5. Pick/pack read model resolves each order line to current internal location.
6. Warehouse uses internal UI, not eBay SKU/location hacks.

## Ingestion Policy
- Ingest all marketplace orders into local storage (history + audit fidelity).
- Do not filter ingest based on operational state.
- Apply operational filters in read models/views only (for example pick/pack queue).
- Preserve source status dimensions separately:
  - `payment_status`
  - `fulfillment_status`
  - `status` (normalized workflow status used by application logic)

## Roadmap (Checklist)

### Phase 0: Foundations
- [x] Define glossary and invariants (SKU immutability, location mutability).
- [x] Create application ports (`MarketplaceOrdersPort`, repository ports).
- [x] Decide persistence shape (Drupal content entities vs dedicated tables).

### Phase 1: eBay ingestion core
- [x] Implement `EbayOrdersGateway` adapter.
- [x] Implement `EbayOrderPayloadMapper`.
- [x] Implement `UpsertMarketplaceOrder` (idempotent).
- [x] Add unique constraints for idempotency.
- [x] Add `drush` command: `marketplace-orders:sync --marketplace=ebay --since=<iso8601>`.
- [x] Add kernel tests for insert/update/idempotency.

### Phase 2: Internal pick/pack read model
- [ ] Implement `BuildPickPackQueue` query/use case.
- [ ] Resolve order lines -> listing -> current storage location.
- [ ] Build admin table view for pick/pack.
- [ ] Add filter/sort by status, age, location.
- [ ] Add tests for location resolution and edge cases.

### Phase 3: Workflow actions
- [ ] Add actions: Picked, Packed, Shipped.
- [ ] Persist audit timestamps and actor IDs.
- [ ] Ensure actions are idempotent and monotonic.
- [ ] Add tests for status transitions.

### Phase 4: Decouple location from SKU
- [ ] Stop requiring location in newly generated SKU values.
- [ ] Keep legacy SKU support for existing live inventory.
- [ ] Add migration/compat handling where needed.
- [ ] Update documentation and operator runbook.

### Phase 5: Marketplace abstraction hardening
- [ ] Verify no eBay-specific assumptions leak into domain/application.
- [ ] Add contract tests around `MarketplaceOrdersPort`.
- [ ] Add placeholder adapter scaffold for second marketplace.

## Definition of Done (per phase)
- [ ] Use cases are covered by automated tests.
- [ ] No domain/application imports from infrastructure/UI layers.
- [ ] Operator docs updated.
- [ ] Rollback strategy documented for schema changes.

## Open Decisions
- [x] Content Entity vs dedicated SQL tables for orders.
- [ ] Polling cadence and watermark storage strategy.
- [ ] Whether to snapshot location at order time or always resolve live location.
- [ ] Multi-quantity allocation policy for bundle/multi-location inventory.

## Current Implementation Surface (Phase 0)
- Module: `html/modules/custom/marketplace_orders`
- Contracts:
  - `src/Contract/MarketplaceOrdersPortInterface.php`
  - `src/Contract/MarketplaceOrderRepositoryInterface.php`
  - `src/Contract/MarketplaceOrderSyncStateRepositoryInterface.php`
  - `src/Contract/InventoryLocationReadPortInterface.php`
- Application use case:
  - `src/Service/SyncMarketplaceOrdersSinceService.php`
- Persistence adapter:
  - `src/Infrastructure/DatabaseMarketplaceOrderRepository.php`
- eBay adapter:
  - `src/Infrastructure/Ebay/EbayMarketplaceOrdersPort.php`
  - `src/Infrastructure/Ebay/EbayOrderPayloadMapper.php`
- Drush:
  - `src/Command/MarketplaceOrdersCommand.php`
- Schema:
  - `marketplace_order`
  - `marketplace_order_line`
  - `marketplace_order_sync_state`
