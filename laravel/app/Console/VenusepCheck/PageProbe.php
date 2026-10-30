<?php

namespace App\Console\VenusepCheck;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Routing\Route;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/* ONE request, through the HTTP kernel (no server), in a fresh session signed
   in as the role — and what is wrong with the answer. Never throws.

   A result is an array: route, role, url, status, redirect, ms, result, problems.
   problems: list of ['fail'|'warn', text]. result: ok | warn | fail | not_ported
   | needs_params | skipped. */
final class PageProbe
{
    public const PLACEHOLDER_TEXT = 'This page is not on Laravel yet';

    /* PHP / Laravel error output that must never reach a page */
    private const ERROR_PATTERNS = [
        '/\b(?:Warning|Notice|Deprecated|Fatal error|Parse error|Catchable fatal error)(?:<\/b>)?:\s/' => 'PHP error text',
        '/Undefined (?:variable|array key|index|offset|property)\b/' => 'PHP "undefined" error',
        '/Stack trace:/' => 'stack trace in the page',
        '/Uncaught [A-Z]\w*(?:Exception|Error)\b/' => 'uncaught exception text',
        '/SQLSTATE\[[0-9A-Z]{5}\]/' => 'SQL error in the page',
        '/Illuminate\\\\[A-Za-z\\\\]+Exception|Symfony\\\\Component\\\\ErrorHandler|laravel-exceptions-renderer|Whoops, looks like/' => 'Laravel error page',
    ];

    /* exceptions that are how a page says "go elsewhere" / "not for you" */
    private const CONTROL_FLOW = [
        HttpResponseException::class, ValidationException::class, AuthenticationException::class,
        AuthorizationException::class, TokenMismatchException::class, ModelNotFoundException::class,
        InvalidSignatureException::class,
    ];

    public static function run(string $name, Route $route, string $role, array $session, array $query, array $missing): array
    {
        $uri = '/' . ltrim($route->uri(), '/');
        $url = $uri . ($query ? '?' . http_build_query($query) : '');
        $base = ['route' => $name, 'role' => $role, 'url' => $url, 'status' => null, 'redirect' => null, 'ms' => 0.0];
        $problems = [];
        if ($route->parameterNames()) {
            return $base + ['result' => 'needs_params', 'problems' => [['warn', 'URL parameters {' . implode('}, {', $route->parameterNames()) . '}: cannot guess them']]];
        }

        $started = hrtime(true);
        try {
            [$response, $stray] = self::handle($url, $session);
        } catch (\Throwable $e) {
            $base['ms'] = round((hrtime(true) - $started) / 1e6, 1);
            return $base + ['result' => 'fail', 'problems' => [['fail', 'exception escaped the kernel: ' . self::describe($e)]]];
        }
        $base['ms'] = round((hrtime(true) - $started) / 1e6, 1);
        $status = $response->getStatusCode();
        $base['status'] = $status;
        if ($response->isRedirection()) {
            $base['redirect'] = self::localPath((string) $response->headers->get('Location'));
        }

        try {
            $body = self::body($response);
        } catch (\Throwable $e) {
            $body = '';
            $problems[] = ['fail', 'streaming the response failed: ' . self::describe($e)];
        }
        if ($status === 501 && str_contains($body, self::PLACEHOLDER_TEXT)) {
            return $base + ['result' => 'not_ported', 'problems' => []];
        }

        $e = $response->exception ?? null;
        if ($e instanceof \Throwable && ($status >= 500 || !self::isControlFlow($e))) {
            $problems[] = ['fail', 'exception: ' . self::describe($e)];
        }
        if ($status >= 500) {
            $problems[] = ['fail', "HTTP {$status}"];
        } elseif ($status >= 400 && !in_array('POST', $route->methods(), true) && !in_array($status, [401, 403], true)) {
            $problems[] = ['warn', "HTTP {$status} for a page"];   // endpoints answer a GET with 4xx by design
        }
        if (trim($stray) !== '') {
            $problems[] = ['warn', 'output echoed outside the response: ' . self::snippet($stray, 0)];
            $body = $stray . $body;
        }
        if (!$response instanceof BinaryFileResponse) {
            foreach (self::errorText($body) as $p) {
                $problems[] = ['fail', $p];
            }
        }
        $type = (string) $response->headers->get('Content-Type');
        if ($status === 200 && ($type === '' || str_contains($type, 'text/html'))) {
            foreach (self::inspectHtml($body, $uri) as $p) {
                $problems[] = $p;
            }
        }
        foreach ($missing as $key) {
            $problems[] = ['needs', "no ?{$key}= value found in the database"];
        }

        return $base + ['result' => self::verdict($problems), 'problems' => $problems];
    }

