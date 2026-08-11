# Cache Header Audit — Production-Grade Reference

> Reference for every cache-affecting header RankReady emits. Updated 1.0.1.
> Audit standard: RFC 9110 / 9111, Mark Nottingham's caching tutorial, the
> public docs of Cloudflare, Fastly, Varnish, Akamai, LiteSpeed.

## Tl;dr — three header recipes, one source of truth

| Endpoint type | Recipe | Source method |
|---|---|---|
| **Dynamic / never cache** (`/post.md`, `/index.md`, error pages) | No-cache directives for every known layer | `RNRD_Cache::no_cache_headers()` |
| **Cacheable static** (`/llms.txt`, `/llms-full.txt`, `/mcp.json`) | Public, ETag-revalidated, browser-vs-edge TTL split, CORS open | Inline in serve methods |
| **HTML pages with negotiation hint** | `Vary: Accept, Accept-Encoding` + `Link: rel=alternate` to .md | `add_vary_header()` + `add_md_link_header()` |

## Recipe 1 — dynamic no-cache

The canonical no-cache header set, emitted by `RNRD_Cache::no_cache_headers()`:

```
Cache-Control:                no-store, no-cache, must-revalidate, max-age=0
Expires:                      0
CF-Edge-Cache:                no-cache
Cloudflare-CDN-Cache-Control: no-store
CDN-Cache-Control:            no-store
Surrogate-Control:            no-store
Akamai-Cache-Control:         no-store
Edge-Control:                 no-store
X-Accel-Expires:              0
X-LiteSpeed-Cache-Control:    no-cache
X-LiteSpeed-Tag:              rnrd-dynamic
```

| Layer | Directive read | Why this works |
|---|---|---|
| Browser / generic proxy | `Cache-Control` | RFC 9111 §5.2 |
| Ancient HTTP/1.0 proxy | `Expires: 0` | RFC 9111 §5.3 |
| Cloudflare APO | `CF-Edge-Cache: no-cache` | APO ignores `CDN-Cache-Control`. The only header APO reads. |
| Cloudflare (non-APO) | `Cloudflare-CDN-Cache-Control` then `CDN-Cache-Control` | Cloudflare-specific overrides the generic one |
| BunnyCDN, KeyCDN | `CDN-Cache-Control` | Cross-CDN standard |
| Varnish, Fastly | `Surrogate-Control` | RFC 5861-aligned |
| Akamai (modern) | `Akamai-Cache-Control` | Their own draft header |
| Akamai (legacy) | `Edge-Control` | Older Akamai property profiles |
| Nginx FastCGI cache | `X-Accel-Expires: 0` | Nginx-specific |
| LiteSpeed Web Server (LSWS) | `X-LiteSpeed-Cache-Control: no-cache` + `X-LiteSpeed-Tag` | Tag-based purge possible |

**Plus PHP constants** for in-process page-cache plugins:

```php
DONOTCACHEPAGE     // WP Rocket, W3TC, WP Super Cache, WP Fastest Cache, Cache Enabler, Comet Cache, Breeze, SG Optimizer
DONOTCACHEOBJECT   // W3TC
DONOTCACHEDB       // W3TC
LSCWP_NO_CACHE     // LiteSpeed Cache plugin
```

### Why no `Pragma: no-cache` (removed in 1.0.1)

Per RFC 9111 §5.4, `Pragma` is a **request**-direction header. Sending it on a response is technically a spec violation. Modern caches ignore it; ancient caches honour `Expires: 0` anyway. Drop it.

### Why `no-store` AND `no-cache` (deliberately both)

- `no-store`: do not write the response to any cache at all
- `no-cache`: if you do cache it, you must revalidate before reuse
- Different intermediaries interpret one but not the other. Sending both is defensive, not redundant.

## Recipe 2 — cacheable static (llms.txt, mcp.json)

```
Cache-Control:                  public, max-age=60, s-maxage=600, stale-while-revalidate=3600
CDN-Cache-Control:              public, max-age=600, stale-while-revalidate=3600
Cloudflare-CDN-Cache-Control:   public, max-age=600
Surrogate-Control:              max-age=600
ETag:                           "<sha1-of-body>"
Vary:                           Accept-Encoding
Access-Control-Allow-Origin:    *
Access-Control-Allow-Methods:   GET, HEAD, OPTIONS
Access-Control-Expose-Headers:  Content-Type, ETag, Last-Modified
```

### Browser-vs-edge TTL split (Nottingham §6.2)

