<?php

namespace Iperstudio\Impersonate;

use InvalidArgumentException;
use Kirby\Cms\App;
use Kirby\Cms\User;
use Kirby\Http\Response;
use Kirby\Http\Route;
use WeakMap;

final class Impersonate
{
    public const OPTION = 'iperstudio.impersonate-plugin.';
    private static ?WeakMap $contexts = null;

    /** Replace the final $kirby->render() call in the site's front controller. */
    public static function render(App $kirby): ?Response
    {
        $selector = $kirby->request()->query()->get('as');
        if ($selector === null) {
            return $kirby->render();
        }
        if (!$kirby->option(self::OPTION . 'enabled', true)) {
            return self::error('Account impersonation is disabled.', 403);
        }
        $admin = $kirby->user(null, false);
        if ($admin?->role()->name() !== 'admin') {
            return self::error('Admin access required.', 403);
        }
        if (!is_string($selector) || trim($selector) === '') {
            return self::error('Enter an account ID or email address.', 400);
        }

        // Resolve only real accounts: never pass the special "kirby"/"nobody"
        // identifiers directly to Kirby's impersonate() method.
        $selector = trim($selector);
        $target = $kirby->users()->find($selector);
        if (!$target) {
            foreach ($kirby->users() as $user) {
                if (strcasecmp($user->email(), $selector) === 0) {
                    $target = $user;
                    break;
                }
            }
        }
        if (!$target || $target->id() === 'kirby' || $target->id() === 'nobody') {
            return self::error('Account not found.', 404);
        }
        if (self::blocked($kirby, $admin, $target)) {
            return self::error('This action is disabled while impersonating an account.', 403);
        }

        self::$contexts ??= new WeakMap();
        $previous = self::$contexts[$kirby] ?? null;
        self::$contexts[$kirby] = ['admin' => $admin, 'target' => $target];
        $kirby->response()->cache(false);
        $kirby->response()->header('Cache-Control', 'private, no-store');
        $kirby->response()->header('Referrer-Policy', 'no-referrer');
        $kirby->response()->header('X-Robots-Tag', 'noindex, nofollow');

        try {
            // Include routing, controllers, templates and response conversion
            // in the callback, so all of them see the selected account.
            return $kirby->impersonate($target->id(), fn () => $kirby->render());
        } finally {
            if ($previous !== null) {
                self::$contexts[$kirby] = $previous;
            } else {
                unset(self::$contexts[$kirby]);
            }
        }
    }

    public static function target(?App $kirby = null): ?User
    {
        $kirby ??= App::instance();
        return self::$contexts[$kirby]['target'] ?? null;
    }

    public static function admin(?App $kirby = null): ?User
    {
        $kirby ??= App::instance();
        return self::$contexts[$kirby]['admin'] ?? null;
    }

    public static function visible(?App $kirby = null): bool
    {
        $kirby ??= App::instance();
        return (bool)$kirby->option(self::OPTION . 'enabled', true)
            && (self::target($kirby) !== null || $kirby->user(null, false)?->role()->name() === 'admin');
    }

    /** Return the current URL with only the impersonation selector removed. */
    public static function exitUrl(App $kirby): string
    {
        $query = $kirby->request()->query()->toArray();
        unset($query['as']);
        $url = $kirby->request()->url(['query' => null, 'fragment' => null])->toString();
        return $url . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
    }

