<?php

namespace KuboKolibri\Http\Controllers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class ProxyController extends Controller
{
    private Client $http;
    private string $kolibriUrl;

    public function __construct()
    {
        $this->kolibriUrl = rtrim(config('kubo-kolibri.kolibri_url', 'http://localhost:8080'), '/');
        $this->http = new Client([
            'base_uri' => $this->kolibriUrl,
            'timeout' => 30,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function proxy(Request $request, string $path = '')
    {
        // Laravel strips trailing slashes from route parameters, but Kolibri
        // redirects /en/learn → /en/learn/ (301). Recover the original path
        // from the request URI to preserve the trailing slash.
        $requestPath = $request->getRequestUri(); // e.g. /kolibri-proxy/en/learn/?foo=bar
        $targetUrl = preg_replace('#^/kolibri-proxy#', '', explode('?', $requestPath)[0]);
        if ($targetUrl === '') {
            $targetUrl = '/';
        }
        if ($request->getQueryString()) {
            $targetUrl .= '?' . $request->getQueryString();
        }

        $cookies = $this->buildCookieJar($request);

        $options = [
            'headers' => $this->buildForwardHeaders($request),
            'cookies' => $cookies,
        ];

        if (in_array($request->method(), ['POST', 'PUT', 'PATCH'])) {
            $contentType = $request->header('Content-Type', '');
            if (str_contains($contentType, 'application/json')) {
                $options['body'] = $request->getContent();
            } else {
                $options['form_params'] = $request->all();
            }
        }

        try {
            $response = $this->http->request($request->method(), $targetUrl, $options);
        } catch (GuzzleException $e) {
            return response('Kolibri is not available.', 502);
        }

        $statusCode = $response->getStatusCode();
        $contentType = $response->getHeaderLine('Content-Type');

        // Handle redirects — rewrite Location header to go through proxy
        if (in_array($statusCode, [301, 302, 303, 307, 308])) {
            $location = $response->getHeaderLine('Location');
            $headers = ['Location' => $this->rewriteLocationHeader($location)];
            $laravelResponse = response('', $statusCode, $headers);
            foreach ($response->getHeader('Set-Cookie') as $cookie) {
                $laravelResponse->header('Set-Cookie', $this->rewriteCookiePath($cookie), false);
            }
            return $laravelResponse;
        }

        $body = $response->getBody()->getContents();

        if (str_contains($contentType, 'text/html')) {
            $body = $this->injectOverrides($body);
        }

        $headers = $this->buildResponseHeaders($response, $contentType);

        // Don't cache HTML responses — injected CSS/JS changes need to take effect immediately
        if (str_contains($contentType, 'text/html')) {
            $headers['Cache-Control'] = 'no-cache, no-store, must-revalidate';
        }
        $laravelResponse = response($body, $statusCode, $headers);

        foreach ($response->getHeader('Set-Cookie') as $cookie) {
            $laravelResponse->header('Set-Cookie', $this->rewriteCookiePath($cookie), false);
        }

        return $laravelResponse;
    }

    private function rewriteLocationHeader(string $location): string
    {
        $parsed = parse_url($location);
        if (isset($parsed['host'])) {
            $kolibriHost = parse_url($this->kolibriUrl, PHP_URL_HOST);
            if ($parsed['host'] === $kolibriHost) {
                $path = $parsed['path'] ?? '/';
                $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
                $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
                $location = $path . $query . $fragment;
            } else {
                return $location;
            }
        }

        if (str_starts_with($location, '/') && !str_starts_with($location, '/kolibri-proxy/')) {
            $location = '/kolibri-proxy' . $location;
        }

        return $location;
    }

    private function buildCookieJar(Request $request): \GuzzleHttp\Cookie\CookieJar
    {
        $jar = new \GuzzleHttp\Cookie\CookieJar();
        $domain = parse_url($this->kolibriUrl, PHP_URL_HOST) ?: 'localhost';

        // Only forward Kolibri-relevant cookies — not KUBO's session, CSRF, or
        // analytics cookies which could confuse Django.
        $kolibriCookies = ['kolibri', 'kolibri_csrftoken', 'visitor_id'];
        $seen = [];

        // Read raw cookies from the header — bypass Laravel's EncryptCookies
        // which would try to decrypt Kolibri's unencrypted cookies and drop them.
        // Browsers send more-specific-path cookies first, so we keep the FIRST
        // occurrence of each name (the /kolibri-proxy/ cookie, not the stale / one).
        $cookieHeader = $request->header('Cookie', '');
        foreach (explode(';', $cookieHeader) as $part) {
            $part = trim($part);
            if (!$part) continue;
            $eq = strpos($part, '=');
            if ($eq === false) continue;
            $name = substr($part, 0, $eq);
            $value = substr($part, $eq + 1);

            if (!in_array($name, $kolibriCookies)) continue;
            if (isset($seen[$name])) continue;
            $seen[$name] = true;

            $jar->setCookie(new \GuzzleHttp\Cookie\SetCookie([
                'Name' => $name,
                'Value' => $value,
                'Domain' => $domain,
                'Path' => '/',
            ]));
        }

        return $jar;
    }

    private function buildForwardHeaders(Request $request): array
    {
        $headers = [
            'Accept' => $request->header('Accept', '*/*'),
            'Accept-Language' => $request->header('Accept-Language', 'en'),
            'Accept-Encoding' => 'identity', // Don't accept compressed — we need to read/rewrite HTML
        ];

        // Auto-inject Kolibri CSRF token from cookies into the header.
        // JS on the main page can't read cookies at path /kolibri-proxy/,
        // so the proxy does it server-side from the raw Cookie header.
        $cookieHeader = $request->header('Cookie', '');
        if ($request->header('X-CSRFToken')) {
            $headers['X-CSRFToken'] = $request->header('X-CSRFToken');
        } elseif (preg_match('/kolibri_csrftoken=([^;]+)/', $cookieHeader, $m)) {
            $headers['X-CSRFToken'] = $m[1];
        }
        if ($request->header('Content-Type')) {
            $headers['Content-Type'] = $request->header('Content-Type');
        }
        if ($request->header('If-None-Match')) {
            $headers['If-None-Match'] = $request->header('If-None-Match');
        }
        if ($request->header('If-Modified-Since')) {
            $headers['If-Modified-Since'] = $request->header('If-Modified-Since');
        }

        return $headers;
    }

    private function buildResponseHeaders($response, string $contentType): array
    {
        $headers = ['Content-Type' => $contentType];

        if ($response->hasHeader('Cache-Control')) {
            $headers['Cache-Control'] = $response->getHeaderLine('Cache-Control');
        }
        if ($response->hasHeader('ETag')) {
            $headers['ETag'] = $response->getHeaderLine('ETag');
        }
        if ($response->hasHeader('Last-Modified')) {
            $headers['Last-Modified'] = $response->getHeaderLine('Last-Modified');
        }

        // Strip Content-Security-Policy — Kolibri's CSP blocks our injected
        // inline <style>/<script>. Both servers are on localhost, so this is safe.
        // Strip Content-Encoding — we decoded it to rewrite HTML.

        return $headers;
    }

    private function rewriteCookiePath(string $cookie): string
    {
        return preg_replace('/Path\s*=\s*\//i', 'Path=/kolibri-proxy/', $cookie, 1);
    }

    private function injectOverrides(string $html): string
    {
        // 1. URL interceptor must run BEFORE any Kolibri JS loads.
        //    Inject immediately after <head> (before any <script src="...">).
        $urlInterceptor = '<script>' . $this->getUrlInterceptorJs() . '</script>';
        $html = preg_replace('/(<head[^>]*>)/i', '$1' . "\n" . $urlInterceptor, $html, 1);

        // 2. CSS overrides and MutationObserver — inject before </head>
        $css = $this->getInjectedCss();
        $js = $this->getInjectedJs();
        $overrides = "<style>{$css}</style>\n<script>{$js}</script>\n";
        if (stripos($html, '</head>') !== false) {
            $html = str_ireplace('</head>', $overrides . '</head>', $html);
        }

        // 3. Rewrite ALL absolute paths in src/href (except already-proxied ones and fragments)
        $html = preg_replace(
            '/(src|href)="\/(?!kolibri-proxy\/|#)([^"]*?)"/i',
            '$1="/kolibri-proxy/$2"',
            $html
        );

        return $html;
    }

    /**
     * JavaScript that intercepts fetch() and XMLHttpRequest to rewrite
     * absolute Kolibri paths through the proxy. Must run before Kolibri's
     * own scripts execute.
     */
    private function getUrlInterceptorJs(): string
    {
        return <<<'JS'
(function() {
    var proxyBase = '/kolibri-proxy';
    var lastComplete = false;

    function shouldRewrite(url) {
        if (typeof url !== 'string') return false;
        // Only rewrite absolute paths (starting with /)
        if (url.charAt(0) !== '/') return false;
        // Don't double-rewrite
        if (url.indexOf(proxyBase) === 0) return false;
        // Don't rewrite fragment-only
        if (url.charAt(1) === '#') return false;
        return true;
    }

    function rewrite(url) {
        return shouldRewrite(url) ? proxyBase + url : url;
    }

    // Intercept fetch() — rewrite URLs and detect exercise answer submissions
    var origFetch = window.fetch;
    window.fetch = function(input, init) {
        var url = typeof input === 'string' ? input : (input instanceof Request ? input.url : '');
        var method = (init && init.method) || (input instanceof Request ? input.method : 'GET');
        method = method.toUpperCase();

        // Rewrite URL
        if (typeof input === 'string') {
            input = rewrite(input);
        } else if (input instanceof Request) {
            var newUrl = rewrite(input.url.replace(location.origin, ''));
            if (newUrl !== input.url.replace(location.origin, '')) {
                input = new Request(location.origin + newUrl, input);
            }
        }

        var result = origFetch.call(this, input, init);

        // Detect Kolibri trackprogress POST/PUT — these fire on exercise interactions.
        // POST creates the session, PUT updates on each answer.
        // Read the RESPONSE to get correctness from attempt data.
        if ((method === 'POST' || method === 'PUT') && url.indexOf('trackprogress') !== -1) {
            result.then(function(response) {
                // Clone so we don't consume the body
                return response.clone().json();
            }).then(function(data) {
                if (!window.parent || window.parent === window) return;
                // Extract correctness from the latest attempt in the response.
                // PUT responses use 'attempts', POST responses use 'pastattempts'.
                var attempts = data.attempts || data.pastattempts || [];
                if (!attempts.length) return;
                var last = attempts[attempts.length - 1];
                var correct = last.correct || 0;
                window.parent.postMessage({
                    type: 'kubo-exercise-progress',
                    lastCorrect: correct >= 1
                }, '*');
                // Notify on first-time completion (transition from incomplete → complete).
                // Only check on PUT (answer submitted), not POST (session created).
                // POST sets lastComplete to the initial state without firing.
                if (method === 'PUT' && data.complete && !lastComplete) {
                    window.parent.postMessage({ type: 'kubo-exercise-complete' }, '*');
                }
                lastComplete = !!data.complete;
            }).catch(function() {});
        }

        return result;
    };

    // Intercept XMLHttpRequest — URL rewriting + trackprogress detection
    // Kolibri uses axios (XHR-based), so this is the primary interception point.
    var origOpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function(method, url) {
        this._kuboMethod = (method || 'GET').toUpperCase();
        this._kuboUrl = url || '';
        arguments[1] = rewrite(url);
        return origOpen.apply(this, arguments);
    };

    function notifyParentFromXhr(xhr) {
        if (xhr._kuboNotified) return;
        xhr._kuboNotified = true;
        try {
            var data = JSON.parse(xhr.responseText);
            if (!window.parent || window.parent === window) return;
            var attempts = data.attempts || data.pastattempts || [];
            if (attempts.length > 0) {
                var last = attempts[attempts.length - 1];
                window.parent.postMessage({
                    type: 'kubo-exercise-progress',
                    lastCorrect: (last.correct || 0) >= 1
                }, '*');
            }
            if (xhr._kuboMethod === 'PUT' && data.complete && !lastComplete) {
                window.parent.postMessage({ type: 'kubo-exercise-complete' }, '*');
            }
            lastComplete = !!data.complete;
        } catch(e) {}
    }

    var origSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function(body) {
        if ((this._kuboMethod === 'POST' || this._kuboMethod === 'PUT') &&
            this._kuboUrl.indexOf('trackprogress') !== -1) {
            var xhr = this;
            xhr._kuboNotified = false;
            xhr.addEventListener('load', function() {
                if (xhr.status >= 200 && xhr.status < 300) {
                    notifyParentFromXhr(xhr);
                }
            });
        }
        return origSend.apply(this, arguments);
    };

    // Intercept dynamic script/link/img element creation
    var origSetAttribute = Element.prototype.setAttribute;
    Element.prototype.setAttribute = function(name, value) {
        if ((name === 'src' || name === 'href') && typeof value === 'string') {
            value = rewrite(value);
        }
        return origSetAttribute.call(this, name, value);
    };

    // Override property setters for script.src, img.src, link.href
    ['HTMLScriptElement', 'HTMLImageElement', 'HTMLLinkElement', 'HTMLIFrameElement'].forEach(function(ctor) {
        var C = window[ctor];
        if (!C) return;
        ['src', 'href'].forEach(function(prop) {
            var desc = Object.getOwnPropertyDescriptor(C.prototype, prop);
            if (!desc || !desc.set) return;
            var origSet = desc.set;
            Object.defineProperty(C.prototype, prop, {
                get: desc.get,
                set: function(val) {
                    origSet.call(this, rewrite(val));
                },
                configurable: true,
                enumerable: true
            });
        });
    });
})();
JS;
    }

    private function getInjectedCss(): string
    {
        return <<<'CSS'
/* Hide Kolibri's top nav bar — KUBO's bar handles navigation */
.scrolling-header,
nav.k-navbar,
.k-navbar-component,
header[role="banner"],
[class*="ImmersiveToolbar"],
[class*="app-bar"],
.side-nav-container,
.side-nav {
    display: none !important;
}

/* Hide mastery indicators from previous sessions.
   Note: CompletionModal is NOT hidden here — exercise.blade.php's
   completion polling needs innerText to detect "Stay and practice". */
[class*="MasteryModel"],
[class*="mastery-model"],
[class*="PointsPopover"],
[class*="StatusIcon"][class*="correct"],
.content-icon svg.complete-icon,
[class*="overall-status"],
[class*="ProgressIcon"][class*="is-complete"] {
    display: none !important;
}

/* Reclaim space from hidden nav */
[class*="content-area"],
[class*="main-wrapper"],
main {
    margin-top: 0 !important;
    padding-top: 0 !important;
}
CSS;
    }

    private function getInjectedJs(): string
    {
        return <<<'JS'
(function() {
    var observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
            m.addedNodes.forEach(function(node) {
                if (node.nodeType !== 1) return;
                var selectors = [
                    '[class*="CompletionModal"]',
                    '[class*="MasteryModel"]',
                    '[class*="PointsPopover"]'
                ];
                selectors.forEach(function(sel) {
                    var el = node.matches && node.matches(sel) ? node : node.querySelector && node.querySelector(sel);
                    if (el) el.style.display = 'none';
                });
            });
        });
    });
    if (document.body) {
        observer.observe(document.body, { childList: true, subtree: true });
    } else {
        document.addEventListener('DOMContentLoaded', function() {
            observer.observe(document.body, { childList: true, subtree: true });
        });
    }
})();
JS;
    }
}