    /* fail beats everything; then needs_params; then warn */
    public static function verdict(array $problems): string
    {
        $levels = array_column($problems, 0);
        return in_array('fail', $levels, true) ? 'fail'
            : (in_array('needs', $levels, true) ? 'needs_params'
            : (in_array('warn', $levels, true) ? 'warn' : 'ok'));
    }

    /* Workers run in parallel; on Windows two of them compiling the same Blade
       view at once makes one rename() fail with "Access is denied" — a race in
       the checker, not a fault of the page (a real server compiles it once).
       Such a 500 is retried once; any other failure is reported as it is. */
    /** @return array{0: \Symfony\Component\HttpFoundation\Response, 1: string} */
    private static function handle(string $url, array $session): array
    {
        $result = self::handleOnce($url, $session);
        $e = $result[0]->exception ?? null;
        if ($e instanceof \Throwable && str_contains($e->getMessage(), 'rename(') && str_contains($e->getMessage(), 'Access is denied')) {
            usleep(300_000);
            $result = self::handleOnce($url, $session);
        }

        return $result;
    }

    /** @return array{0: \Symfony\Component\HttpFoundation\Response, 1: string} */
    private static function handleOnce(string $url, array $session): array
    {
        $store = app('session')->driver();
        $store->flush();
        $store->setId(null);                     // a fresh session per request
        foreach ($session as $k => $v) {
            $store->put($k, $v);
        }

        $request = Request::create($url, 'GET', [], [], [], [
            'HTTP_ACCEPT' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            'HTTP_USER_AGENT' => 'venusep:check',
        ]);
        $kernel = app(Kernel::class);
        $level = ob_get_level();
        ob_start();
        try {
            $response = $kernel->handle($request);
            $kernel->terminate($request, $response);
        } finally {
            $stray = '';
            while (ob_get_level() > $level) {
                $stray = ob_get_clean() . $stray;
            }
        }

        return [$response, $stray];
    }

    private static function body($response): string
    {
        if ($response instanceof BinaryFileResponse) {
            return '';
        }
        if ($response instanceof StreamedResponse) {
            $level = ob_get_level();
            ob_start();
            try {
                $response->sendContent();
            } finally {
                $out = '';
                while (ob_get_level() > $level) {
                    $out = ob_get_clean() . $out;
                }
            }
            return $out;
        }

        return (string) $response->getContent();
    }

    private static function isControlFlow(\Throwable $e): bool
    {
        foreach (self::CONTROL_FLOW as $class) {
            if ($e instanceof $class) {
                return true;
            }
        }

        return $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500;
    }

    /** @return list<string> */
    public static function errorText(string $body): array
    {
        /* inline JS / CSS may legitimately say "Warning: " (Alpine, console.warn) */
        $body = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $body) ?? $body;
        $found = [];
        foreach (self::ERROR_PATTERNS as $re => $label) {
            if (preg_match($re, $body, $m, PREG_OFFSET_CAPTURE)) {
                $found[] = $label . ': ' . self::snippet($body, $m[0][1]);
            }
        }

