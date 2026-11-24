# Changelog

## 1.0.0 — 2026-11-24

- Support Kirby 6 alongside Kirby 5. Kirby 6 requires PHP 8.3 or later.
- Document the Composer upgrade from version 0.1.x to 1.x.

## 0.1.0 — 2026-11-24

- Add request-scoped frontend impersonation for authenticated Kirby admins.
- Accept account IDs and email addresses through the `as` query parameter.
- Add an account toolbar, translations and optional default styles using data attributes.
- Preserve same-origin navigation, forms, fetch and XMLHttpRequest/HTMX requests.
- Support configured method/route/callback blocks and optional read-only mode.
- Keep normal application routing and permission checks; exclude the Kirby Panel and API.
- Add PHP integration and browser-script tests.
