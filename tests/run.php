<?php

require __DIR__ . '/bootstrap.php';

use Iperstudio\Impersonate\Impersonate;

app(as: null);
$prefix = Impersonate::OPTION;

foreach (['dealer', ' dealer@example.test ', 'DEALER@EXAMPLE.TEST', 'distributor', 'unverified', 'administrator'] as $selector) {
    $app = app(as: $selector);
    $response = Impersonate::render($app);
    $html = $response->body();
    check($response->code() === 200, 'Existing accounts must render');
    check(str_contains($html, 'data-impersonate-state="active"'), 'The active toolbar must be rendered');
    check(str_contains($html, 'data-impersonate-script'), 'The active browser script must load');
    check(!str_contains($html, 'data-impersonate-controls'), 'No selector inside active impersonation');
    check(!str_contains($html, 'class='), 'Plugin UI must not require CSS classes');
    check(!str_contains($html, 'data-impersonate-notice'), 'Default mode must not claim to be read-only');
    check($response->header('Cache-Control') === 'private, no-store', 'No cached impersonated responses');
    check($response->header('Referrer-Policy') === 'no-referrer', 'Do not leak selector in referrers');
    check($app->user()->id() === 'administrator' && $app->user(null, false)->id() === 'administrator', 'Restore and preserve the actual admin');
    check(Impersonate::target($app) === null && Impersonate::admin($app) === null, 'Clear request context');
}
$app = app(as: 'dealer');
check(str_contains(Impersonate::render($app)->body(), '<main>dealer|dealer|en</main>'), 'Controller and template must see target identity and language');
$app = app(as: 'distributor', path: 'it');
check(str_contains(Impersonate::render($app)->body(), '<main>distributor|distributor|it</main>'), 'Preserve multilingual routing');
foreach ([null, 'dealer', 'distributor', 'unverified'] as $actor) {
    $app = app(actor: $actor);
    check(Impersonate::render($app)->code() === 403, 'Only the real admin can impersonate');
}
$app = app(actor: 'dealer');
$app->impersonate('kirby');
check(Impersonate::render($app)->code() === 403, 'A raised virtual admin must not bypass actual authentication');
foreach (['missing', 'kirby', 'nobody'] as $selector) {
    $app = app(as: $selector);
    check(Impersonate::render($app)->code() === 404, 'Reject missing and virtual accounts');
}
foreach (['', ' ', ['dealer']] as $selector) {
    $app = app(as: $selector);
    check(Impersonate::render($app)->code() === 400, 'Reject empty and malformed selectors');
}
$app = app(options: [$prefix . 'enabled' => false]);
check(Impersonate::render($app)->code() === 403, 'Disabled integration must reject selectors');
foreach ([null, 'dealer', 'administrator'] as $actor) {
    $app = app(actor: $actor, as: null);
    $html = Impersonate::render($app)->body();
    check(str_contains($html, 'data-impersonate-controls') === ($actor === 'administrator'), 'Only admin sees the idle selector');
    check(!str_contains($html, 'data-impersonate-script'), 'Do not patch browser requests outside impersonation');
}
$app = app(as: null, query: ['q' => '"><script>alert(1)</script>', 'filters' => ['first', 'second']]);
$html = Impersonate::render($app)->body();
check(!str_contains($html, '<script>alert(1)</script>'), 'Escape hidden query values');
$decoded = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
check(str_contains($decoded, 'name="filters[0]"') && str_contains($decoded, 'name="filters[1]"'), 'Preserve array query filters');
$app = app(options: [$prefix . 'styles' => false]);
$html = Impersonate::render($app)->body();
check(!str_contains($html, 'data-impersonate-styles') && str_contains($html, 'data-impersonate-script'), 'Styles can be disabled without disabling navigation');
$app = app(query: ['q' => 'hello world', 'page' => '3']);
$html = Impersonate::render($app)->body();
check(str_contains(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'q=hello%20world&page=3'), 'Exit URL preserves other query parameters');
check(!preg_match('/data-impersonate-exit[^>]*as=|href="[^"]*as=[^"]*" data-impersonate-exit/', $html), 'Exit link removes impersonation');

