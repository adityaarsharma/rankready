# RankReady – LLM SEO, EEAT & AI Optimization

**The WordPress plugin that gets your content cited by AI.**

RankReady is the most complete WordPress plugin for AI search optimization. It combines all pillars of LLM SEO into a single, lightweight package: AI-generated content, intelligent schema markup that auto-detects your content type, LLMs.txt, Markdown endpoints, AI crawler management, content freshness monitoring, and a full **EEAT Author Box with Person JSON-LD schema** that powers author identity for ChatGPT, Perplexity, and Google AI Overviews citations.

[![WordPress](https://img.shields.io/badge/WordPress-6.2%2B-blue.svg)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)
[![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)
[![Version](https://img.shields.io/badge/Version-0.6.1-orange.svg)](https://github.com/adityaarsharma/rankready/releases)
[![Auto-Updates](https://img.shields.io/badge/auto--updates-via%20GitHub%20releases-success.svg)](#auto-updates)
[![Changelog](https://img.shields.io/badge/changelog-Keep%20a%20Changelog-brightgreen.svg)](CHANGELOG.md)

> **Status: v0.6 — public beta.** RankReady is free and open-source (GPL-2.0). Download the latest release zip below. Pro features (license-gated) arrive in v1.0. Auto-updates flow from GitHub releases to every install — no token, no config, no setup.

---

## Why RankReady Exists

AI search is replacing traditional search. ChatGPT, Perplexity, Google AI Overviews, and Claude are now how people find information. But most WordPress sites are invisible to these AI engines.

**The research is clear:**
- 81% of AI-cited pages include schema markup (AccuraCast 2025)
- Pages with FAQPage schema are 3.2x more likely to appear in AI Overviews
- 65% of AI citations target content updated within the past year
- 44% of citations come from the top third of the page
- ChatGPT search results correlate 87% with Bing's top 10
- AI agent traffic grew 6,900% year-over-year in 2025
- Incomplete schema causes an 18% citation penalty vs. no schema at all

RankReady handles all of this automatically.

---

## What Makes RankReady Different

No other WordPress plugin combines all of these:

| Capability | RankReady | Rank Math | Yoast | AIOSEO | LLMagnet | LovedByAI |
|-----------|-----------|-----------|-------|--------|----------|-----------|
| AI Summary Generation (OpenAI) | Yes | No | No | No | No | No |
| FAQ Generator with Brand Injection | Yes | No | No | No | No | No |
| FAQPage JSON-LD Schema | Yes | Block only | Block only | Block only | No | No |
| Article Schema + Speakable | Yes | Partial | Partial | Partial | No | No |
| **EEAT Author Box + Person JSON-LD** | **Yes** | Basic | Basic | Basic | No | No |
| **sameAs priority ordering (Wikidata-first)** | **Yes** | No | No | No | No | No |
| **HowTo Schema Auto-Detection** | **Yes** | Manual block | Manual block | No | No | No |
| **ItemList Schema Auto-Detection** | **Yes** | No | No | No | No | No |
| LLMs.txt + llms-full.txt | Yes | Basic | Basic | Basic | Yes | No |
| Markdown Endpoints (.md) | Yes | No | No | No | No | No |
| Per-Crawler Robots.txt (31 bots) | Yes | No | 3 bots | No | No | No |
| Content Freshness Alerts | Yes | No | No | No | No | No |
| Bulk Author Changer (EEAT) | Yes | No | No | No | No | No |
| **Global fonts in Gutenberg blocks (theme.json)** | **Yes** | N/A | N/A | N/A | No | No |
| Content Negotiation (Accept header) | Yes | No | No | No | No | No |
| **Headless REST API (Next.js / Nuxt)** | **Yes** | No | No | No | No | No |
| **WPGraphQL Fields** | **Yes** | No | No | No | No | No |
| **On-Demand Revalidation Webhook** | **Yes** | No | No | No | No | No |
| DataForSEO + OpenAI Usage Tracking | Yes | N/A | N/A | N/A | No | No |
| Health Check Diagnostic | Yes | No | No | No | No | No |
| **Auto-updates from GitHub releases** | **Yes** | wp.org | wp.org | wp.org | No | No |

---

## Features

### 1. FAQ Generator with Brand Entity Injection

Auto-generates SEO-optimized FAQ Q&A pairs using a two-stage pipeline:

1. **DataForSEO** discovers real search questions (keyword suggestions + related keywords)
2. **OpenAI** generates answers using your actual page content + brand terms

**Why it matters:**
- FAQPage schema drives 3.2x more AI Overview appearances than any other schema type
- Brand entity injection uses semantic triples (Subject-Predicate-Object) to naturally associate your brand with relevant topics
- Focus keyword auto-detected from Rank Math, Yoast, AIOSEO, or SEOPress
- Content hash prevents duplicate API calls when content hasn't changed
- Bulk generation across all post types with resume capability
- **Auto-Generate on Publish toggle** (added in 0.5.x) — off by default, mirrors the AI Summary auto-generate behavior

### 2. AI Summary (Key Takeaways)

Generates concise bullet-point summaries from post content via OpenAI on publish/update.

- Content-hash caching: only regenerates when content changes
- Custom prompt support for controlling summary style and tone
- Included in Markdown endpoints and schema output
- Bulk regenerate with progress tracking and resume
- Per-post disable toggle

### 3. Intelligent Schema Auto-Detection

RankReady automatically detects your content type and injects the right schema — no manual blocks, no configuration needed.

#### Article JSON-LD Schema with Speakable

Complete Article/BlogPosting schema with AI citation optimization:

- `headline`, `datePublished`, `dateModified`, `author`, `publisher`, `image`, `description`
- `speakable` markup for voice-query optimization
- `about` entities from hierarchical taxonomies (categories) — works with ALL custom post types
- `mentions` entities from non-hierarchical taxonomies (tags) — works with ALL custom taxonomies
- `hasPart` with AI summary content
- Automatic detection: skips when Rank Math, Yoast, or AIOSEO is active (no duplicate schema)
- FAQPage schema injected separately when FAQ data exists
- Duplicate detection: skips FAQ schema when Rank Math/Yoast/AIOSEO FAQ blocks exist in content

#### HowTo JSON-LD Schema (Auto-Detected)

Scans your existing post content for step-by-step patterns and injects HowTo schema automatically. No manual HowTo blocks needed — if your content already has steps, RankReady detects them.

**How detection works:**
- Title must contain "how to", "step by step", "tutorial", or "guide to"
- Content is scanned for step patterns using three methods:
  1. **Step N headings**: `<h2>Step 1: Do this</h2>`, `<h3>Step 2 - Do that</h3>`
  2. **Numbered headings**: `<h2>1. Do this</h2>`, `<h3>2) Do that</h3>`
  3. **Ordered lists**: `<ol><li>First do this</li><li>Then do that</li></ol>`
- Requires minimum 2 steps to inject schema
- Extracts step name, description text (up to 500 chars), and step images automatically
- Skips if Rank Math HowTo block or Yoast How-To block already exists in the post

**Why it matters:**
- HowTo schema is one of Google's supported rich result types — eligible for step-by-step carousels in search
- AI models extract HowTo structured data to build direct answers for "how to" queries
- Rank Math and Yoast require a manual HowTo block — RankReady auto-detects from your existing content
- Zero content changes needed — your blog posts already have the steps, RankReady just tells search engines about them

#### ItemList JSON-LD Schema (Auto-Detected)

Scans your listicle posts and injects ItemList schema automatically. Perfect for "Best of", "Top N", and comparison posts that AI models use to build recommendation answers.

**How detection works:**
- Title must match listicle patterns:
  - Number + qualifier: "10 Best WordPress Plugins", "Top 5 Elementor Addons"
  - Qualifier + number: "Best 10 Tools for SEO", "Top 5 Page Builders"
  - Number + noun: "7 Plugins Every Developer Needs", "15 Tips for Faster WordPress"
  - Qualifier without number: "Best Elementor Addons for 2025", "Top WordPress Themes"
  - Supports 20+ list nouns: tools, plugins, addons, themes, extensions, widgets, alternatives, tips, tricks, strategies, and more
- Items extracted from content using two methods:
  1. **Numbered headings**: `<h2>1. Elementor Pro</h2>`, `<h3>#2 Beaver Builder</h3>`
  2. **Consecutive headings**: Sequences of 3+ h2/h3 headings (auto-filters generic sections like Introduction, FAQ, Conclusion)
- Requires minimum 3 items to inject schema
- Extracts per item: name, URL (prioritizes links matching the item name), description (first paragraph, 200 chars), and image

**Why it matters:**
- ItemList schema tells Google and AI models "this is a curated list of items" — eligible for list rich results
- AI models like ChatGPT and Perplexity heavily cite listicle pages when answering "best X for Y" queries
- Products mentioned in ItemList schema get stronger entity recognition in AI knowledge graphs
- No other WordPress plugin auto-detects listicle content — Rank Math, Yoast, and AIOSEO don't offer this

**Mutually exclusive with HowTo**: A post gets one schema type or the other based on title patterns. If the title matches "how to" patterns, ItemList detection is skipped. This prevents conflicting schema on the same page.

### 4. LLMs.txt Generator

Serves `/llms.txt` and `/llms-full.txt` per the [llmstxt.org specification](https://llmstxt.org/).

- Structured site index for AI models to understand your content
- `/llms.txt` — token-efficient links and descriptions
- `/llms-full.txt` — full content as clean markdown (uses `strip_shortcodes()` for performance)
- Respects noindex from Rank Math, Yoast, AIOSEO, SEOPress
- Taxonomy controls: exclude categories and tags
- Transient caching with configurable TTL
- Multisite-safe: skips physical robots.txt sync on multisite

### 5. Markdown Endpoints

Every post available as clean Markdown at `URL.md`:

```
https://example.com/my-post/     -> HTML
https://example.com/my-post.md   -> Clean Markdown
```

- YAML frontmatter: title, date, author, description, categories, tags, word count
- Strips Elementor, Divi, WPBakery, Beaver Builder markup
- Content negotiation via `Accept: text/markdown` header
- `<link rel="alternate" type="text/markdown">` auto-discovery
- 5-minute transient cache keyed by `post_modified` — no repeated processing on bot crawls

### 6. AI Crawler Management (robots.txt)

Per-crawler toggles for **31 AI bots** with automatic robots.txt management:

| Company | Bots |
|---------|------|
| **OpenAI** | GPTBot, ChatGPT-User, OAI-SearchBot |
| **Anthropic** | ClaudeBot, anthropic-ai, Claude-Web |
| **Google** | Google-Extended, GoogleOther |
| **Apple** | Applebot-Extended |
| **Microsoft** | Bingbot |
| **Perplexity** | PerplexityBot |
| **Meta** | Meta-ExternalAgent, Meta-ExternalFetcher, FacebookBot |
| **Mistral** | MistralAI-User |
| **ByteDance** | Bytespider |
| **Amazon** | Amazonbot |
| **Cohere** | cohere-ai |
| **Search Engines** | DuckAssistBot, YouBot, PhindBot |
| **Training/Data** | CCBot, AI2Bot, Diffbot, Omgilibot, PetalBot, Brightbot, magpie-crawler, DataForSeoBot |

**Strategy support:** Block training bots (GPTBot) while allowing search bots (OAI-SearchBot, ChatGPT-User). Per RFC 9309 spec. Append-only, never modifies existing plugin rules.

### 7. Content Freshness Alerts

Monitor content staleness that impacts AI visibility:

- 65% of AI citations target content from the past year
- 50% of citations are from content less than 13 weeks old
- Configurable threshold: 60, 90, 180, or 365 days
- Urgency levels: critical (>1yr), high (>6mo), moderate (>threshold)
- Shows summary/FAQ status per post
- Fresh percentage dashboard
- Direct edit links for stale posts

### 8. Bulk Author Changer (EEAT)

Reassign post authors across any post type for E-E-A-T optimization:

- Filter by post type, date range, source author
- Preview count before executing
- Batch processing with progress tracking
- Capped at 10,000 posts per operation

### 9. Tools Dashboard

- **Health Check**: 12-point diagnostic scan of all plugin features
- **API Usage Tracking**: OpenAI tokens + DataForSEO cost monitoring
- **Error Log**: Recent API errors with source, timestamp, post reference
- **Bulk Operations**: Summary, FAQ, and Start Over with resume capability

### 10. Headless WordPress Public API

Production-grade read-only REST API built for headless WordPress — Next.js, Nuxt, Astro, SvelteKit, Gatsby, Faust.js, WPEngine Atlas, and any frontend where the backend domain is separate from the rendering layer.

**Endpoints** (namespace `rankready/v1/public/`):

| Method | Route | Purpose |
|--------|-------|---------|
| GET | `/faq/{id}` | FAQ items for a post |
| GET | `/summary/{id}` | AI summary for a post |
| GET | `/schema/{id}` | Ready-to-inject JSON-LD (FAQPage + HowTo + ItemList) |
| GET | `/post/{id}` | Combined payload (FAQ + summary + schemas) in one request |
| GET | `/post-by-slug/{slug}` | Lookup by slug with `post_type` and `lang` filters |
| GET | `/list` | Paginated list for SSG / ISR build steps |
| POST | `/revalidate` | Manual revalidation trigger (shared secret required) |

**Also exposes** `rankready_faq`, `rankready_summary`, `rankready_schema` as native REST fields on `/wp/v2/posts/{id}` — so Faust.js and any existing REST consumer picks them up automatically.

**Enterprise features:**

- **HTTP caching**: ETag (weak, payload + version hash), Last-Modified, `Cache-Control: public, s-maxage=N, stale-while-revalidate=86400`, automatic `304 Not Modified` on matching `If-None-Match` / `If-Modified-Since`
- **CORS hardening**: Allowlist from settings, `Vary: Origin`, `Access-Control-Expose-Headers: ETag, Last-Modified, X-RR-*`
- **Rate limiting**: Transient per-IP (default 120 req/min, configurable), real IP detection via `CF-Connecting-IP` / `X-Forwarded-For` / `X-Real-IP`, authenticated editors exempt, `429` + `Retry-After` on exceed
- **On-Demand Revalidation**: Fire-and-forget POST to Next.js / Nuxt endpoint when FAQ / summary / schema changes. `blocking=>false, timeout=>0.01` so the editor flow never waits. `X-RR-Secret` header verified with `hash_equals()` on the frontend.
- **WPGraphQL integration**: Conditional `rankReadyFaq`, `rankReadySummary`, `rankReadySchema` fields on every public post type when WPGraphQL is active
- **Multilingual**: Polylang + WPML support — `lang` query arg on slug / list endpoints, translations map in combined payload
- **RFC 7807 errors**: 4xx / 5xx responses on `/public/` routes transformed to `application/problem+json`
- **Security**: Per-page capped at 100, password-protected posts return 403, `hash_equals()` for secrets, no admin / PII in any response
- **Observability**: `X-RR-Version`, `X-RR-Request-Id`, `X-RR-Cache` headers for debugging

**Off by default.** Enable in `RankReady > Headless` tab. Configure CORS origins, cache TTL, rate limit, revalidate webhook URL + secret, and optional WPGraphQL fields.

### 11. EEAT Author Box with Person JSON-LD Schema

A full EEAT author identity system that maps every profile field directly to Schema.org Person data. Built for AI citation — the 2026 citation research is clear that authors with verifiable identity, structured credentials, and priority-ordered `sameAs` links get cited ~23% more by Perplexity and show up dramatically more in Google AI Overviews.

**How it works:**

1. **Fill the author profile** — Every WordPress user gets a new "RankReady Author Box" section on `user-edit.php` with 23 schema-mapped fields (job title, employer, bio, headshot, started year, topics of expertise, credentials suffix, education repeater, certifications repeater, memberships repeater, awards repeater, Wikidata QID, Wikipedia, ORCID, Google Scholar, LinkedIn, GitHub, YouTube, X, personal site, contact form URL). Every field has an inline description explaining what EEAT signal it emits.

2. **Add the block or widget** — Insert the `RankReady Author Box` Gutenberg block or the matching Elementor widget on any post, or enable auto-display globally from `RankReady → Author Box` settings.

3. **RankReady emits Person JSON-LD** — On every article the author appears in `Article.author` as a full Person node with `jobTitle`, `worksFor` (Organization), `description`, `image`, `knowsAbout`, `sameAs`, `alumniOf`, `hasCredential`, `memberOf`, `award`, `contactPoint`, `publishingPrinciples`, and `identifier` PropertyValue for both ORCID and Wikidata. On `is_author()` archive pages, a `ProfilePage { mainEntity: Person }` node is emitted via `wp_head` (zero visible template override — RankReady never overrides author archive templates).

**Priority-ordered `sameAs`** (research-backed):

```
Wikidata → Wikipedia → ORCID → Google Scholar → LinkedIn → GitHub → YouTube → X → personal site
```

The first three are the canonical entity anchors LLMs actually reuse. Wikidata URIs get the #1 slot because Wikidata QIDs are what Google's Knowledge Graph and every major LLM internally resolve entities against.

**ORCID dual emission** — When ORCID is set, RankReady emits both the orcid.org URL in `sameAs` AND an `identifier` PropertyValue with `propertyID: "ORCID"`. This two-channel emission significantly improves disambiguation in academic citation contexts.

**Per-post Author Trust fields** — Post editor sidebar gets a new panel with three fields, all backed by registered post meta exposed to the block editor:

- **Fact-checked by** (user picker) → `Article.reviewedBy[]`
- **Reviewed by** (user picker) → `Article.reviewedBy[]`
- **Last reviewed** (date) → `Article.lastReviewed`

Both reviewer fields serialize into `reviewedBy[]` as full Person nodes (each carries their own credentials, sameAs, memberOf, award), and both also render in the Author Box display as a "Fact-checked by X · Reviewed by Y · Last reviewed [date]" line (Healthline pattern).

**Zero schema conflict with other SEO plugins** — RankReady never emits a duplicate Person node. When Rank Math, Yoast SEO, AIOSEO, SEOPress, The SEO Framework, or Slim SEO is active, RankReady hooks into each plugin's schema graph filter and **enhances the existing Person node in place** with its own `sameAs`, `knowsAbout`, `alumniOf`, `hasCredential`, `memberOf`, `award` — never overwriting existing fields. When no SEO plugin is active, RankReady emits its own Person node inline on articles plus a `ProfilePage` on author archives.

**Three layouts:**

- **Card** — Full end-of-article box: 96px headshot, name + credentials suffix, job title + employer + years of experience, bio, topics of expertise pills, education + certifications rows, social icons, reviewed-by line, editorial policy + fact-check footer links
- **Compact** — Small sidebar-ready variant: 64px headshot, condensed byline, bio, social icons
- **Inline byline** — Healthline-style above-the-fold row: 40px headshot, "By [Name]" + job title + reviewed-by line, no bio/credentials/box

**Configurable fields per block/widget** — Every section (headshot, job title, employer, years of experience, bio, expertise tags, credentials, social icons, reviewed-by) has an individual toggle so you can build a minimal byline or a maximal card from the same data source.

### 12. Gutenberg Typography Parity with theme.json Global Fonts

The Gutenberg Summary, FAQ, and Author Box blocks ship full typography controls that previously only existed in the Elementor widgets. Every text layer (Summary label, Summary bullets, FAQ question, FAQ answer, Author Box heading, name, meta, bio) exposes:

- **Font Family** — Dropdown populated from `wp.data.select('core/block-editor').getSettings().__experimentalFeatures.typography.fontFamilies`, reading the Theme, Custom, and Default groups. Any block theme that registers global fonts via `theme.json` shows up automatically — **Kadence, Astra, GeneratePress, Twenty Twenty-Four, and every other block theme** — with a `"Theme — Inter"` / `"Custom — Space Grotesk"` label so you can see which group each font comes from.
- **Font Weight** — 100 Thin through 900 Black
- **Font Size (px)** — with `0 = inherit from theme` semantics so you can leave it on the theme default
- **Line Height**
- **Letter Spacing (px)** — on Summary label and bullets
- **Text Transform** — none / uppercase / lowercase / capitalize on Summary label

Every new control carries an inline `help:` description so end users don't have to guess what each one does. Backwards compatible — unset values cascade to the theme.

---

## Schema Auto-Detection: How It Decides

RankReady reads your post title and content, then picks the right schema automatically:

```
Post Published/Updated
    |
    |-- Is Rank Math / Yoast / AIOSEO active?
    |     |-- YES -> Skip Article schema (SEO plugin handles it)
    |     |-- NO  -> Inject Article + Speakable JSON-LD
    |
    |-- Does FAQ data exist for this post?
    |     |-- YES -> Does Rank Math/Yoast FAQ block exist in content?
    |     |         |-- YES -> Skip FAQPage schema (SEO plugin handles it)
    |     |         |-- NO  -> Inject FAQPage JSON-LD
    |     |-- NO  -> Skip
    |
    |-- Title contains "how to", "tutorial", "step by step"?
    |     |-- YES -> Does Rank Math/Yoast HowTo block exist?
    |     |         |-- YES -> Skip (SEO plugin handles it)
    |     |         |-- NO  -> Extract steps from content -> Inject HowTo JSON-LD
    |     |-- NO  -> Continue
    |
    |-- Title matches listicle pattern ("Best N", "Top N", "N Tools")?
          |-- YES -> Extract items from headings -> Inject ItemList JSON-LD
          |-- NO  -> Skip
```

**Every schema type has duplicate detection.** RankReady never conflicts with Rank Math, Yoast, or AIOSEO. If a competing plugin already handles a schema type, RankReady steps aside.

---

## How AI Discovers Your Content

```
AI Crawler visits your site
    |
    |-- robots.txt
    |     |-- Allow: /llms.txt
    |     |-- Allow: /llms-full.txt
    |     |-- Allow: /*.md$
    |
    |-- /llms.txt (structured site index)
    |     |-- Links to every published post
    |     |-- Links to /llms-full.txt
    |
    |-- /llms-full.txt (full content dump)
    |     |-- Every post as inline markdown
    |
    |-- /any-post.md (per-post markdown)
    |     |-- YAML frontmatter
    |     |-- AI Summary (key takeaways)
    |     |-- Clean content
    |
    |-- HTML page
          |-- Article JSON-LD + Speakable schema
          |-- FAQPage JSON-LD (if FAQ data exists)
          |-- HowTo JSON-LD (if tutorial/how-to post)
          |-- ItemList JSON-LD (if listicle post)
          |-- <link rel="alternate" type="text/markdown">
          |-- Link HTTP header to .md version
          |-- Accept: text/markdown negotiation
```

---

## <a id="auto-updates"></a>Auto-Updates (no token, no config)

Every install of RankReady auto-updates from the GitHub releases on this repo. **No GitHub token required.** No per-site configuration. Install once and forget.

```
                               git push --tags
                                      |
                                      v
   +-------------------------------------------------------------+
   | GitHub Action (.github/workflows/release.yml)               |
   |  1. Validate plugin header / RR_VERSION / readme.txt match  |
   |  2. Stage rankready/ folder (rsync, exclude .git/.github)   |
   |  3. Build rankready-X.Y.Z.zip with correct folder structure |
   |  4. Extract changelog section for X.Y.Z                     |
   |  5. Create GitHub release, attach the zip                   |
   +-------------------------------------------------------------+
                                      |
                                      v
   +-------------------------------------------------------------+
   | Plugin Update Checker (PUC) bundled with the plugin         |
   |  1. Polls GitHub releases API once per day (anonymous)      |
   |  2. Compares latest release tag vs RR_VERSION               |
   |  3. If newer, surfaces "Update available" in WP Admin       |
   |  4. WordPress installs the rankready-X.Y.Z.zip release      |
   |     asset like any normal plugin update                     |
   +-------------------------------------------------------------+
```

PUC is bundled inside `vendor/plugin-update-checker/` and wired in `rankready.php`. It uses GitHub's anonymous public API (60 requests/hour limit — way more than enough for daily checks). To force an immediate check on a site, visit:

```
/wp-admin/plugins.php?puc_check_for_updates=1&puc_slug=rankready
```

**License gating roadmap.** When v1.0 ships with paid Pro features, the same PUC integration will accept an optional license key parameter. Free features will keep updating from public releases for everyone. Paid Pro features will validate the license key at runtime and unlock additional functionality. The update mechanism itself stays free and public.

---

## Installation

1. Download the latest release zip from [Releases](https://github.com/adityaarsharma/rankready/releases/latest)
2. **Plugins → Add New → Upload Plugin** in WordPress admin
3. Activate and go to **RankReady** in the admin menu
4. Configure:
   - **Settings tab**: OpenAI key + DataForSEO credentials
   - **Content AI tab**: AI Summary and FAQ Generator — post types, prompts, brand terms, auto-generate toggles
   - **Authority tab**: Author Box EEAT profile fields, layouts, typography
   - **AI Crawlers tab**: LLMs.txt, Markdown endpoints, per-bot robots.txt controls
   - **Advanced tab**: Bulk operations, freshness alerts, health check, headless API

After install, future updates appear inline in WP Admin → Plugins like any plugin from wordpress.org. Click "update now" and it installs the latest GitHub release zip automatically.

---

## Requirements

- WordPress 6.2+
- PHP 7.4+
- OpenAI API key (for summary + FAQ generation)
- DataForSEO credentials (for FAQ question discovery)

---

## Security

- All REST endpoints require authentication + capability checks
- `$wpdb->prepare()` with positional placeholders on all dynamic SQL
- `sanitize_callback` on all REST route parameters
- `esc_html()`, `esc_attr()`, `esc_url()` on all output
- API keys never exposed in REST responses or health check output
- Bulk operations capped at 10,000 posts
- Physical robots.txt managed via WP_Filesystem API
- Multisite guard on robots.txt sync
- `flush_rewrite_rules()` deferred to `init` hook (prevents corrupting other plugins' rules)
- Clean uninstall removes all options and post meta
- Duplicate-install guard — if two RankReady folders end up in the plugins directory, the second copy bails out cleanly with an admin notice instead of fataling the site

---

## Compatibility

**SEO Plugins** (read-only integration):
- Rank Math, Yoast SEO, AIOSEO, SEOPress (free + Pro), The SEO Framework, Slim SEO
- Auto-detects focus keywords, respects noindex, prevents schema duplication
- HowTo/ItemList schema skips injection when competing plugin's blocks exist

**Page Builders** (strips wrapper markup in markdown):
- Elementor, Divi, WPBakery, Beaver Builder, Gutenberg

**Display Options**:
- Gutenberg blocks (Summary + FAQ + Author Box) with full style controls and theme.json global font support
- Elementor widgets (Summary + FAQ + Author Box) with native `Group_Control_Typography`
- Auto-display above or below content

**Block Themes with Global Font Support** (tested — fonts registered via `theme.json` appear automatically in the Gutenberg Author Box / Summary / FAQ blocks):
- Kadence, Astra, GeneratePress, Twenty Twenty-Four, Twenty Twenty-Three, Blocksy, Hello Elementor, and any theme that registers fonts in `theme.json`

---

## Developer Filters

```php
// Force RankReady's llms.txt even if another plugin handles it
add_filter( 'rankready_force_llms_txt', '__return_true' );

// Exclude specific posts from llms.txt
add_filter( 'rankready_exclude_from_llms', function( $exclude, $post ) {
    if ( $post->post_type === 'landing-page' ) return true;
    return $exclude;
}, 10, 2 );

// Hide the "View as Markdown" link
add_filter( 'rankready_show_md_link', '__return_false' );

// Disable schema injection for specific posts
add_filter( 'rankready_inject_schema', function( $inject, $post ) {
    if ( $post->ID === 123 ) return false;
    return $inject;
}, 10, 2 );

// Disable HowTo schema auto-detection
add_filter( 'rankready_inject_howto_schema', '__return_false' );

// Disable ItemList schema auto-detection
add_filter( 'rankready_inject_itemlist_schema', '__return_false' );

// Customize ItemList schema output
add_filter( 'rankready_itemlist_schema', function( $schema, $post ) {
    $schema['itemListOrder'] = 'https://schema.org/ItemListOrderDescending';
    return $schema;
}, 10, 2 );
```

**Author Box integration points:**

```php
// Programmatically read the full Person schema for any user.
$person = RR_Author_Box::build_person_schema( $user_id );

// Render the author box HTML for any user + post combination.
echo RR_Author_Box::render_html(
    $user_id,
    array(
        'layout'         => 'card',         // card | compact | inline
        'showHeadshot'   => true,
        'showBio'        => true,
        'showCredentials'=> true,
        'showSocials'    => true,
        'showReviewed'   => true,
    ),
    $post_id
);
```

All profile fields are registered user meta with `show_in_rest => true`, so the block editor and headless consumers can read them via `/wp/v2/users/{id}` → `meta.rr_author_*`.

---

## Roadmap to v1.0

- **v0.5.x** — Internal dev releases. Feature-complete free plugin. Public repo, public auto-updates, no licensing.
- **v0.6 – v0.9** — Add AI Crawler Analytics dashboard, LLM Citation Tracking (via DataForSEO `ai_optimization_llm_mentions`), Content AI-Readiness Score, Bing ranking gap analysis.
- **v1.0** — Public launch. Same free plugin everyone gets today + paid Pro features gated by license key. License keys validated at runtime by the same plugin. Updates stay free and public for the free version. Pro features stay locked unless a valid key is present.

License gating in v1.0 is **runtime, not download-time**. The same zip is shipped to free users and paid users. The license key only changes which features activate.

---

## Changelog

Full release history lives in [**CHANGELOG.md**](CHANGELOG.md) (Keep a Changelog format).
Downloadable builds are published to [**GitHub Releases**](https://github.com/adityaarsharma/rankready/releases) with the plugin zip attached to each release.

---

## Versioning & Releases

RankReady follows [Semantic Versioning](https://semver.org/). Version numbers live in three places and must stay in sync:

1. `rankready.php` — `Version:` header + `RR_VERSION` constant
2. `readme.txt` — `Stable tag:` line
3. `CHANGELOG.md` — add a new `## [X.Y.Z] - YYYY-MM-DD` section under `## [Unreleased]`

### Automated release

A GitHub Actions workflow (`.github/workflows/release.yml`) watches for version tags and handles everything else automatically. The full release flow is:

```bash
# 1. Bump version in the three files above, commit
git commit -am "vX.Y.Z: <one-line summary>"

# 2. Tag and push — that's it
git tag vX.Y.Z
git push origin main --follow-tags
```

The workflow will:
1. Verify header Version, `RR_VERSION` constant, and readme.txt Stable tag all match the pushed tag (fails fast on mismatch)
2. Extract the matching section from `CHANGELOG.md` as the release notes
3. Build a clean `rankready-X.Y.Z.zip` (excludes `.git`, `.github`, dev configs)
4. Create a GitHub Release with the zip attached
5. PUC on every install picks up the new release on its next daily poll and surfaces it as an inline plugin update

Tag patterns that trigger it: `0.5.1`, `1.0.0`, `v0.5.1`, etc.

---

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
