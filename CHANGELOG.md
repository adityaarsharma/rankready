# Changelog

All notable changes to RankReady are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- **CI**: `.github/workflows/release.yml` — auto-builds `rankready-<version>.zip` and publishes a GitHub Release with release notes pulled from `CHANGELOG.md` whenever a version tag is pushed. Verifies plugin header Version, `RR_VERSION` constant, and `readme.txt` Stable tag all match the tag before building.

## [1.5.4] - 2026-04-11

### Added — Enterprise Headless WordPress support

Production-grade public read-only REST API for Next.js, Nuxt, Astro, SvelteKit, Gatsby, Faust.js, Atlas, and any other headless frontend where the WordPress backend domain is separate from the rendering layer.

- New public REST namespace `rankready/v1/public/` with seven endpoints:
  - `GET /faq/{id}` — FAQ items for a post
  - `GET /summary/{id}` — AI summary for a post
  - `GET /schema/{id}` — Ready-to-inject JSON-LD (FAQPage + HowTo + ItemList)
  - `GET /post/{id}` — Combined payload (FAQ + summary + schemas) in a single request
  - `GET /post-by-slug/{slug}?post_type=&lang=` — Slug lookup with post type and language filter
  - `GET /list?post_type=&per_page=&page=&since=` — Paginated list for SSG / ISR build steps
  - `POST /revalidate` — Manual revalidation trigger (shared secret required)
- `rankready_faq`, `rankready_summary`, `rankready_schema` registered as public REST fields on every public post type so core `/wp/v2/posts/{id}` responses carry the data natively (Faust.js compatible).
- HTTP caching layer:
  - Weak `ETag` generated from payload hash + plugin version
  - `Last-Modified` from `post_modified_gmt`
  - `Cache-Control: public, s-maxage=N, stale-while-revalidate=86400`
  - `304 Not Modified` on matching `If-None-Match` or `If-Modified-Since`
- CORS hardening:
  - Allowlist from `rr_headless_cors_origins` (comma-separated URLs)
  - `Vary: Origin` so CDNs cache per-origin
  - `Access-Control-Expose-Headers: ETag, Last-Modified, Cache-Control, X-RR-*`
  - Wildcard allowed when no origins configured (endpoints are read-only)
- Rate limiting:
  - Transient-based per-IP bucket (default 120 req/min, configurable)
  - Real IP detection honoring `CF-Connecting-IP`, `X-Forwarded-For`, `X-Real-IP`
  - Authenticated editors bypass limiting
  - Returns `429 Too Many Requests` with `Retry-After` on exceed
- On-Demand Revalidation webhook:
  - Fire-and-forget POST to Next.js / Nuxt revalidation endpoint on `_rr_faq`, `_rr_summary`, `_rr_schema_data` meta updates and `save_post`
  - `X-RR-Secret` shared secret authentication, verified with `hash_equals()`
  - `blocking=>false, timeout=>0.01` so the editor flow never waits
- WPGraphQL integration (conditional):
  - Registers `rankReadyFaq`, `rankReadySummary`, `rankReadySchema` fields on every public post type when WPGraphQL is active and the toggle is enabled
- Multilingual support:
  - Polylang: `pll_get_post_language()` and `pll_get_post_translations()`
  - WPML: `wpml_post_language_details` and `wpml_element_trid` filters
  - `lang` query arg on slug and list endpoints
  - Translations map included in combined post payload
- RFC 7807 Problem Details error format:
  - 4xx and 5xx responses on `/public/` routes transformed to `application/problem+json`
  - Returns `{ type, title, status, detail, instance, retry_after }`
- Observability headers: `X-RR-Version`, `X-RR-Request-Id`, `X-RR-Cache`
- New admin tab **Headless** with: master enable toggle, CORS origins textarea, core REST meta toggle, cache TTL, rate limit, revalidate URL and secret (masked), WPGraphQL toggle (auto-disabled when plugin missing), and live endpoint reference.

### Security

- Per-page hard cap of 100 on `/list` endpoint
- Password-protected posts return `403 Forbidden` from public endpoints
- Only published posts of public post types are exposed
- No admin data, secrets, or user PII in any response
- Shared-secret verification uses `hash_equals()` to prevent timing attacks

### Changed

- `RR_Faq::build_faq_schema_array()` extracted from `inject_faq_schema()` so the FAQPage JSON-LD array can be reused by the public REST endpoints.

### Notes

Headless mode is **off by default**. Existing installs are unaffected until the master toggle is turned on in the new Headless tab.

## [1.5.3] - 2026-04-09

### Fixed

- FAQ bulk cron was getting stuck when a post threw a fatal error — queue now persists BEFORE generation so a failed post no longer blocks the entire queue.
- Summary bulk cron had the same stuck-queue bug — now dequeues before generating.
- Uncaught exceptions during FAQ / summary generation no longer kill the cron tick — wrapped in `try` / `catch` with error logging.