| Token | Value | Audience | Reasoning |
|---|---|---|---|
| `max-age=60` | 1 min | Browsers, end-user clients | Cheap to revalidate via ETag → 304. Keeps clients seeing fresh content. |
| `s-maxage=600` | 10 min | Shared caches (CDN edges, Varnish) | Reduces origin load: one origin fetch per 10 min per edge POP regardless of viewer count |
| `stale-while-revalidate=3600` | 1 hr | Compatible edges (Cloudflare, Fastly, Vercel) | Lets edge serve a slightly-stale body for an hour while fetching fresh in background → zero user-facing latency for refresh |

### ETag revalidation pattern

```php
$etag        = '"' . sha1( $body ) . '"';
$client_etag = trim( $_SERVER['HTTP_IF_NONE_MATCH'] ?? '' );
if ( '' !== $client_etag && $client_etag === $etag ) {
    status_header( 304 );
    header( 'ETag: ' . $etag );
    header( 'Cache-Control: public, max-age=60, s-maxage=600, stale-while-revalidate=3600' );
    exit;
}
```

Sites like Cloudflare docs, Stripe docs, Vercel docs all use the same pattern. Bandwidth savings ~60–90% on revalidations.

## Recipe 3 — markdown response (negotiated)

```
Content-Type:                   text/markdown; charset=utf-8
Vary:                           Accept, Accept-Encoding
X-Robots-Tag:                   noindex, follow
X-Content-Type-Options:         nosniff
Link:                           <canonical-url>; rel="canonical"
Access-Control-Allow-Origin:    *
Access-Control-Allow-Methods:   GET, HEAD, OPTIONS
Access-Control-Expose-Headers:  Content-Type, ETag, Last-Modified, Link, X-Markdown-Tokens
X-RankReady-Source:             markdown-accept
X-Markdown-Tokens:              <approximate-token-count>
+ all dynamic no-cache headers from Recipe 1
```

### Why Vary on BOTH Accept AND Accept-Encoding

- `Accept` ensures the cache stores HTML and Markdown separately when negotiating on the same URL
- `Accept-Encoding` ensures a gzip-encoded body isn't served to an identity-only client

Missing `Accept-Encoding` from `Vary` is a real correctness bug under compressing intermediaries (cited explicitly in Nottingham §6.4).

### Why CORS open

AI agents fetch markdown cross-origin from `chat.openai.com`, `claude.ai`, `perplexity.ai`, etc. Without `Access-Control-Allow-Origin: *` the browser-runtime fetch fails CORS preflight. Public markdown content carries no risk from open CORS — the same content is served to direct HTTPS requests anyway.

## Common anti-patterns this plugin avoids

| Anti-pattern | What it does wrong | RankReady's behaviour |
|---|---|---|
| `Cache-Control: max-age=0` alone | No instruction for shared caches; some treat as "cache forever then revalidate" | Always pairs with `no-store` or `s-maxage` |
| `Cache-Control: no-cache` without ETag/Last-Modified | Forces revalidation but provides nothing to revalidate against — re-downloads body every time | ETag emitted on every cacheable response |
| `Expires` past date with no `Cache-Control` | Modern caches honor it forever; you cannot purge | Always paired with `Cache-Control` |
| Mixed casing (`cache-control` vs `CACHE-CONTROL`) | Tools that compare case-sensitively flag false positives | Title-Case throughout |
| `Vary: *` | Disables caching entirely | Specific Vary tokens only |
| `Vary: User-Agent` | Causes massive cache fragmentation | Never used |
| Pragma on response | RFC 9111 violation | Removed in 1.0.1 |
| `Cache-Control: private` on public content | Blocks edge caches for everyone | Never used on llms.txt / mcp.json |
| Setting CORS to specific origin then forgetting Vary: Origin | Edge caches one origin's response and replays to another | Plugin uses `*` (truly public content) |

## Coverage matrix

