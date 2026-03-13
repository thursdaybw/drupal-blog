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

## Product Delivery Direction (Current)
- Primary objective is internal business value, fast.
- Delivery surface is Drupal admin UI first, optimized mobile-first for phone-based pick/pack use.
- Frontend architecture is intentionally deferred (Drupal UI now; HTMX or standalone frontend later).
- Keep backend use-cases and read models stable so UI can be swapped without rewriting domain/application logic.

## Mobile-First UI Direction (Pick/Pack)
- Current table view is retained for desktop, but mobile is the primary operational surface.
- Mobile rendering should use stacked order-line cards instead of wide tables.
- Card priority order:
  1. `storage_location` (large/bold) and `quantity`
  2. listing title + SKU
  3. order ID + buyer + external statuses
- Touch-first controls:
  - per-row actions: `Picked`, `Packed`, `Label Purchased`, `Dispatched`
  - top-level filters: actionable/all toggle, marketplace, quick search (location/SKU/order ID)
- Controller should select presentation mode by viewport/context while using the same read model service.
- No policy logic in UI layer; all state transitions remain in application use-cases.

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
  - `status` (normalized external status used by application logic)

## Authority and State Policy
- Internal warehouse workflow is authoritative for operations.
- Marketplace status is an external signal, not command authority.
- We retain both:
  - Internal workflow state (to be added): `warehouse_status`
  - External marketplace state (already stored): `payment_status`, `fulfillment_status`, `status`
- Reconciliation rules determine how external state influences internal state, instead of direct overwrite.

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
- [x] Implement `BuildPickPackQueue` query/use case.
- [x] Resolve order lines -> listing -> current storage location.
- [x] Build admin table view for pick/pack.
- [ ] Add filter/sort by status, age, location.
- [x] Add tests for location resolution and edge cases.

### Phase 3: Workflow actions
- [x] Add internal actions: Picked, Packed, Label Purchased, Dispatched.
- [x] Persist audit timestamps and actor IDs.
- [x] Ensure actions are idempotent and monotonic.
- [x] Add tests for status transitions.

### Phase 2.5: Mobile UX Hardening
- [ ] Add mobile card layout for pick/pack queue.
- [ ] Keep desktop table view as secondary presentation.
- [ ] Add touch-sized action controls for workflow transitions.
- [ ] Add mobile-first filter/search controls.

### Phase 3.5: Shipping Label Integration
- [ ] Keep current operational path: labels purchased externally (MyPost Business).
- [ ] Store shipping facts in-platform: `label_purchased_at`, `carrier`, `tracking_number`, `shipping_source`.
- [ ] Add manual update UI for label/tracking facts.
- [ ] Evaluate marketplace-agnostic shipping adapter boundary for future APIs/CSV import.

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
- Read model:
  - `src/Service/PickPackQueueQueryService.php`
  - `src/Model/PickPackQueueRow.php`
  - `src/Model/PickPackQueueResult.php`
- Persistence adapter:
  - `src/Infrastructure/DatabaseMarketplaceOrderRepository.php`
- eBay adapter:
  - `src/Infrastructure/Ebay/EbayMarketplaceOrdersPort.php`
  - `src/Infrastructure/Ebay/EbayOrderPayloadMapper.php`
- Drush:
  - `src/Command/MarketplaceOrdersCommand.php`
- Admin UI:
  - `src/Controller/PickPackQueueController.php`
  - `marketplace_orders.routing.yml`
  - `marketplace_orders.links.menu.yml`
- Schema:
  - `marketplace_order`
  - `marketplace_order_line`
  - `marketplace_order_sync_state`
