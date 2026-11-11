<?php

ob_start();
$bootstrap = getenv('KIRBY_BOOTSTRAP') ?: dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($bootstrap)) {
    throw new RuntimeException('Install development dependencies or set KIRBY_BOOTSTRAP to a Kirby 5 Composer autoloader.');
}
require $bootstrap;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;

$testRoot = sys_get_temp_dir() . '/iperstudio-impersonate-' . bin2hex(random_bytes(6));
function fixtureFile(string $path, string $content): void
{
    Dir::make(dirname($path));
    file_put_contents($path, $content);
}
// Load a real installed copy through Kirby's plugin discovery on each request.
foreach (['index.php', 'Impersonate.php', 'composer.json', 'snippets/head.php', 'snippets/toolbar.php', 'assets/impersonate.css', 'assets/impersonate.js'] as $file) {
    fixtureFile($testRoot . '/site/plugins/impersonate-plugin/' . $file, file_get_contents(dirname(__DIR__) . '/' . $file));
}
foreach (['dealer', 'distributor', 'unverified'] as $role) {
    fixtureFile($testRoot . '/site/blueprints/users/' . $role . '.yml', 'title: ' . ucfirst($role));
}
foreach (['home', 'orders/X1wHFp3GpmdN'] as $page) {
    foreach (['en', 'it'] as $language) {
        fixtureFile($testRoot . '/content/' . $page . '/default.' . $language . '.txt', 'Title: Fixture');
    }
}
fixtureFile($testRoot . '/site/controllers/default.php', <<<'PHP'
<?php
return function ($kirby) {
    return ['controllerAccount' => $kirby->user()?->id() ?? 'guest'];
};
PHP);
fixtureFile($testRoot . '/site/templates/default.php', <<<'PHP'
<!doctype html>
<html><head><?php snippet('impersonate/head') ?></head><body>
<?php snippet('impersonate/toolbar') ?>
<main><?= esc($controllerAccount) ?>|<?= esc($kirby->user()?->role()->name() ?? 'guest') ?>|<?= esc($kirby->language()?->code() ?? '') ?></main>
</body></html>
PHP);

$GLOBALS['testActions'] = [];
function app(?string $actor = 'administrator', mixed $as = 'dealer', array $options = [], string $path = 'en', string $method = 'GET', array $query = [], array $body = [], bool $multilang = true): App
{
    global $testRoot;
    App::destroy();
    // Kirby discovers the installed copy on the first simulated request.
    // PHP's include_once cache survives subsequent App instances in this test.
    if (class_exists('Iperstudio\\Impersonate\\Impersonate', false)) {
        require $testRoot . '/site/plugins/impersonate-plugin/index.php';
    }
    if ($as !== null) $query['as'] = $as;
    $routes = [
        [
            'pattern' => 'orders/(:any)/(approve|refuse)',
            'language' => '*',
            'method' => 'ALL',
            'action' => function ($language, $order, $action) {
                $GLOBALS['testActions'][] = [kirby()->user()?->id(), $order, $action, kirby()->request()->data()];
                return new Kirby\Http\Response('action:' . kirby()->user()?->id());
            },
        ],
        [
            'pattern' => 'ajax/(:any)', 'method' => 'ALL',
            'action' => fn ($name) => new Kirby\Http\Response('ajax:' . kirby()->user()?->id() . ':' . $name),
        ],
        [
            'pattern' => 'explode', 'method' => 'ALL',
            'action' => function () { throw new RuntimeException('fixture failure'); },
        ],
        [
            'pattern' => 'redirect',
            'action' => fn () => Kirby\Http\Response::redirect(Iperstudio\Impersonate\Impersonate::url('/subsite/en/orders?x=1#info')),
        ],
    ];
    $defaults = [
        'debug' => true,
        'languages' => $multilang,
        'languages.detect' => false,
        'routes' => $routes,
    ];
    $app = new App([
        'roots' => ['index' => $testRoot],
        'urls' => ['index' => 'https://example.test/subsite'],
        'options' => array_replace($defaults, $options),
        'path' => $path,
        'request' => [
            'url' => 'https://example.test/subsite/' . $path,
            'method' => $method,
            'query' => $query,
            'body' => $body,
        ],
        'languages' => $multilang ? [
            ['code' => 'en', 'default' => true, 'name' => 'English', 'url' => '/en'],
            ['code' => 'it', 'name' => 'Italiano', 'url' => '/it'],
        ] : null,
        'users' => [
            ['id' => 'administrator', 'email' => 'admin@example.test', 'role' => 'admin'],
            ['id' => 'dealer', 'email' => 'dealer@example.test', 'role' => 'dealer'],
            ['id' => 'distributor', 'email' => 'distributor@example.test', 'role' => 'distributor'],
            ['id' => 'unverified', 'email' => 'unverified@example.test', 'role' => 'unverified'],
        ],
    ]);
    if ($actor !== null) $app->auth()->setUser($app->user($actor));
    return $app;
}

$checks = 0;
function check(bool $condition, string $label): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($label);
    $checks++;
}

register_shutdown_function(static function () use ($testRoot) {
    App::destroy();
    Dir::remove($testRoot);
    if (ob_get_level() > 0) ob_end_flush();
});
