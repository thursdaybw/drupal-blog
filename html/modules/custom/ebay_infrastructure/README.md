# eBay Infrastructure

Low-level eBay plumbing for the rest of the system.

What belongs here
- auth and token refresh
- raw eBay API clients
- low-level media upload helpers
- shared test support for eBay-facing code

What does not belong here
- generic listing publishing rules
- local mirror tables
- audit/report screens

## Shared eBay test pattern

When a test needs to prove eBay behaviour without calling the real network:

1. use the real `SellApiClient`
2. put a fake recorded HTTP client underneath it
3. queue fake JSON responses
4. assert on the captured outbound requests

Shared support class:
- `Drupal\\Tests\\ebay_infrastructure\\Support\\RecordedHttpClient`

Why this pattern exists
- it keeps the eBay adapter tests realistic
- it avoids the real eBay API
- it gives us one shared fake instead of many hand-rolled ones

Modules already using this pattern
- `ebay_connector`
- `bb_ebay_mirror`