### Added

- Batch processing per cron tick (up to 5 FAQ posts or `BULK_BATCH` summaries) within a time budget based on `max_execution_time`.
- Failed post ID tracking (`rr_faq_failed_ids` option, last 100 failures) for debugging stuck queues.
- Cron watchdog heartbeat options (`rr_faq_cron_last_tick`, `rr_bulk_cron_last_tick`) for stuck-state detection.
- Post-generation FAQ validation — rejects items with banned opener patterns (`Yes` / `Sure` / `Of course`), banned filler words (`leverage` / `utilize` / `comprehensive`), "follow the documentation" boilerplate, and one-sentence thin answers.

### Changed

- Stronger search-intent grounding in FAQ prompt — forces 60% of questions from real DataForSEO search queries when available.
- Brand-stuffing banned in FAQ questions: `How do I X in Elementor?` not `Can I use The Plus Addons to X?`.
- FAQ generation temperature lowered from `0.5` to `0.3` for stricter instruction compliance.

## [1.5.2] - 2026-04-08

### Fixed

- Markdown links in FAQ answers now render as clickable HTML links in Gutenberg block, Elementor widget, and auto-display.
- FAQ schema output now strips markdown link syntax cleanly instead of leaving raw brackets.

### Changed

- Banned `Yes, [Brand Name]...` opener pattern in FAQ answer generation.

## [1.5.1] - 2026-04-07

### Fixed

- FAQ generation failing with "No valid FAQ items" — OpenAI returns `faqs` wrapper key which was not handled.
- Added universal JSON response wrapper detection — handles `faq`, `faqs`, `questions`, `items`, and any unknown wrapper key.
- DataForSEO API timeout reduced from 20s to 5s per call to fit within the 30s `max_execution_time` budget.
- OpenAI timeout reduced from 60s to 15s — total worst-case generation time is now 25s (was 100s).

### Changed

- FAQ generation now consistently completes in roughly 9s on shared hosting.

## [1.5] - 2026-04-05

### Added

- **Schema Automation tab** — enable / disable toggles for Article, FAQPage, HowTo, ItemList, and Speakable schema with SEO plugin compatibility guide.
- **HowTo JSON-LD schema auto-detection** — scans post content for step patterns (Step N headings, numbered headings, ordered lists) and injects HowTo schema automatically. Skips when Rank Math or Yoast HowTo blocks already exist.
- **ItemList JSON-LD schema auto-detection** — scans listicle posts (`Best N`, `Top N`, `N Best`) and injects ItemList schema with item names, URLs, descriptions, and images.
- HowTo and ItemList are mutually exclusive — a post gets one or the other based on title patterns.
- Auto-detection of the active SEO plugin (Rank Math, Yoast, AIOSEO) with a visual compatibility status.
- WP-Cron background schema scanner — HowTo / ItemList detection runs every 5 minutes via `wp-cron.php`, zero performance impact on page loads.
- Schema batch size control with dynamic server recommendation (shared hosting: 5, mid-range: 15, VPS: 25).
- Schema scanner progress dashboard — scanned / pending counts, estimated time, next cron run.
- Server resource detection — reads PHP `memory_limit`, `max_execution_time`, PHP version for batch recommendations.
- Smart FAQ algorithm — People Also Ask (PAA) questions from Google SERP, comparison queries, Reddit-style questions.
- Page-type-aware FAQ — auto-detects docs, landing pages, comparisons, tutorials, and blog posts for tailored question styles.
- Multi-source question discovery — PAA + keyword suggestions + related keywords + Google related searches.
- Levenshtein fuzzy deduplication — removes near-duplicate questions (less than 30% string distance).
- Smart question ranking — PAA weighted highest, then comparison queries, then keyword suggestions.
- LLM-optimized answers — each FAQ answer designed as a standalone knowledge unit AI chatbots can cite.
- JSON response wrapper handling — auto-detects `{faq:[...]}`, `{questions:[...]}`, `{items:[...]}` response formats.
- Schema scan REST endpoints — `/schema/status` and `/schema/recommendation` for programmatic access.

### Fixed

- `robots.txt` file operations now use the WP_Filesystem API instead of `file_put_contents` / `file_get_contents` (WordPress.org compliance).

## [1.3] - 2026-03-25

### Added

- **Schema Automation tab** — new admin tab with enable / disable toggles for all 5 schema types and expandable detection guides.
- **HowTo JSON-LD schema auto-detection** — scans post content for step-by-step patterns and injects HowTo schema automatically.
- **ItemList JSON-LD schema auto-detection** — scans listicle posts and injects ItemList schema with item names, URLs, descriptions, and images.
- Developer filters: `rankready_inject_howto_schema`, `rankready_inject_itemlist_schema`, `rankready_itemlist_schema`.