// Real actions are enabled by default, with their existing request body intact.
foreach (['approve', 'refuse'] as $action) {
    $app = app(path: 'en/orders/X1wHFp3GpmdN/' . $action, method: 'POST', body: ['csrf' => 'fixture-token', 'note' => 'Review']);
    $response = Impersonate::render($app);
    check($response->body() === 'action:dealer', 'Allowed actions use the target account');
    $record = end($GLOBALS['testActions']);
    check($record[3]['note'] === 'Review' && $record[3]['csrf'] === 'fixture-token', 'Do not rewrite request bodies or CSRF tokens');
    check($app->user()->id() === 'administrator', 'Restore admin after an allowed action');
}
foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
    $app = app(options: [$prefix . 'readOnly' => true], path: 'en/orders/abc/approve', method: $method);
    $before = count($GLOBALS['testActions']);
    check(Impersonate::render($app)->code() === 403, 'Read-only must block non-read methods on the server');
    check(count($GLOBALS['testActions']) === $before, 'Blocked actions must not execute');
}
$app = app(options: [$prefix . 'readOnly' => true]);
check(str_contains(Impersonate::render($app)->body(), 'data-impersonate-notice'), 'Read-only notice follows configuration');
foreach (['POST' => 403, 'GET' => 200] as $method => $code) {
    $app = app(options: [$prefix . 'blockedMethods' => ['post']], path: 'en/orders/abc/approve', method: $method);
    check(Impersonate::render($app)->code() === $code, 'Configured method blocks are case-insensitive');
}
foreach (['en', 'it'] as $language) {
    foreach (['GET' => 200, 'POST' => 403] as $method => $code) {
        $app = app(options: [$prefix . 'blockedRoutes' => [['pattern' => 'orders/(:any)/approve', 'methods' => 'POST']]], path: "$language/orders/abc/approve", method: $method);
        $before = count($GLOBALS['testActions']);
        check(Impersonate::render($app)->code() === $code, 'Match method-specific rules without language and base path');
        check(count($GLOBALS['testActions']) === $before + (int)($code === 200), 'Deny matching routes before execution');
    }
}
$app = app(options: [$prefix . 'blockedRoutes' => ['logout']], path: 'it/logout');
check(Impersonate::render($app)->code() === 403, 'String rules block all methods, including mutating GET routes');
$app = app(options: [$prefix . 'blockedRoutes' => ['it/orders/(:any)/approve']], path: 'it/orders/abc/approve');
check(Impersonate::render($app)->code() === 403, 'Explicitly language-prefixed rules also work');
$app = app(options: [$prefix . 'block' => static fn ($app, $admin, $target) => $admin->id() === 'administrator' && $target->id() === 'dealer' && $app->request()->get('action') === 'remove'], query: ['action' => 'remove']);
check(Impersonate::render($app)->code() === 403, 'Custom callback can inspect action parameters and identities');
foreach (['panel', 'panel/users', 'api/users', 'it/panel', 'it/api/pages'] as $path) {
    $app = app(path: $path);
    check(Impersonate::render($app)->code() === 403, 'Do not impersonate Kirby Panel or API');
}
$app = app(options: ['panel.slug' => 'admin-area', 'api.slug' => 'json-api'], path: 'admin-area/users');
check(Impersonate::render($app)->code() === 403, 'Respect custom Panel slug');
$app = app(path: 'ajax/status');
check(Impersonate::render($app)->body() === 'ajax:dealer:status', 'Custom AJAX routes see target identity');
$app = app(path: 'ajax/status', multilang: false);
check(Impersonate::render($app)->body() === 'ajax:dealer:status', 'Single-language sites support custom routes');
$app = app(path: 'redirect');
$response = Impersonate::render($app);
check($response->header('Location') === '/subsite/en/orders?x=1&as=dealer#info', 'URL helper preserves query and fragment in custom redirects');

// URL helpers are also usable while rendering project snippets/controllers.
$app = app();
$app->extend(['hooks' => ['page.render:before' => function ($data) use ($app) {
    check(Impersonate::target($app)?->id() === 'dealer' && Impersonate::admin($app)?->id() === 'administrator', 'Context exposes both identities during rendering');
    check(Impersonate::url('https://outside.test/page') === 'https://outside.test/page', 'Do not leak target to external links');
    check(Impersonate::url('mailto:help@example.test') === 'mailto:help@example.test', 'Do not alter non-web URL schemes');
    check(Impersonate::url('#info') === '#info', 'Leave fragment-only links alone');
    check(Impersonate::url('/orders?q=1&as=old#info') === '/orders?q=1&as=dealer#info', 'Replace existing selector without losing other URL parts');
    return $data;
}]]);
Impersonate::render($app);
$app = app(path: 'explode');
try {
    Impersonate::render($app);
    throw new LogicException('Expected fixture exception');
} catch (RuntimeException $e) {
    check($e->getMessage() === 'fixture failure', 'Preserve application exceptions');
}
check($app->user()->id() === 'administrator' && Impersonate::target($app) === null, 'Exceptions must restore identity and clear context');

foreach (['blockedMethods' => 'POST', 'blockedRoutes' => 'logout', 'block' => 'not-a-function'] as $name => $value) {
    $app = app(options: [$prefix . $name => $value]);
    try {
        Impersonate::render($app);
        throw new LogicException('Expected invalid configuration exception');
    } catch (InvalidArgumentException) {
        check($app->user()->id() === 'administrator', 'Invalid configuration must fail before impersonation');
    }
}
echo "OK: $checks integration checks passed with Kirby " . Kirby\Cms\App::version() . ".\n";