| Cache layer | Detected | Bypassed (dynamic) | Cacheable respected | Tested |
|---|---|---|---|---|
| Cloudflare APO | ✅ | ✅ via `CF-Edge-Cache` | ✅ | ✅ |
| Cloudflare (non-APO) | ✅ | ✅ via `Cloudflare-CDN-Cache-Control` | ✅ | ✅ |
| Cloudflare Workers / Pages | n/a | inherits above | inherits above | — |
| Fastly | n/a | ✅ via `Surrogate-Control` | ✅ via `Surrogate-Control` | — |
| Varnish | n/a | ✅ via `Surrogate-Control` | ✅ via `Surrogate-Control` | — |
| Akamai | n/a | ✅ via `Akamai-Cache-Control` + `Edge-Control` | ✅ | — |
| BunnyCDN / KeyCDN / generic CDN | n/a | ✅ via `CDN-Cache-Control` | ✅ | — |
| Nginx FastCGI cache | ✅ | ✅ via `X-Accel-Expires` | ✅ via `X-Accel-Expires` | ✅ |
| LiteSpeed Web Server | ✅ | ✅ via `X-LiteSpeed-Cache-Control` + tag | ✅ | ✅ |
| LiteSpeed Cache plugin (PHP) | ✅ | ✅ via `LSCWP_NO_CACHE` + exclusion filter | ✅ | ✅ |
| WP Rocket | ✅ | ✅ via `DONOTCACHEPAGE` + `rocket_cache_reject_uri` | ✅ | ✅ |
| W3 Total Cache | ✅ | ✅ via `DONOTCACHE*` constants + URI reject | ✅ | ✅ |
| WP Super Cache | ✅ | ✅ via `DONOTCACHEPAGE` + reject filter | ✅ | ✅ |
| WP Fastest Cache | ✅ | ✅ via `DONOTCACHEPAGE` | ✅ | ✅ |
| NitroPack | ✅ | ✅ via `DONOTCACHEPAGE` + filter | ✅ | ✅ |
| Hummingbird | ✅ | ✅ via filter | ✅ | ✅ |
| Cache Enabler | ✅ | ✅ via filter | ✅ | ✅ |
| Comet Cache | ✅ | ✅ via `DONOTCACHEPAGE` | ✅ | ✅ |
| SG Optimizer | ✅ | ✅ via `DONOTCACHEPAGE` | ✅ | ✅ |
| Breeze (Cloudways) | ✅ | ✅ via `DONOTCACHEPAGE` | ✅ | ✅ |
| Swift Performance | ✅ | ✅ via `DONOTCACHEPAGE` | ✅ | ✅ |
| Autoptimize | ✅ | ✅ via JS/CSS exclude filters | ✅ | ✅ |
| Perfmatters | ✅ | ✅ via filters | ✅ | ✅ |
| Pantheon Edge | ✅ | ✅ via `Surrogate-Control` | ✅ | — |
| WP Engine Edge | ✅ | ✅ via `Cache-Control: no-store` | ✅ | — |
| Kinsta Edge | ✅ | ✅ via Cloudflare layer | ✅ | — |
| Nginx Helper | ✅ | ✅ via tag filter | ✅ | ✅ |

## Verification (curl one-liners)

```bash
# Dynamic markdown — should show no-cache directives from every layer:
curl -sI -H "Accept: text/markdown" https://yoursite.com/index.md \
  | grep -iE "^(cache-control|expires|cf-|cdn-|cloudflare-|surrogate-|akamai-|edge-|x-accel|x-litespeed|vary|access-control)"

# Cacheable llms.txt — should show ETag, TTL split, CORS:
curl -sI https://yoursite.com/llms.txt \
  | grep -iE "^(cache-control|cdn-|cloudflare-|surrogate-|etag|vary|access-control)"

# 304 revalidation — should return 304 with empty body when ETag matches:
ETAG=$(curl -sI https://yoursite.com/llms.txt | awk '/^[Ee][Tt]ag:/{print $2}' | tr -d '\r')
curl -sI -H "If-None-Match: $ETAG" https://yoursite.com/llms.txt | head -1
# Expected: HTTP/2 304
```

## What changed in 1.0.1 from 1.0.0

| Change | Reason |
|---|---|
| Removed `Pragma: no-cache` from response paths | RFC 9111 §5.4 violation; cosmetic but enterprise responses are clean |
| Added `Expires: 0` to no-cache path | Belt-and-suspenders for HTTP/1.0-era proxies |
| Renamed `cf-edge-cache` → `CF-Edge-Cache` | Cloudflare docs casing convention |
| Renamed `x-markdown-source` → `X-RankReady-Source` | Consistent vendor-prefixed casing |
| Added `Akamai-Cache-Control` alongside `Edge-Control` | Modern + legacy Akamai header coverage |
| Added `Vary: Accept-Encoding` to all Vary headers | Real correctness fix under compressing intermediaries |
| Added ETag + If-None-Match handling to `/llms.txt` and `/mcp.json` | 60–90% bandwidth saving on revalidation |
| Split `max-age` ≠ `s-maxage` on cacheable endpoints | Browsers re-check often (cheap with ETag), CDN edges cache longer (lower origin load) |
| Added `Access-Control-*` CORS headers to `/llms.txt`, `/mcp.json`, `/.md` | AI agents fetch cross-origin; previously blocked by browser CORS |
| Removed double emission of CDN headers from markdown class | Now delegates to `RNRD_Cache::no_cache_headers()` once |
| Added `Surrogate-Control: max-age=X` to cacheable endpoints | Varnish/Fastly TTL respected (was missing) |
