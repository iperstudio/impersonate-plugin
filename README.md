# Iperstudio Impersonate Plugin

View a Kirby website as another existing account, without logging out of your admin account.

Use `?as=ACCOUNT_ID` or `?as=user%40example.com`. The selected account is used for the full frontend request: routes, controllers, templates and custom endpoints. Your application's existing permission checks continue to apply.

Supports **Kirby 5** (PHP 8.2+) and **Kirby 6** (PHP 8.3+). No frontend dependencies or asset build step.

## Installation

### Composer (Packagist)

From your Kirby project's root, run:

```sh
composer require 'iperstudio/impersonate-plugin:^1.0'
```

Run the same command to update an existing `0.1.x` installation to `1.x`.

Allow `getkirby/composer-installer` if Composer prompts you. The installer places the plugin in `site/plugins/impersonate-plugin` by default.

### Manual installation

Download and extract a tagged release, then place the extracted directory at `site/plugins/impersonate-plugin` in your Kirby project.

Use one installed copy of the plugin per project and follow the setup below after installation.

## Setup

### 1. Wrap the site's render call

In the front controller (`index.php`, or `public/index.php` in a public-folder setup), load the Kirby bootstrap and create the Kirby instance before calling the plugin. If your `index.php` currently ends with `echo (new Kirby)->render();`, change it to:

```php
<?php

use Kirby\Cms\App;

require 'kirby/bootstrap.php';

$kirby = new App();

echo \Iperstudio\Impersonate\Impersonate::render($kirby);
```

Keep the bootstrap path from your project. If your front controller already creates `$kirby`, keep its constructor options and other setup, and replace `echo $kirby->render();` with the last line above.

Kirby discovers the plugin when `new App()` runs. The explicit `Kirby\Cms\App` import lets static analyzers such as Intelephense resolve the class. If you see `Class "Iperstudio\Impersonate\Impersonate" not found`, check that the constructor runs before the wrapper and that `impersonate-plugin/index.php` exists in Kirby's plugins directory (`site/plugins` by default).

The wrapper uses Kirby's normal renderer and router. Requests without `as` use the normal renderer unchanged.

### 2. Include the resources

Inside `<head>`, before your application's scripts:

```php
<?php snippet('impersonate/head') ?>
```

The snippet loads the optional default CSS for admins. During impersonation it also loads the browser script synchronously, so it can preserve the target in requests made by later site scripts. Do not defer or asynchronously load this script.

### 3. Place the toolbar

Put this snippet once inside `<body>`, wherever the account controls should appear:

```php
<?php snippet('impersonate/toolbar') ?>
```

To see the toolbar, sign in to the Kirby Panel as a user with the `admin` role, then open the website on the same origin in the same browser profile. Outside impersonation it shows an account input and an **Open preview** button. During impersonation it shows the selected email, role and a **Back to admin view** link. It remains visible even when the target has no admin privileges.

The plugin does not add a Panel section or automatically inject markup into your layout.

## Usage

Enter an existing account ID or email in the toolbar, or add the selector to a URL:

```text
/en/orders/X1wHFp3GpmdN?as=ACCOUNT_ID
/en/orders/X1wHFp3GpmdN?as=dealer%40example.com
/en/orders?q=chair&as=ACCOUNT_ID
```

Leading/trailing spaces are removed, and email lookup is case-insensitive. Account IDs retain their original case. The special Kirby identities `kirby` and `nobody` cannot be selected.

There is no impersonation state in the login session. Normal tabs without `as` retain the admin view. Each request carrying `as` checks that the **actually authenticated account** has the `admin` role, then runs with the selected account. The original identity is restored after rendering, including when application code throws an exception.

The browser script maintains `as` on same-origin links, native forms, `fetch()` and XMLHttpRequest/HTMX requests. It uses the selected account ID after the initial email lookup. The exit link removes only `as`, preserving the current path and other query parameters.

## Actions and restrictions

