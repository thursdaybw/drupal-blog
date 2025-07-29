# Headless JSON:API User Context

**Exposes the current user’s identity and context to JavaScript frontends via a stable, minimal JSON endpoint.**

This module is designed for headless Drupal sites that need to provide authenticated user context to a frontend application — such as React, Vue, or Svelte — without relying on assumptions like user ID `1` or exposing the full user entity.

## What It Does

Provides a single, safe JSON endpoint:

```
GET /jsonapi/me

````

Returns:
```json
{
  "uid": 42,
  "name": "johndoe",
  "display_name": "John Doe"
}
````

The endpoint respects the current session — no tokens, no extra setup — just `credentials: 'include'` in your frontend fetch.

## Why This Module

Drupal’s built-in JSON\:API module is powerful, but it doesn’t include a simple way to ask:

> "Who is the currently logged-in user?"

* Drupal’s global JS settings (`drupalSettings`) are **not available** in decoupled apps.
* Building custom logic in every project creates fragmentation.

This module gives you a clean and composable foundation for:

* Displaying login status
* Showing personalized content
* Toggling frontend features based on roles
* Bootstrapping more advanced user-aware API endpoints

## Planned / Suggested Extensions

This module is intentionally minimal, but may grow to include:

* Current user’s roles or permissions
* CSRF token passthrough
* Flags like `onboarding_complete`, `has_notifications`, etc.
* Optional integration with React or Vue environments

If you need these now, you can extend this module easily with your own controller or services.

## Installation

Standard Drupal module installation:

```
composer require drupal/headless_jsonapi_user_context
```

Then enable it:

```
drush en headless_jsonapi_user_context
```

or via the admin UI.

## Requirements

* Drupal 10+
* JSON\:API module enabled (core)
* A working session/cookie-based authentication setup

## Security

This module does **not** expose any sensitive information. The endpoint only returns basic user identity for the currently authenticated session. Anonymous users receive a 403 or a `null` result.
