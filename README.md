<div align="center">

# RankReady — llms.txt, Markdown & AI SEO for WordPress

### Make your content machine-readable for ChatGPT, Perplexity, Claude, Gemini and Google AI Overviews — alongside your existing SEO plugin, not instead of it.

[![WordPress.org](https://img.shields.io/badge/WordPress.org-1.3.0-10b981?style=for-the-badge)](https://wordpress.org/plugins/rankready-ai-llm-seo/)
[![WordPress](https://img.shields.io/badge/WordPress-6.9%2B-10b981?style=for-the-badge)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-10b981?style=for-the-badge)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPL%20v2+-blue?style=for-the-badge)](https://www.gnu.org/licenses/gpl-2.0)

**[Download from WordPress.org →](https://wordpress.org/plugins/rankready-ai-llm-seo/)** · **[Official page](https://hostmy.blog/)** · **[Support forum](https://wordpress.org/support/plugin/rankready-ai-llm-seo/)**

</div>

---

## What this does, precisely

RankReady handles the **machine-readability layer** of your site: the files, endpoints, headers and structured data that AI crawlers and agents consume.

It does **not** promise citations, rankings or AI visibility. Nobody can promise those, and we don't claim them anywhere in the plugin. What RankReady guarantees is narrower and verifiable: your content is served in the formats AI clients ask for, your crawler policy is explicit, and your structured data is valid. Whether an engine then quotes you is its decision.

Everything runs in the WordPress admin. **Zero frontend impact** — no API calls on page load, no third-party scripts, no extra requests for visitors.

---

## Features

### llms.txt + llms-full.txt
Serves the [llmstxt.org](https://llmstxt.org) convention at `/llms.txt` (curated index) and `/llms-full.txt` (concatenated Markdown). Configurable post types, max post count, category/tag exclusions, per-domain brand identity. Programmatic override via the `rankready_llms_txt_content` filter.

### Markdown endpoints + content negotiation
Every post is also served as clean Markdown at `/post-slug.md` with YAML frontmatter, plus `Accept: text/markdown` negotiation on the canonical URL and a `<link rel="alternate" type="text/markdown">` discovery tag.

**Reality check on reach:** per [acceptmarkdown.com](https://acceptmarkdown.com)'s matrix, only ~7 clients actually send `Accept: text/markdown` (Claude Code, Copilot Chat/CLI, Cursor, Microsoft Copilot, OpenClaw, OpenCode). ChatGPT browse, Claude.ai, Gemini, Grok and Perplexity do **not**. That's exactly why the distinct `.md` URLs, the `<link rel="alternate">` tag and llms.txt carry the reach — Accept negotiation alone would cover a small minority of traffic.

### AI Summary generation
"Key Takeaways" via OpenAI, Anthropic, Google Gemini or DeepSeek, with Speakable JSON-LD. Unlimited manual generation, no monthly cap, bring your own API key.

### FAQ generation with DataForSEO
Pulls real "People Also Ask" questions for your focus keyword, then your chosen LLM writes the answers. Output is valid FAQPage JSON-LD.

### E-E-A-T author box + schema
Author Box (photo, bio, headline, topics, credentials, year started) plus Article, Speakable, FAQPage, HowTo and ItemList JSON-LD. E-E-A-T here follows [Google's Search Quality Rater Guidelines](https://guidelines.raterhub.com/searchqualityevaluatorguidelines.pdf) — we describe what the guidelines ask for, not what any model does internally.

### 29 AI crawler controls + robots.txt sync
Per-bot allow/block for **29 AI crawlers** — GPTBot, ChatGPT-User, OAI-SearchBot, ClaudeBot, Claude-Web, anthropic-ai, PerplexityBot, Google-Extended, GoogleOther, Applebot-Extended, Bingbot, Meta-ExternalAgent, Meta-ExternalFetcher, FacebookBot, MistralAI-User, Bytespider, Amazonbot, cohere-ai, DuckAssistBot, YouBot, PhindBot, CCBot, AI2Bot, Diffbot, Omgilibot, PetalBot, Brightbot, magpie-crawler, DataForSeoBot. Auto-syncs to both the virtual and physical `robots.txt`, plus Content Signals (`ai-train`, `search`, `ai-input`) per [contentsignals.org](https://contentsignals.org).

Googlebot and FacebookExternalHit are emitted in their **own** group that mirrors your site's `User-agent: *` rules — never folded into the permissive AI group, because a crawler obeys only its most specific matching group (RFC 9309 §2.2.1).

### WebMCP manifest + Google OKF
A [Model Context Protocol](https://modelcontextprotocol.io/) manifest at `/.well-known/mcp.json` listing what agents can do on your site, plus Google's Open Knowledge Format output at `/okf/`.

> **Server note:** nginx blocks dotfile paths by default, so `/.well-known/*` returns 403 on many hosts (RunCloud, stock openresty). No plugin can override that — it needs a server config change. See [Troubleshooting](#troubleshooting).

### Insights — crawler and referral analytics
Training bots, citation bots, real AI referrals (chatgpt.com, perplexity.ai, claude.ai, gemini.google.com, copilot.microsoft.com) and content freshness. 100% server-side, no third-party scripts. A crawler hit tells you your page was **fetched** — not that it was quoted.

### Cache compatibility
WP Rocket · LiteSpeed Cache · W3 Total Cache · WP Super Cache · WP Fastest Cache · Breeze · SG Optimizer · Hummingbird · NitroPack · Perfmatters · Cloudflare APO · Nginx FastCGI · BunnyCDN · Varnish/Fastly · Akamai · Pantheon · Kinsta · WP Engine.

Cache-bypass entries are persisted to each cache plugin's stored config so server-level caches honour the bypass before PHP runs. Full header ruleset: [`docs/CACHE-HEADER-AUDIT.md`](docs/CACHE-HEADER-AUDIT.md).

### Multilingual detection
Detects WPML, Polylang, TranslatePress, Weglot and GTranslate, and emits `hreflang` Link headers for each variant. Per-language llms.txt generation is not yet implemented — only the default language is generated today.

### Diagnostics
26 live probes that actually fetch every endpoint, check status codes and headers, detect SEO-plugin conflicts, inspect edge-cache HIT/MISS, and list `template_redirect` callbacks at priority < 5 that could race our handlers. One-click plaintext report for support tickets.

---

## Works alongside your SEO plugin

| Plugin | How RankReady coexists |
|---|---|
| **Rank Math** | Person + Article schema merged via `rank_math/json_ld` into the existing graph |
| **Yoast SEO** | Merged via `wpseo_schema_graph` — no duplicate Person nodes |
| **All in One SEO** | Merged via `aioseo_schema_output` |
| **SEOPress** | Merged via `seopress_schemas_single_data` |
| **SEO Framework · Slim SEO** | Coexists; RankReady supplies AI-only fields none of them cover |

RankReady never emits a standalone JSON-LD block when one of those is handling schema. You don't replace your SEO plugin — you add RankReady on top.

---

## Installation

1. WordPress admin → **Plugins → Add New** → search **"RankReady"** → Install → Activate
2. Add an AI provider API key (OpenAI, Anthropic, Gemini or DeepSeek) in **Settings**
3. Enable llms.txt, Markdown endpoints and crawler controls in **AI Crawlers**
4. Visit `/llms.txt` on your site to confirm it's serving

Requires WordPress 6.9+ and PHP 7.4+.

---

## Troubleshooting

**`/.well-known/mcp.json` returns 403.** nginx blocks dotfile paths. The tell: `/.well-known/acme-challenge/test` returns 404 (allowed) while everything else returns 403 — a `location ~ /\.` rule with a single Let's Encrypt exception. Fix at the server (`^~` must outrank `location ~ /\.`):

```nginx
location ^~ /.well-known/ {
    allow all;
    default_type text/plain;
    try_files $uri $uri/ /index.php?$args;
}
```

**Cloudflare APO ignores `Vary: Accept`.** APO's cache key is URL + querystring + device type, so negotiation on the canonical URL can't work through it. The plugin detects APO and surfaces the exact Cache Rule to paste. Distinct `.md` URLs are unaffected — they're different URLs.

**Endpoints return the right content under a 404.** Fixed in 1.2.1. If you're on an older build with LiteSpeed + Rank Math, upgrade.

---

## For contributors

```bash
git clone https://github.com/eticastudio/rankready.git
cd rankready
php -l rankready.php          # no build step — vanilla ES5, no JSX/webpack
```

**Architecture:** `rankready.php` holds a PSR-4-style autoloader (`RNRD_Foo` → `includes/class-rnrd-foo.php`). All classes boot on `init`, then `do_action( 'rnrd_loaded' )` fires as the extension point.

**Non-negotiables before any PR:**

- **Backward compatibility is the top rule.** `rnrd_*` options, `_rnrd_*` meta keys, `rnrd_*` hooks, `/wp-json/rankready/v1/*` routes, block `save()` output and public CSS class names are permanent contracts. Renames go through a ≥2-version dual-write/dual-read cycle.
- **Copy honesty.** Claim crawlability, never citations, rankings or visibility. No fabricated statistics. This grep must return empty:
  ```bash
  grep -rnE "[0-9]+×|[0-9]+% more|[0-9]+% of AI|Get cited by|will rank|guaranteed result" includes/*.php assets/*.js readme.txt
  ```
- **Assert contracts, don't assume them.** Every `wp_remote_*` + `json_decode` must check WP_Error, the HTTP status *and* the task-level status (DataForSEO returns HTTP 200 on billing failures). Every `template_redirect` handler that serves a body must call `status_header( 200 )` on success.
- **`(bool) 'off'` is `true`.** Options stored by `sanitize_on_off` are the strings `'on'`/`'off'` — always compare `'on' === get_option(...)`.
- **Never load admin code on the frontend.** Gate admin assets by post type, not by hook — every CPT shares `post.php`.
- **No duplicate schema.** Check for Rank Math / Yoast / AIOSEO and merge via their filters.
- **Every REST route needs a real permission callback.** Never `__return_true`.

**Release process — WordPress.org goes first, GitHub mirrors after.** Never tag a version or publish a GitHub release before that exact version is approved and live on WordPress.org. A GitHub tag for a version WP.org later rejects means users install a non-compliant build via Composer, WP-CLI or Git Updater. Builds are cut with `build-release.sh` and must pass `orbit-wporg-release-gate` with zero errors on the extracted zip.

---

## Privacy & third-party services

Everything stays on your own site. Your API keys, DataForSEO credentials, generated summaries, FAQs and author profiles live in your own `wp_options` and `wp_postmeta` tables. We don't see, collect or transmit your data.

Third-party services are contacted **only** when you enter credentials and trigger a generation action: [OpenAI](https://openai.com/policies/privacy-policy) · [Anthropic](https://www.anthropic.com/legal/privacy) · [Google Gemini](https://policies.google.com/privacy) · [DeepSeek](https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html) · [DataForSEO](https://dataforseo.com/terms-of-service). Plugin usage data goes to [Freemius](https://freemius.com/privacy/) only if you opt in on activation. Full disclosure in [`readme.txt`](readme.txt).

---

## Releases & maintenance

**RankReady is maintained on WordPress.org, which is its canonical home.** Install and auto-update from there. This repository is the public source mirror for code review, issues and community contributions; tagged releases here always match the version approved on WordPress.org and are never ahead of it.

| Version | Highlights | Links |
|---|---|---|
| **1.3.0** *(current)* | Reorganised settings and post-edit metaboxes, in-editor Generate Summary/FAQ, homepage Markdown, AI Snippet defaults, Freemius opt-in | [WordPress.org](https://wordpress.org/plugins/rankready-ai-llm-seo/) · [Release notes](https://github.com/eticastudio/rankready/releases/tag/v1.3.0) |
| 1.1.2 | Google Open Knowledge Format (OKF) bundle at `/okf/`, auto-synced on publish | [Release notes](https://github.com/eticastudio/rankready/releases/tag/v1.1.2) |
| 1.0.1 | Cache content-negotiation fix, homepage Markdown URL fix, full cache-header audit | [Release notes](https://github.com/eticastudio/rankready/releases/tag/v1.0.1) |

- **Install or update:** [WordPress.org → rankready-ai-llm-seo](https://wordpress.org/plugins/rankready-ai-llm-seo/)
- **Full changelog:** [WordPress.org Changelog tab](https://wordpress.org/plugins/rankready-ai-llm-seo/#developers)
- **Report a bug:** [WordPress.org support forum](https://wordpress.org/support/plugin/rankready-ai-llm-seo/) or [GitHub Issues](https://github.com/eticastudio/rankready/issues)

> Requires WordPress **6.9+** and PHP **7.4+**. Tested up to WordPress 7.1.

---

## License

GPL-2.0-or-later. Full text in [LICENSE](LICENSE).

---

<div align="center">

Built and maintained by **[HostMyBlog](https://hostmy.blog/)** — an [Etica Studio](https://github.com/eticastudio) product.

</div>