**Actions are enabled by default.** If an impersonated account can approve an order, submit a form or edit its profile, those are real application actions. The plugin does not add confirmation dialogs or bypass your site's CSRF or permission checks.

Configure restrictions in `site/config/config.php`:

```php
return [
    'iperstudio.impersonate-plugin' => [
        'blockedMethods' => ['DELETE'],
        'blockedRoutes' => [
            'logout',
            [
                'pattern' => 'orders/(:any)/(approve-review|refuse-review)',
                'methods' => ['POST'],
            ],
        ],
    ],
];
```

`blockedRoutes` accepts Kirby route patterns. A string entry blocks all methods. An array entry has a required `pattern` and optional `methods` string or array; omitted methods mean `ALL`. Each pattern is checked against both the full request path and the path without the language prefix. Query strings are not part of route matching.

Blocked requests return **403 before the site's route or controller runs**. Rules apply only to requests carrying `as`; normal admin requests are unaffected. Invalid configuration raises an exception instead of silently ignoring restrictions.

### Read-only mode

```php
return [
    'iperstudio.impersonate-plugin' => [
        'readOnly' => true,
        'blockedRoutes' => ['logout'],
    ],
];
```

Read-only mode allows GET and HEAD and rejects other request methods. It also blocks write requests in the browser and adds a read-only note to the toolbar. **GET routes can still perform application actions** (logout is a common example); block those routes explicitly.

### Rules based on query parameters or submitted data

Use the optional callback when a path/method rule is not enough:

```php
return [
    'iperstudio.impersonate-plugin' => [
        'block' => function ($kirby, $admin, $target): bool {
            return $kirby->request()->get('action') === 'delete-account';
        },
    ],
];
```

The callback runs before impersonation: `$admin` is the authenticated administrator, and `$target` is the selected account. Return `true` to block the request. Method, route and callback restrictions are cumulative; a matching restriction cannot be overridden by returning `false` from the callback.

### Options

All options use the `iperstudio.impersonate-plugin` namespace:

| Option | Default | Purpose |
| --- | --- | --- |
| `enabled` | `true` | Enable the toolbar and impersonation; disabled selectors return 403. |
| `styles` | `true` | Load the plugin's default stylesheet. |
| `readOnly` | `false` | Permit only GET and HEAD while impersonating. |
| `blockedMethods` | `[]` | HTTP method names to block, case-insensitively. |
| `blockedRoutes` | `[]` | Kirby route patterns with optional method restrictions. |
| `block` | `null` | Optional `($kirby, $admin, $target): bool` callback. |

Kirby's own Panel and API paths are always excluded from impersonation, including custom `panel.slug` and `api.slug` values. This plugin targets the website frontend and its custom endpoints.

## Styling with data attributes

The UI contains **no CSS classes**. The default stylesheet uses low-specificity `:where()` selectors and can be overridden by your project CSS.

| Attribute | Element |
| --- | --- |
| `data-impersonate-toolbar` | Outer `<aside>`. |
| `data-impersonate-state="idle\|active"` | Current toolbar state. |
| `data-impersonate-controls` | Account selection form. |
| `data-impersonate-label` | Input label. |
| `data-impersonate-input` | Account ID/email input. |
| `data-impersonate-submit` | Open preview button. |
| `data-impersonate-active` | Active account row. |
| `data-impersonate-identity` | Account and role text. |
| `data-impersonate-notice` | Optional read-only explanation. |
| `data-impersonate-exit` | Return to admin link; also exempts it from URL rewriting. |

Defaults use an unboxed, wrapping flex layout with a 1rem margin on each side. The input is about 20rem wide. The buttons have a black background and keep their text on one line. The read-only explanation is hidden at widths of 1200px or less. The toolbar is hidden when printing.

Example overrides:

```css
[data-impersonate-toolbar] {
  --impersonate-gap: 0.5rem;
  --impersonate-border: #666;
  --impersonate-button-bg: #222;
  --impersonate-button-color: #fff;
  --impersonate-radius: 0.25rem;
  max-width: 75rem;
  margin-inline: auto;
  padding-inline: 1.5rem;
}

[data-impersonate-identity] {
  color: #fbbf24;
}
```

