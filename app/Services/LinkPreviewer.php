<?php

namespace App\Services;

use Closure;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Reads the Open Graph tags (title, text, image) of a link, the same way
 * Facebook builds the preview of a shared link.
 */
class LinkPreviewer
{
    /**
     * Facebook serves Open Graph tags of public posts to its own crawler.
     */
    private const USER_AGENT = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';

    /**
     * @param  (Closure(string): list<string>)|null  $resolveHost  Returns the IP addresses of a host name.
     */
    public function __construct(private ?Closure $resolveHost = null) {}

    /**
     * @return array{url: string, site_name: ?string, title: ?string, description: ?string, image: ?string}|null
     */
    public function preview(string $url): ?array
    {
        if (! $this->isSafePublicUrl($url)) {
            return null;
        }

        return Cache::remember('link-preview:'.sha1($url), now()->addHour(), fn () => $this->fetch($url));
    }

    /**
     * Facebook's API rejects short share links (facebook.com/share/p/...), so
     * turn them into the post's real address, e.g. https://www.facebook.com/Page/posts/123.
     */
    public function canonicalFacebookUrl(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (! in_array($host, ['facebook.com', 'www.facebook.com', 'web.facebook.com', 'm.facebook.com', 'fb.com', 'fb.watch'], true)) {
            return $url;
        }

        $resolved = $url;
        $path = (string) parse_url($url, PHP_URL_PATH);

        if ($host === 'fb.watch' || str_starts_with($path, '/share/')) {
            $resolved = $this->preview($url)['url'] ?? $url;
        }

        $resolvedPath = rawurldecode((string) parse_url($resolved, PHP_URL_PATH));

        // /Page/posts/<optional text slug>/<id>/  ->  /Page/posts/<id>
        if (preg_match('#^/([^/]+)/posts/(?:[^/]*/)?(\w+)/?$#u', $resolvedPath, $matches) === 1) {
            return 'https://www.facebook.com/'.$matches[1].'/posts/'.$matches[2];
        }

        // Reels and videos: drop tracking parameters such as ?rdid=...&share_url=...
        if (preg_match('#^/reel/(\d+)/?$#', $resolvedPath, $matches) === 1) {
            return 'https://www.facebook.com/reel/'.$matches[1];
        }

        if (preg_match('#^/([^/]+)/videos/(?:[^/]*/)?(\d+)/?$#u', $resolvedPath, $matches) === 1) {
            return 'https://www.facebook.com/'.$matches[1].'/videos/'.$matches[2];
        }

        return (string) preg_replace('#^https?://(web|m)\.facebook\.com#', 'https://www.facebook.com', $resolved);
    }

    /**
     * @return array{url: string, site_name: ?string, title: ?string, description: ?string, image: ?string}|null
     */
    private function fetch(string $url): ?array
    {
        $response = null;
        $currentUrl = $url;

        // Follow redirects by hand so every hop is checked (Facebook share links redirect).
        for ($hop = 0; $hop <= 3; $hop++) {
            try {
                $response = Http::withHeaders(['User-Agent' => self::USER_AGENT, 'Accept-Language' => 'bn,en;q=0.8'])
                    ->withoutRedirecting()
                    ->connectTimeout(5)
                    ->timeout(10)
                    ->get($currentUrl);
            } catch (Throwable) {
                return null;
            }

            if (! $response->redirect()) {
                break;
            }

            $location = $response->header('Location');
            $nextUrl = str_starts_with($location, '/')
                ? parse_url($currentUrl, PHP_URL_SCHEME).'://'.parse_url($currentUrl, PHP_URL_HOST).$location
                : $location;

            if (! $this->isSafePublicUrl($nextUrl)) {
                return null;
            }

            $currentUrl = $nextUrl;
        }

        $url = $currentUrl;

        if (! $response->successful()) {
            return null;
        }

        $tags = $this->metaTags(mb_substr($response->body(), 0, 1_000_000));

        $preview = [
            'url' => $tags['og:url'] ?? $url,
            'site_name' => $tags['og:site_name'] ?? parse_url($url, PHP_URL_HOST),
            'title' => $tags['og:title'] ?? $tags['title'] ?? null,
            'description' => $tags['og:description'] ?? $tags['description'] ?? null,
            'image' => $tags['og:image'] ?? null,
        ];

        return $preview;
    }

    /**
     * @return array<string, string>
     */
    private function metaTags(string $html): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_use_internal_errors($previous);

        $xpath = new DOMXPath($document);
        $tags = [];

        foreach ($xpath->query('//meta[@property or @name]') ?: [] as $meta) {
            $key = strtolower($meta->getAttribute('property') ?: $meta->getAttribute('name'));
            $content = trim(html_entity_decode($meta->getAttribute('content'), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

            if ($content !== '' && ! isset($tags[$key])) {
                $tags[$key] = $content;
            }
        }

        $title = $xpath->query('//title')?->item(0)?->textContent;

        if (! isset($tags['title']) && is_string($title) && trim($title) !== '') {
            $tags['title'] = trim($title);
        }

        return $tags;
    }

    /**
     * Only fetch public web addresses, never the server's own network.
     */
    private function isSafePublicUrl(string $url): bool
    {
        $parts = parse_url($url);

        if (! in_array($parts['scheme'] ?? null, ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }

        $addresses = ($this->resolveHost ?? fn (string $host) => gethostbynamel($host) ?: [])($parts['host']);

        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        return true;
    }
}