        return $found;
    }

    /* Links/assets that lead nowhere, and POST forms without @csrf.
       @return list<array{0: string, 1: string}> */
    public static function inspectHtml(string $html, string $pagePath): array
    {
        if (trim($html) === '') {
            return [];
        }
        $doc = new \DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?>' . $html, LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $xp = new \DOMXPath($doc);

        $problems = [];
        $seen = [];
        /* links inside an editing box (the FAQ answer editor) are text being edited, not links to follow */
        $refs = ['//a[@href][not(ancestor::*[@contenteditable="true"])]' => 'href','//link[@href]' => 'href', '//script[@src]' => 'src', '//img[@src]' => 'src',
            '//iframe[@src]' => 'src', '//source[@src]' => 'src', '//form[@action]' => 'action'];
        foreach ($refs as $query => $attr) {
            foreach ($xp->query($query) as $el) {
                $path = self::internalPath(trim($el->getAttribute($attr)), $pagePath);
                if ($path === null || isset($seen[$path])) {
                    continue;
                }
                $seen[$path] = true;
                if (!self::exists($path)) {
                    $kind = in_array($el->nodeName, ['a', 'form'], true) ? 'broken link' : 'missing asset';
                    $problems[] = ['fail', "{$kind}: <{$el->nodeName} {$attr}=\"" . $el->getAttribute($attr) . '">'];
                }
            }
        }
        foreach ($xp->query('//form') as $i => $form) {
            if (strtolower(trim($form->getAttribute('method'))) === 'post'
                && $xp->query('.//input[@name="_token"]', $form)->length === 0) {
                $label = $form->getAttribute('id') ?: ($form->getAttribute('action') ?: 'form #' . ($i + 1));
                $problems[] = ['warn', "POST form without a CSRF token (@csrf): {$label}"];
            }
        }

        return $problems;
    }

    /* The site-relative path a link points at, or null when it is not ours to check. */
    private static function internalPath(string $ref, string $pagePath): ?string
    {
        if ($ref === '' || $ref[0] === '#' || preg_match('/^(?:javascript|mailto|tel|data|blob|about):/i', $ref)
            || str_contains($ref, '${') || str_contains($ref, '{{')) {
            return null;
        }
        $parts = parse_url($ref);
        if ($parts === false) {
            return null;
        }
        if (isset($parts['scheme']) || str_starts_with($ref, '//')) {
            if (!in_array(strtolower($parts['host'] ?? ''), self::internalHosts(), true)) {
                return null;   // another site
            }
        }
        $path = $parts['path'] ?? '';
        if ($path === '') {
            return null;       // "?x=1" — the page itself
        }
        if ($path[0] !== '/') {
            $path = rtrim(str_ends_with($pagePath, '/') ? $pagePath : dirname($pagePath), '/\\') . '/' . $path;
        }
        $segments = [];
        foreach (explode('/', $path) as $seg) {
            if ($seg === '..') {
                array_pop($segments);
            } elseif ($seg !== '.' && $seg !== '') {
                $segments[] = $seg;
            }
        }

        return '/' . implode('/', $segments);
    }

    private static function internalHosts(): array
    {
        static $hosts = null;
        return $hosts ??= array_values(array_filter(array_unique([
            'localhost', '127.0.0.1', strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST)),
        ])));
    }

    /* a file in public/, or something the router answers (any method) */
    private static function exists(string $path): bool
    {
        $file = public_path(rawurldecode(ltrim($path, '/')));
        if ($path !== '/' && is_file($file)) {
            return true;
        }
        try {
            Router::getRoutes()->match(Request::create($path, 'GET'));
            return true;
        } catch (MethodNotAllowedHttpException $e) {
            return true;
        } catch (NotFoundHttpException $e) {
            return false;
        } catch (\Throwable $e) {
            return true;
        }
    }

    public static function localPath(string $location): string
    {
        $parts = parse_url($location);
        if ($parts === false || !isset($parts['host']) || !in_array(strtolower($parts['host']), self::internalHosts(), true)) {
            return $location;
        }

        return ($parts['path'] ?? '/') . (isset($parts['query']) ? '?' . $parts['query'] : '');
    }

    public static function describe(\Throwable $e): string
    {
        $file = str_replace('\\', '/', $e->getFile());
        $file = str_contains($file, '/laravel/') ? substr($file, strrpos($file, '/laravel/') + 9) : basename($file);

        return class_basename($e) . ': ' . self::snippet($e->getMessage(), 0, 220) . " ({$file}:{$e->getLine()})";
    }

    private static function snippet(string $text, int $at, int $len = 160): string
    {
        $s = substr($text, max(0, $at - 20), $len);
        $s = trim(preg_replace('/\s+/', ' ', strip_tags($s)));

        return mb_strlen($s) > $len ? mb_substr($s, 0, $len) . '…' : $s;
    }
}