    /** Explicitly preserve the target for custom redirects or generated URLs. */
    public static function url(string $url, ?App $kirby = null): string
    {
        $kirby ??= App::instance();
        $target = self::target($kirby);
        if (!$target) return $url;
        $parsed = parse_url($url);
        if ($parsed === false || str_starts_with($url, '#')) return $url;
        if (isset($parsed['scheme']) && !in_array(strtolower($parsed['scheme']), ['http', 'https'], true)) return $url;
        if (isset($parsed['host'])) {
            $index = parse_url($kirby->url('index'));
            $scheme = strtolower($parsed['scheme'] ?? $index['scheme'] ?? 'http');
            $indexScheme = strtolower($index['scheme'] ?? 'http');
            if (strtolower($parsed['host']) !== strtolower($index['host'] ?? '') || $scheme !== $indexScheme
                || ($parsed['port'] ?? ($scheme === 'https' ? 443 : 80)) !== ($index['port'] ?? ($indexScheme === 'https' ? 443 : 80))) return $url;
        }
        $parts = explode('#', $url, 2);
        $base = explode('?', $parts[0], 2);
        parse_str($base[1] ?? '', $query);
        $query['as'] = $target->id();
        return $base[0] . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)
            . (isset($parts[1]) ? '#' . $parts[1] : '');
    }

    public static function blockedMethods(App $kirby): array
    {
        $methods = $kirby->option(self::OPTION . 'blockedMethods', []);
        if (!is_array($methods)) throw new InvalidArgumentException('blockedMethods must be an array.');
        $result = [];
        foreach ($methods as $method) {
            if (!is_string($method) || !preg_match('/^[A-Za-z]+$/D', $method)) {
                throw new InvalidArgumentException('blockedMethods must contain HTTP method names.');
            }
            $result[] = strtoupper($method);
        }
        if ($kirby->option(self::OPTION . 'readOnly', false)) {
            $result = array_merge($result, ['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE']);
        }
        return array_values(array_unique($result));
    }

    private static function blocked(App $kirby, User $admin, User $target): bool
    {
        $path = trim($kirby->path(), '/');
        $relative = self::withoutLanguage($kirby, $path);
        $method = strtoupper($kirby->request()->method());

        // The frontend integration never impersonates Kirby's Panel or API.
        foreach ([$kirby->option('panel.slug', 'panel'), $kirby->option('api.slug', 'api')] as $reserved) {
            if (!is_string($reserved) || $reserved === '') continue;
            foreach ([$path, $relative] as $candidate) {
                if ($candidate === $reserved || str_starts_with($candidate, $reserved . '/')) return true;
            }
        }
        if (($kirby->option(self::OPTION . 'readOnly', false) && !in_array($method, ['GET', 'HEAD'], true))
            || in_array($method, self::blockedMethods($kirby), true)) {
            return true;
        }

        $rules = $kirby->option(self::OPTION . 'blockedRoutes', []);
        if (!is_array($rules)) throw new InvalidArgumentException('blockedRoutes must be an array.');
        foreach ($rules as $rule) {
            if (is_string($rule)) $rule = ['pattern' => $rule];
            if (!is_array($rule) || !isset($rule['pattern']) || !is_string($rule['pattern'])) {
                throw new InvalidArgumentException('Each blockedRoutes entry requires a string pattern.');
            }
            $methods = $rule['methods'] ?? ['ALL'];
            if (is_string($methods)) $methods = [$methods];
            if (!is_array($methods)) throw new InvalidArgumentException('Route methods must be a string or array.');
            foreach ($methods as $value) {
                if (!is_string($value)) throw new InvalidArgumentException('Route methods must be strings.');
            }
            $methods = array_map('strtoupper', $methods);
            if (!in_array('ALL', $methods, true) && !in_array($method, $methods, true)) continue;
            $route = new Route($rule['pattern'], 'ALL', static fn () => null);
            if ($route->parse($route->pattern(), $path) !== false
                || $route->parse($route->pattern(), $relative) !== false) return true;
        }

        $callback = $kirby->option(self::OPTION . 'block');
        if ($callback !== null && !is_callable($callback)) {
            throw new InvalidArgumentException('block must be a callable or null.');
        }
        return $callback !== null && (bool)$callback($kirby, $admin, $target);
    }

    private static function withoutLanguage(App $kirby, string $path): string
    {
        $base = trim((string)parse_url($kirby->url('index'), PHP_URL_PATH), '/');
        foreach ($kirby->languages() as $language) {
            $prefix = trim($language->path(), '/');
            if ($base !== '' && str_starts_with($prefix, $base . '/')) {
                $prefix = substr($prefix, strlen($base) + 1);
            }
            if ($prefix !== '' && ($path === $prefix || str_starts_with($path, $prefix . '/'))) {
                return ltrim(substr($path, strlen($prefix)), '/');
            }
        }
        return $path;
    }

    private static function error(string $message, int $code): Response
    {
        return new Response($message, 'text/plain', $code, [
            'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
