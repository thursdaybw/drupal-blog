# Reconcile bb-ai-listing fork divergence against host ai_listing module

Date opened: 2026-04-28
Owner: bevan
Status: Backlog

## Context

The long-term direction is to split AI listing work out of the current bevansbench.com host. A `bb-ai-listing` fork already exists and may have started to diverge from the host `html/modules/custom/ai_listing` module.

## Problem

Now that production is stable, divergence between the fork and the host module needs to be made explicit. Otherwise bug fixes, bulk-intake improvements, and inference changes may land in one place and be missed in the other.

## Acceptance criteria

- [ ] Identify the current source-of-truth branches/commits for the host module and the fork.
- [ ] Compare module code, config assumptions, tests, and demo harness behaviour.
- [ ] List changes present only in the host module.
- [ ] List changes present only in the fork.
- [ ] Decide what should be backported, forward-ported, or intentionally left divergent.
- [ ] Update kanban cards 17/18/21 with any concrete extraction or parity tasks discovered.
- [ ] Document the near-term rule for where new AI listing work should land.

## Links

- `docs/kanban/backlog/17-define-a-separate-dev-environment-for-the-bb-ai-listing-product.md`
- `docs/kanban/backlog/18-stand-up-a-standalone-bb-ai-listing-dev-environment-with-docker-compose.md`
- `docs/kanban/backlog/21-adopt-standalone-bulk-image-intake-architecture-in-host-site-until-retirement.md`