### Changed

- All `robots.txt` file operations now use the WordPress Filesystem API.

## [1.2]

### Added

- **Content Freshness Alerts** — scan for stale posts losing AI visibility (65% of AI citations target content less than 1 year old).
- Expanded AI crawler list — 31 bots (was 22). Added `anthropic-ai`, `GoogleOther`, `Meta-ExternalFetcher`, `MistralAI-User`, `PetalBot`, `Omgilibot`, `Brightbot`, `magpie-crawler`, `DataForSeoBot`.
- Freshness summary dashboard with fresh percentage, stale count, urgency levels (critical / high / moderate).

### Fixed

- PHP 8.0+ fatal error — `generate_faq()` declared `: array` return type but returned `WP_Error` on failure paths.
- `flush_rewrite_rules()` moved from `plugins_loaded` to `init` hook (prevents corrupting other plugins' rewrite rules).
- FAQ OpenAI call now uses `response_format: json_object` (prevents parse failures from markdown-wrapped JSON).
- `llms-full.txt` uses `strip_shortcodes()` instead of `do_shortcode()` (prevents expensive shortcode execution during bulk generation).
- Markdown endpoints now cached via transients (5 min TTL, keyed by `post_modified`) — prevents repeated content processing on bot crawls.

## [0.4.6]

### Security

- Health check no longer exposes API key prefix in diagnostic output.
- DataForSEO verify endpoint no longer leaks login email or debug info in responses.
- All SQL queries in health check and migration now use `$wpdb->prepare()` with positional placeholders.
- `verify-dfs` REST route now has `sanitize_callback` on all parameters.
- Multisite guard added to physical `robots.txt` sync — skips when `is_multisite()`.

### Fixed

- `get_term_link()` return values are now checked for `WP_Error` before use in JSON-LD schema.
- FAQ OpenAI call now checks HTTP status code before processing response (matches summary generator behavior).
- APS migration now runs only once via `rr_aps_migrated` flag instead of re-running on every version bump.

## [0.4.5]

### Fixed

- `about` (categories) and `mentions` (tags) schema now use `get_object_taxonomies()` — works with all custom post types and their custom taxonomies, not just default `category` / `post_tag`.
- Hierarchical taxonomies (categories, `blog-category`, `product_cat`, etc.) map to `about` entities.
- Non-hierarchical taxonomies (tags, `blog-tag`, `product_tag`, etc.) map to `mentions` entities.

## [0.4.4]

### Added

- Health Check diagnostic tool in Tools tab — scans all settings, API keys, coverage stats, rewrite rules, errors.
- DataForSEO usage tracking — API calls and cost displayed alongside OpenAI usage in Tools tab.
- Resume button for Start Over bulk operation — stop and pick up where you left off.

### Changed

- Start Over Stop now preserves the queue for resume instead of clearing it.
- API Usage card now shows both OpenAI and DataForSEO costs side by side.

## [0.4.3]

### Added

- Full SEO plugin compatibility — merges AI schema into Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework, and Slim SEO.
- `abstract` property — Key Takeaways text as machine-readable summary for AI citation.
- `lastReviewed` property — FAQ review date as freshness / trust signal.
- `reviewedBy` property — post author as E-E-A-T signal with bio excerpt.
- `significantLink` property — auto-extracted internal links from post content.
- `citation` property — auto-extracted external links as `CreativeWork` references for AI fact-checking chains.
- `accessibilityFeature` property — detects TOC, structural navigation (H2 / H3), alt text, and long description.
- `hasPart` now includes both Key Takeaways and FAQ sections as extractable `WebPageElement`s.
- `rankready_ai_schema_properties` filter for developers to extend AI properties.

### Changed

- All properties are now dynamic — extracted from actual post content, categories, tags, and meta.

## [0.4.2]

### Added

- Speakable schema now merges into Rank Math's Article / BlogPosting via the `rank_math/json_ld` filter.
- Speakable schema merges into Yoast's Article schema via the `wpseo_schema_graph` filter.
- `hasPart` `WebPageElement` — marks Key Takeaways as a structured section LLMs can extract directly.
- `about` entities from categories — topic-level comprehension signals for AI Overviews.
- `mentions` entities from tags — secondary entity signals.
- `SpeakableSpecification` now includes `.rr-faq-wrapper` selector for voice search on FAQ content.

## [0.4.1]

### Fixed

- Theme builder detection rewritten — uses `did_action('elementor/theme/before_do_single')` for reliable detection when blog posts use Elementor Pro theme builder templates.
- Nexter Theme Builder detection added — auto-display skips when the Nexter single template is active.
- Frontend styles now load reliably on theme builder pages (uses `get_queried_object_id()` instead of `get_the_ID()`).
- Auto-display no longer injects Key Takeaways or FAQ via `the_content` when a theme builder widget handles display.

### Added

- Start Over is now a bulk operation — select post types and regenerate all Key Takeaways + FAQ from scratch with progress bar.
- Start Over ignores the auto-generate setting — always regenerates regardless of toggle.
- Bulk Start Over REST endpoints (`startover-bulk/start`, `process`, `stop`).

## [0.4]

### Added

- **Product Context setting** — describe your product / brand so AI never hallucinates wrong features or compatibility claims.
- **Auto-Generate toggle** (default OFF) — summaries only generate via manual Regenerate, block, or Bulk. No surprise token usage on publish.
- Estimated cost display (GPT-4o-mini pricing) above token usage in Tools tab.

### Changed

- Complete prompt rewrite for Key Takeaways — entity-rich, zero-hallucination, specific insights only.
- Complete prompt rewrite for FAQ — strict fact-checking rules, no invented features / integrations / compatibility.
- Product context injected into both Key Takeaways and FAQ prompts — AI now respects brand facts.
- Custom Prompt now applied to FAQ generation (was only used for summaries).
- FAQ temperature lowered from `0.7` to `0.5` for more factual, less creative answers.

### Fixed

- FAQ questions no longer repeat — semantic deduplication removes near-duplicate PAA questions.
- DFS questions now sorted by search volume — most popular People Also Ask questions used first.
- DFS fetches increased to 30 suggestions + 20 related for better question discovery.

## [2.5.0]

### Added

- **FAQ Generator** — auto-generate FAQ Q&A pairs using DataForSEO keyword research + OpenAI with brand entity injection.
- FAQPage JSON-LD schema — compound with Article, duplicate detection for Rank Math / Yoast / AIOSEO FAQ blocks.
- Semantic triple brand injection — builds brand-entity association in FAQ answers.
- FAQ auto-display with position control (before / after content).
- Bulk FAQ Generation — generate FAQs for all existing posts with progress bar.
- FAQ in Markdown endpoints — FAQ section appended to `.md` output.
- FAQ Generator admin tab with DataForSEO credentials, brand terms, FAQ count, heading tag controls.
- `lastmod` dates in `llms.txt` entries — LLMs prioritize fresh content.
- FAQ cleanup on uninstall — all FAQ options and post meta removed.

### Fixed

- Content freshness signal via `dateModified` bump when FAQ is generated.

## [2.4.0]

### Added

- **LLM Crawler Access settings** — per-crawler toggles for 22 AI bots (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, etc.).
- Select / deselect all crawlers, grouped by company.
- Enable / disable toggle for `robots.txt` crawler rules.
- Smart deduplication — skips crawlers already defined by Rank Math or other plugins.
- Global Allow directives for `/llms.txt`, `/llms-full.txt`, `/*.md$` endpoints.

### Fixed

- `robots.txt` rules are append-only — never modifies existing Rank Math, Yoast, or other plugin rules.
- Crawlers driven by admin settings, not a hardcoded list.

## [2.0.0]

### Added

- Tabbed admin interface (Settings, LLM Optimization, Tools, Info).
- LLMs.txt generator following llmstxt.org specification.
- Optional `/llms-full.txt` with full post content inlined.
- Markdown endpoints — `/post-slug.md` for LLM crawlers.
- `Accept: text/markdown` content negotiation.
- Auto-discovery via `<link rel="alternate" type="text/markdown">`.
- Link HTTP header for markdown endpoint discovery.
- Canonical `Link` header on `.md` responses pointing to HTML.
- `Vary: Accept` header for CDN content negotiation.
- YAML frontmatter in Markdown (title, date, author, tags, categories).
- Bulk Author Changer with preview, date range filter, progress bar.
- Top-level admin menu with dedicated icon.
- Info tab with quick stats.
- Cache management for LLMs.txt.

### Security

- Nonce verification on all REST endpoints.
- Capability checks on all admin actions.

## [1.0.0]

### Added

- Initial release.

---

[Unreleased]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/compare/1.5.4...HEAD
[1.5.4]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.5.4
[1.5.3]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.5.3
[1.5.2]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.5.2
[1.5.1]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.5.1
[1.5]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.5
[1.3]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.3
[1.2]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.2
[0.4.6]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/0.4.6
[0.4.5]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/0.4.5
[0.4.4]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/0.4.4
[0.4.3]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/0.4.3
[0.4.2]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/0.4.2
[0.4.1]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/0.4.1
[0.4]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/0.4
[2.5.0]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/2.5.0
[2.4.0]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/2.4.0
[2.0.0]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/2.0.0
[1.0.0]: https://github.com/posimyth/RankReady-LLM-SEO-EEAT-AI-Optimization/releases/tag/1.0.0