Set `styles` to `false` to provide all styling yourself. Keep the head snippet for navigation support. You can override `impersonate/toolbar` in your site's snippets directory; retain `data-impersonate-exit` on the exit link.

## Translations

English, Italian, French and Spanish UI strings are included. Override the `impersonate.*` translation keys in your site language definitions, for example:

```php
'translations' => [
    'impersonate.label' => 'View account',
    'impersonate.open' => 'Open',
    'impersonate.exit' => 'Exit preview',
],
```

## PHP helpers

```php
use Iperstudio\Impersonate\Impersonate;

$target = Impersonate::target(); // Selected User, or null outside impersonation
$admin = Impersonate::admin();   // Original User, or null outside impersonation

// Preserve the target for a custom internal URL or redirect:
$url = Impersonate::url($page->url() . '?action=approve#review');
go($url);
```

These helpers are available while the wrapped request is rendering. The URL helper preserves other query parameters and fragments, replaces an existing `as`, and leaves external URLs and non-HTTP schemes unchanged. Do not append `as` to external destinations yourself.

## Integration notes

- The plugin preserves the normal router, controller behavior, request body and CSRF tokens. Existing application permission/CSRF checks may therefore reject an action under the target account; that is not bypassed by this plugin.
- Application code that explicitly asks for `$kirby->user(null, false)` sees the actual admin. Use the usual `$kirby->user()` for target-based rendering and authorization.
- Keep sensitive endpoint authorization on the server. Hiding a section in a template does not authorize or protect its endpoint.
- JavaScript maintains the URL context; it is not an authorization boundary. With JavaScript disabled, custom navigation, third-party scripts or redirects that drop `as`, the next request is a normal admin request. Use `Impersonate::url()` in custom redirects and critical generated form actions. Restrictions apply only to requests carrying `as`, not to the admin's whole session.
- Read-only mode is not a database sandbox and cannot undo side effects in GET handlers. Configure the site's own mutating routes explicitly.
- The plugin itself does not change the login session. Application actions that call login/logout or change authentication can still affect it unless blocked.
- Responses carrying `as` use `Cache-Control: private, no-store`, `Referrer-Policy: no-referrer` and `X-Robots-Tag: noindex, nofollow`. Configure any CDN or reverse proxy to bypass these requests too. Avoiding email addresses in bookmarked/shared URLs also keeps them out of browser history and request logs; ID selectors work equally well.
- Direct calls to `$page->render()` outside the wrapped front controller are not automatically impersonated. Custom headless entry points need their own integration and authorization.

## Migrating an existing impersonation setup

Do not run the old wrapper/browser script and this plugin together. Replace the old front-controller wrapper with `Impersonate::render($kirby)`, use the plugin's head and toolbar snippets, and remove the old preview-specific script from the layout. The `as` URL format remains compatible.

To retain read-only behavior, configure `readOnly => true` and block GET action routes such as logout. Keep application-specific authorization rules in your site code.

## Development and tests

```sh
composer install
composer test
composer test-js
```

The PHP suite uses isolated temporary Kirby sites; it does not contact external services or alter a real site's accounts/content. The browser-script tests use Node's built-in test runner (Node 18+), with no npm installation required.

To run the integration suite against a specific Kirby 5 or 6 installation, point `KIRBY_BOOTSTRAP` to its Composer autoloader:

```sh
KIRBY_BOOTSTRAP=/absolute/path/to/project/vendor/autoload.php php tests/run.php
node --test tests/browser.test.cjs
```

The current test suite covers admin-only authorization, ID/email selection, native multilingual routing, controller/template identity, real allowed actions, configured method/route/callback blocks, context cleanup, URL escaping, default stylesheet opt-out and same-origin navigation/requests.

## License

MIT. See [LICENSE.md](LICENSE.md).
