# RankReady — AI Standards Implementation Whitepaper

**Version**: 0.6.4  
**Date**: April 2026  
**Author**: Aditya Sharma / POSIMYTH  
**Status**: Internal reference — fact-checked against primary sources

---

## Purpose

This document describes every AI/LLM web standard RankReady implements, with accurate spec citations, correct implementation notes, honest assessment of what is standardized vs. community convention, and known limitations. Written to ensure production code matches real specs and does not implement imagined or hallucinated standards.

---

## 1. LLMs.txt

### Source
- **Spec**: [llmstxt.org](https://llmstxt.org) — proposed by Jeremy Howard (Answer.AI), not yet an IETF RFC
- **Status**: Community-adopted proposal. Widely implemented (Anthropic, Cloudflare, Linear, Vercel, many others). Not formally standardized.

### What the spec says
A file at `/llms.txt` on the root of a domain, in Markdown format:

```markdown
# Site Name  (required — H1)

> Short summary of site  (optional blockquote)

Optional body paragraphs.

## Section Name  (optional H2 sections)

- [Page Title](https://example.com/page): Short description of page

## Optional

- [Secondary Page](https://example.com/secondary): Less important content
```

**Key rules:**
- Only one H1, at the top
- Blockquote immediately after H1 for the summary
- H2-delimited sections contain file lists: `- [Title](URL): description`
- An `## Optional` section signals content AI can skip if context window is full
- `/llms-full.txt` is a convention (not in the formal spec) where full page content is inlined

### Markdown endpoints (.md)
The spec states: *"pages on websites that have information that might be useful for LLMs to read provide a clean markdown version of those pages at the same URL as the original page, but with `.md` appended."*

**Correct implementation**: `/pricing/` → `/pricing.md`, `/about/` → `/about.md`

**Homepage**: There is NO spec-defined `.md` URL for the homepage. The correct approach is HTTP content negotiation — send `Accept: text/markdown` to the homepage URL and serve markdown. RankReady implements this correctly. A `/index.md` URL is non-standard and was removed in v0.6.4.3.

### HTTP headers (community convention, not in spec)
The spec does not mention `Link:` headers. However, community practice has emerged:

```http
Link: </llms.txt>; rel="llms-txt"
```

**Important**: `llms-txt` and `llms-full-txt` are **not registered IANA link relation types**. They are community conventions only. RankReady emits them as a best-effort discovery mechanism.

### RankReady implementation
- `/llms.txt` — served via WordPress rewrite rule, generated from published posts. Cached via transients, busted on publish/update.
- `/llms-full.txt` — full content inlined per Lovable/Mintlify convention
- `Accept: text/markdown` on homepage → site overview markdown
- `Accept: text/markdown` on post/page URLs → post markdown
- `<link rel="llms-txt">` in `<head>` — non-standard discovery hint
- `Link: </llms.txt>; rel="llms-txt"` response header — non-standard discovery hint

---

## 2. Markdown Content Negotiation

### Source
- **Basis**: [HTTP content negotiation RFC 9110 §12](https://www.rfc-editor.org/rfc/rfc9110#section-12) — fully standardized
- **Markdown endpoint convention**: llmstxt.org proposal + emerging practice (Next.js, Cloudflare)

### How it works
When a client sends `Accept: text/markdown` in the request, the server responds with `Content-Type: text/markdown` instead of HTML. This is standard HTTP content negotiation.

**Required response headers when serving markdown:**
```http
Content-Type: text/markdown; charset=utf-8
Vary: Accept
```

The `Vary: Accept` header is **mandatory** to prevent CDNs from caching the markdown response and serving it to HTML-requesting clients.

### RankReady implementation
- `template_redirect` hook at priority 5 checks `HTTP_ACCEPT` for `text/markdown`
- Serves post markdown for singular posts/pages
- Serves site overview markdown for homepage (`is_front_page() || is_home()`)
- `Vary: Accept` added to all HTML responses via `send_headers` hook
- `Link: <URL>; rel="alternate"; type="text/markdown"` per-post header
- `<link rel="alternate" type="text/markdown">` in `<head>` per-post

---

## 3. Content Signals (robots.txt)

### Source
- **Spec**: [contentsignals.org](https://contentsignals.org) — IETF Internet Draft (not yet RFC)
- **Status**: Draft proposal, not finalized. Real-world adoption beginning.

### What the draft says
Top-level directives added to `robots.txt` **outside** any `User-agent:` block, declaring AI usage preferences:

```
ai-train: allow|deny
search: allow|deny
ai-input: allow|deny
```

**Correct placement — outside User-agent blocks:**
```
# Global rules
User-agent: *
Disallow: /wp-admin/

# Content Signals (contentsignals.org)
ai-train: allow
search: allow
ai-input: allow

User-agent: GPTBot
Allow: /
```

**What each directive means:**
- `ai-train: allow` — Content may be used to train AI models
- `search: allow` — Content may appear in AI-powered search results
- `ai-input: allow` — Content may be used as RAG/context input at inference time

### RankReady implementation
- Generated in `generate_robots_block()` after the User-agent AI crawler block
- Synced to physical robots.txt via `sync_physical_robots_txt()` on settings save
- Auto-synced on plugin update via `admin_init` version check (v0.6.4.1+)
- Each signal independently configurable (allow/deny) from LLM Optimization tab

---

## 4. AI Crawler robots.txt Rules

### Source
- **Spec**: [RFC 9309](https://www.rfc-editor.org/rfc/rfc9309) — Robots Exclusion Protocol (fully standardized, September 2022)

### Key rule
A robots.txt block with multiple `User-agent:` lines and NO `Disallow:` or `Allow:` has **no effect**. Each User-agent group must have at least one directive.

**Wrong (was in old robots.txt):**
```
User-agent: GPTBot
User-agent: ClaudeBot
User-agent: Bingbot
Allow: /
```
Wait — this is actually valid per RFC 9309. Multiple User-agent lines sharing directives is explicitly allowed.

**Also wrong (was in ban bots section):**
```
User-agent: Nuclei
User-agent: Gigabot
# No Disallow: / — these bots were not actually banned
```

**Fixed (v0.6.4.1+):**
```
User-agent: Nuclei
Disallow: /

User-agent: Gigabot
Disallow: /
```

### Explicit Allow for AI-specific endpoints
Best practice: explicitly allow AI-specific endpoints even when using global rules:

```
User-agent: GPTBot
User-agent: ClaudeBot
...
Allow: /
Allow: /llms.txt
Allow: /llms-full.txt
Allow: /*.md$
```

RankReady implements this in `generate_robots_block()`.

---

## 5. Agent Skills Discovery

### Source
- **Claimed origin**: "Cloudflare Agent Skills Discovery RFC"
- **Reality**: No official Cloudflare RFC for this found. No published spec at `/.well-known/agent-skills/`. The `agentskills.io` domain does not have a live schema (`agentskills.io/schema/v1/index.schema.json` returns 404).
- **Status**: Draft/proposed by the isitagentready.com team. Not a ratified standard.

### What isitagentready.com expects
A JSON file at `/.well-known/agent-skills/index.json`. Based on analysis of what the scanner checks:

```json
{
  "version": "1.0",
  "site": {
    "name": "Site Name",
    "url": "https://example.com/"
  },
  "skills": [
    {
      "id": "llms-txt",
      "name": "LLMs.txt",
      "description": "...",
      "url": "https://example.com/llms.txt",
      "type": "content"
    }
  ],
  "generated": "2026-04-21T00:00:00Z",
  "generator": "RankReady/0.6.4"
}
```

**Bug fixed in v0.6.4**: Removed `$schema` field pointing to non-existent `agentskills.io/schema/v1/index.schema.json`.

### RankReady implementation
- Serves `/.well-known/agent-skills/index.json` via WordPress rewrite rules
- Auto-builds from enabled features (llms.txt, markdown, sitemap, robots.txt)
- Returns 404 when feature is disabled
- **Limitation**: Blocked by Cloudflare WAF on sites using Cloudflare proxy. Requires WAF bypass rule for `/.well-known/*` to work.

---

## 6. API Catalog (RFC 9727)

### Source
- **Spec**: [RFC 9727](https://www.rfc-editor.org/rfc/rfc9727) — published June 2025, IETF
- **Depends on**: [RFC 9264](https://www.rfc-editor.org/rfc/rfc9264) — Linkset format
- **Status**: Official RFC. Fully standardized.

### Correct format
Content-Type: `application/linkset+json`
Endpoint: `GET /.well-known/api-catalog`

```json
{
  "linkset": [
    {
      "anchor": "https://example.com/",
      "item": [
        {
          "href": "https://example.com/wp-json/",
          "type": "application/json",
          "title": "WordPress REST API"
        }
      ]
    }
  ]
}
```

Per RFC 9264, `anchor` is the resource context — for a site's API catalog, the site root (`https://example.com/`) is correct.

### RankReady implementation
- Serves `/.well-known/api-catalog` via WordPress rewrite rules
- `Content-Type: application/linkset+json` ✅
- Includes WP REST API, llms.txt (if enabled), markdown endpoint (if enabled)
- **Same Cloudflare WAF limitation as Agent Skills above**

---

## 7. Discovery Link Headers

### Source
- **HTTP Link header**: [RFC 8288](https://www.rfc-editor.org/rfc/rfc8288) — fully standardized
- **`rel="sitemap"`**: Not IANA registered. Community convention used by Google.
- **`rel="llms-txt"`**: Not IANA registered. Community convention.
- **`rel="alternate"`** with `type="text/markdown"`: `alternate` IS IANA registered. Using it with a type parameter is standard.

### What RankReady emits on every page
```http
Link: </llms.txt>; rel="llms-txt"
Link: </llms-full.txt>; rel="llms-full-txt"
Link: </>; rel="alternate"; type="text/markdown"
Link: </sitemap_index.xml>; rel="sitemap"; type="application/xml"
```

And in `<head>`:
```html
<link rel="llms-txt" type="text/plain" href="/llms.txt" />
<link rel="llms-full-txt" type="text/plain" href="/llms-full.txt" />
```

**Honest assessment**: Only `rel="alternate"` is IANA-registered. The others are community conventions that major scanners (isitagentready.com) check for. They cause no harm and are useful for discovery.

---

## 8. Article + Speakable JSON-LD

### Source
- **Schema.org/Article**: [schema.org/Article](https://schema.org/Article) — well-established, used by Google
- **SpeakableSpecification**: [schema.org/SpeakableSpecification](https://schema.org/SpeakableSpecification) — supported by Google for voice search

### RankReady implementation
- Injected only when no major SEO plugin is active (avoids duplication with Yoast/Rank Math)
- Includes `speakable` property targeting article summary and first body paragraph
- Standard `@context`, `@type`, `headline`, `author`, `datePublished`, `dateModified`

---

## 9. FAQPage JSON-LD

### Source
- **Schema.org/FAQPage**: [schema.org/FAQPage](https://schema.org/FAQPage) — supported by Google for rich results

### RankReady implementation
- Generated from RankReady-stored FAQ data
- Skipped when Rank Math, Yoast, or AIOSEO FAQ blocks are detected (prevents duplication)
- Valid `Question`/`Answer` structure with `acceptedAnswer`

---

## 10. What's Not Yet Implemented (and Why)

| Standard | Status | Why Not Implemented |
|----------|--------|---------------------|
| MCP Server Card (`/.well-known/mcp.json`) | Draft | Requires actual MCP server backend — not applicable to content sites |
| WebMCP | Draft | Cloudflare-specific, requires Workers infrastructure |
| OAuth Discovery (RFC 8414) | RFC | Only needed for protected API endpoints |
| OAuth Protected Resource (RFC 9728) | RFC | Only needed for token-protected APIs |
| x402 Payment Protocol | Draft | Requires payment infrastructure |
| Web Bot Auth | Draft | Spec not publicly accessible; domain has TLS issues |
| UCP / ACP Commerce | Draft | Agentic commerce — not applicable to plugin/documentation sites |

**Realistic maximum score on isitagentready.com for a content site**: ~55-60/100. The remaining checks require live MCP servers, OAuth infrastructure, or payment endpoints that don't apply to content/documentation sites.

---

## Known Issues and Limitations

1. **`/.well-known/` endpoints blocked by Cloudflare WAF** — Agent Skills and API Catalog return 403 on sites behind Cloudflare without a WAF bypass rule for `/.well-known/*`.

2. **Content Signals spec is a draft** — The contentsignals.org directives (`ai-train`, `search`, `ai-input`) may change syntax or semantics before finalization. We implement the current draft version.

3. **Agent Skills has no official spec** — The `/.well-known/agent-skills/index.json` format is based on what isitagentready.com expects, not a ratified RFC. Field names or structure may change.

4. **Link relation types not IANA-registered** — `rel="llms-txt"`, `rel="llms-full-txt"`, `rel="sitemap"` are community conventions, not registered types. They are harmless but not formally standard.

5. **Physical robots.txt sync** — When WordPress cannot write to the physical robots.txt file (file permissions, Nginx-only stack without `.htaccess`), the `sync_physical_robots_txt()` function silently fails. A manual sync button in the Health Check tab would be a useful future addition.

---

## Version History of AI Features

| Version | Feature |
|---------|---------|
| 0.5.0 | LLMs.txt, Markdown endpoints, robots.txt crawler controls |
| 0.6.0 | Dashboard overhaul |
| 0.6.4 | Content Signals, Agent Skills, API Catalog, Markdown homepage negotiation, Vary: Accept |
| 0.6.4.1 | robots.txt ban bots fix, AI crawler Allow paths, auto-sync on plugin update |
| 0.6.4.2 | Discovery Link headers and `<link>` tags on every page |
| 0.6.4.3 | Revert non-standard `/index.md`, fix Agent Skills fake schema URL |
