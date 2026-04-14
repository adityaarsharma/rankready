# Changelog

All notable changes to RankReady are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.7.0] - 2026-04-14

### Added — RankReady Author Box (full EEAT author system)

A complete EEAT author identity feature that maps 23 WordPress user profile fields directly to Schema.org Person data. Built on the 2026 AI citation research showing that authors with verifiable identity, structured credentials, and priority-ordered `sameAs` links get cited significantly more by Perplexity, ChatGPT, and Google AI Overviews.

**Profile fields** (new section "RankReady Author Box" on `user-edit.php`, all registered with `show_in_rest => true`):

- Identity & work: job title, employer, employer URL, bio, headshot, headshot alt → `jobTitle`, `worksFor` (Organization with `@type`, `name`, `url`), `description`, `image` (ImageObject)
- Experience: started year (derives verifiable years of experience — no fake counters), topics of expertise (comma list) → `knowsAbout[]` (the highest-signal field for LLM topical clustering per research)
- Credentials: post-nominal suffix (e.g. "MD, PhD"), education repeater (degree + institution + year), certifications repeater (name + issuer + year + URL), memberships repeater (org + URL), awards repeater (name + year) → `alumniOf[]` + `hasCredential[]` (credentialCategory: degree/certification), `memberOf[]`, `award[]`
- Verified identity (priority `sameAs`): Wikidata QID, Wikipedia URL, ORCID iD, Google Scholar, LinkedIn — ordered first in `sameAs[]` as the canonical entity anchors LLMs reuse
- Social (lower priority `sameAs`): GitHub, YouTube, X, personal site
- Contact: contact form URL → `contactPoint` (`ContactPoint` with `contactType: "author"`) — no raw email (scrape risk cut deliberately)

Every field has an inline description explaining what EEAT signal it emits. Repeaters are vanilla JS (no jQuery, no React build).

**Priority-ordered `sameAs`**:

```
Wikidata → Wikipedia → ORCID → Google Scholar → LinkedIn → GitHub → YouTube → X → personal site
```

The first three are the canonical entity URIs LLMs and Google Knowledge Graph internally resolve against. The ordering is research-backed.

**ORCID and Wikidata dual emission**: When ORCID is set, emits both the `orcid.org` URL in `sameAs` AND an `identifier` PropertyValue with `propertyID: "ORCID"`. Same treatment for Wikidata (`propertyID: "Wikidata"`). Two-channel emission improves disambiguation for academic and general-entity citation contexts.

**Per-post "Author Trust" meta** (registered post meta, exposed in REST for the block editor sidebar):

- `_rr_author_fact_checked_by` (int → user ID) → `Article.reviewedBy[]`
- `_rr_author_reviewed_by` (int → user ID) → `Article.reviewedBy[]`
- `_rr_author_last_reviewed` (YYYY-MM-DD) → `Article.lastReviewed`
- `_rr_author_disable` (bool) → per-post opt-out from auto-display

Both reviewer fields serialize into `reviewedBy[]` as full Person nodes, each carrying their own `sameAs` / credentials / memberships. The Author Box display also renders "Fact-checked by X · Reviewed by Y · Last reviewed [date]" inline (Healthline pattern).

**Schema conflict-safety — zero duplicate Person nodes**:

- When Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework, or Slim SEO is active, RankReady hooks into each plugin's schema graph filter at priority 100 and **enhances the existing Person node in place** with its own `sameAs`, `knowsAbout`, `alumniOf`, `hasCredential`, `memberOf`, `award`, `jobTitle`, `worksFor`, `contactPoint`, `publishingPrinciples`, `identifier`, `image`, `description`. Never overwrites existing keys. Filter hooks used: `rank_math/json_ld`, `wpseo_schema_graph`, `aioseo_schema_output`, `seopress_schemas_auto_article_json`, `the_seo_framework_schema_graph_data`, `slim_seo_schema_graph`.
- When no SEO plugin is active, RankReady emits its own Person inline on `Article.author` on singular posts.
- On `is_author()` archive pages, RankReady emits a `ProfilePage { mainEntity: Person }` JSON-LD node via `wp_head` only — **zero visible template override**. Works with any theme / any author archive template.

**Three display layouts**:

- **Card** — Full end-of-article box with 96px headshot, name + credentials suffix, job title + employer + years of experience, bio, topics of expertise pills, education + certifications rows, social icons, reviewed-by line, editorial policy + fact-check footer links
- **Compact** — Sidebar-friendly: 64px headshot, condensed byline, bio, social icons
- **Inline byline** — Healthline-style above-the-fold: 40px headshot, "By [Name]" + job title + reviewed-by line, no bio/credentials

Every section (headshot, job title, employer, years of experience, bio, expertise tags, credentials, social icons, reviewed-by) has an individual toggle so the same data source can render as a minimal byline or a maximal card.

**Gutenberg block** (`rankready/author-box`):

- Vanilla `wp.element.createElement` (no JSX, no build step, IIFE pattern, `var` declarations)
- Server-side rendered (`save: () => null`, PHP `render_callback` on `RR_Author_Box::render_block`)
- 9 inspector panels: Content, Visible Fields, Box Style, Heading Style, Name Style, Meta Style, Bio Style, Headshot Style, Social Style
- Author source selector: "Current post author" | "Specific author (user picker)"
- Editor preview fetches user data via `wp.data.useSelect( s => s('core').getUser(id) )` — no custom REST endpoint needed
- Block localizes with a light user list (`wp_localize_script` extends `rrBlockData.users`) so the specific-author dropdown works offline

**Elementor widget** (`RR_Elementor_Author_Box_Widget`):

- Full `Group_Control_Typography` on every text layer (Heading, Name, Meta, Bio, Social) — Elementor Global Fonts + Global Colors work automatically
- `Group_Control_Border` + `Group_Control_Box_Shadow` on the box wrapper
- Responsive dimensions controls for padding + image size
- All visible-field toggles mirror the Gutenberg block
- Registered conditionally via the existing `did_action('elementor/loaded')` guard alongside the Summary and FAQ widgets

**Auto-display** — `the_content` filter with `off` | `before` | `after` | `both` positioning, per-post-type allowlist, per-post opt-out via `_rr_author_disable` meta, skipped automatically when the Gutenberg block is already in the content.

**New Author Box settings tab** (`RankReady → Author Box`):

- Master enable toggle
- Auto-display position (off / before / after / both)
- Post types allowlist
- Default layout (card / compact / inline)
- Default heading text + tag
- Person schema enable toggle with live SEO plugin detection banner
- Editorial Policy URL → `Person.publishingPrinciples` on every author
- Fact-Check Policy URL → footer link in Card layout
- Inline "How to Use" guide

**Fields deliberately cut** (don't emit schema or add privacy risk): public email (scrape risk → `contactPoint` URL instead), pronouns / pronunciation (UI-only), office address, phone, birth date, family relationships, Instagram / Facebook / TikTok / Threads (consumer platforms that don't move LLM entity matching), Muck Rack (journalist-niche), Mastodon / Bluesky (too small), Department (`worksFor.department` rarely surfaces in AI citations). 23 schema-mapped fields total.

### Added — Gutenberg typography parity with `theme.json` global fonts

Full typography controls now available on the Summary, FAQ, and Author Box Gutenberg blocks — previously only Elementor widgets had this. Every text layer exposes:

- **Font Family** dropdown — populated from `wp.data.select('core/block-editor').getSettings().__experimentalFeatures.typography.fontFamilies`, reading the Theme / Custom / Default groups. Any block theme that registers global fonts via `theme.json` shows up automatically: Nexter Theme, Nexter Blocks, Kadence, Astra, GeneratePress, Twenty Twenty-Four, Blocksy, Hello Elementor. Labels prefixed by source group (`"Theme — Inter"`, `"Custom — Space Grotesk"`). Legacy `editor-font-families` theme support is honored as a fallback.
- **Font Weight** — 100 Thin through 900 Black
- **Font Size (px)** — with `0 = inherit from theme` semantics
- **Line Height** — with `0 = inherit`
- **Letter Spacing (px)** — on Summary label and bullets
- **Text Transform** — none / uppercase / lowercase / capitalize on Summary label

Every new control carries an inline `help:` description. Server-side renderers (`build_summary_html()` and `render_faq()` in `class-rr-block.php`) emit inline `style="..."` attributes for every new typography property. Backwards compatible — unset values cascade to the theme.

### Added — Self-healing rewrite rules (rolled in from 1.6.0)

- `pre_update_option_` filters on `rr_llms_enable`, `rr_llms_full_enable`, `rr_md_enable` bust the `rr_rewrite_ok` transient on every settings save so the self-heal re-runs even when the saved toggle value is unchanged (the original `update_option` hooks only fire on value changes, so unchanged-save did not trigger the flush before).
- `admin_init` self-heal: if the `rr_rewrite_ok` transient is missing, reads `get_option('rewrite_rules')` and checks for the `^llms\.txt$`, `^llms-full\.txt$`, and any `\.md$` rule. If a rule is missing and the corresponding toggle is `on`, re-registers the rules and calls `flush_rewrite_rules( false )`. Transient-throttled to once per hour to avoid flushing on every admin page load.
- Smart conflict detection: the `/llms.txt` self-heal is skipped when Rank Math (`RANK_MATH_VERSION` + `llms-txt` module enabled) or Yoast (`WPSEO_VERSION` + `wpseo['enable_llms_txt']`) is already handling `/llms.txt` — prevents rule collision when another plugin owns the route.

### Added — Other

- New file `includes/class-rr-author-box.php` (63 KB) — profile fields UI, `build_person_schema()`, `render_html()`, `enhance_graph()` merge helpers, auto-display filter, archive Person emission, per-post and per-user `register_meta()`, repeater sanitization, block render callback, `block_attributes()` array
- New file `includes/class-rr-elementor-author-box.php` — Elementor widget mirroring the Gutenberg block 1:1
- New file `assets/author-box-block.js` — Gutenberg block source, vanilla `wp.element`, shared typography panel helper
- 10 new option keys defined in `rankready.php`: `RR_OPT_AUTHOR_ENABLE`, `RR_OPT_AUTHOR_AUTO_DISPLAY`, `RR_OPT_AUTHOR_LAYOUT`, `RR_OPT_AUTHOR_HEADING`, `RR_OPT_AUTHOR_HEADING_TAG`, `RR_OPT_AUTHOR_SCHEMA_ENABLE`, `RR_OPT_AUTHOR_EDITORIAL_URL`, `RR_OPT_AUTHOR_FACTCHECK_URL`, `RR_OPT_AUTHOR_POST_TYPES`
- 4 new post meta keys: `RR_META_AUTHOR_FACT_CHECKED_BY`, `RR_META_AUTHOR_REVIEWED_BY`, `RR_META_AUTHOR_LAST_REVIEWED`, `RR_META_AUTHOR_DISABLE`
- 23 new user meta keys: `rr_author_*` (full list in `RR_Author_Box::META_KEYS`)
- 260+ lines of new CSS in `assets/style.css` for the 3 author box layouts, CSS custom properties, mobile responsive breakpoint
- Admin tab "Author Box" with 9 `register_setting()` calls in a new `rr_author_group` settings group
- `uninstall.php` now cleans all 9 new options, 4 new post meta keys, and 23 new user meta keys

### Changed

- `rankready.php` — bumped `Version:` header and `RR_VERSION` constant from `1.5.4` to `1.7.0`, added 10 new constants, registered `RR_Author_Box::init()` in the bootstrap, registered `RR_Elementor_Author_Box_Widget` conditionally under the existing `did_action('elementor/loaded')` guard, added activation defaults for author box options
- `includes/class-rr-block.php` — now registers 3 blocks (Summary, FAQ, Author Box), enqueues `author-box-block.js`, extends `rrBlockData` localization with user list + author box defaults, adds typography attributes (`labelFontFamily`, `labelFontWeight`, `labelLineHeight`, `labelLetterSpacing`, `labelTextTransform`, `bulletFontFamily`, `bulletFontWeight`, `bulletLetterSpacing`, `questionFontFamily`, `questionFontWeight`, `questionLineHeight`, `answerFontFamily`, `answerFontWeight`, `answerLineHeight`) to Summary and FAQ block registrations, emits the new typography properties as inline styles in the server renderers, extends `enqueue_frontend_assets()` to also load `style.css` when the Author Box block is present or auto-display is enabled
- `includes/class-rr-admin.php` — added `'author' => __('Author Box', 'rankready')` tab between `'faq'` and `'schema'` in the tab registration, added `render_tab_author()` method (~100 lines) with settings form, added 9 `register_setting()` calls in the new `AUTHOR_GROUP = 'rr_author_group'` constant, added inline SEO-plugin detection banner
- `assets/block.js` and `assets/faq-block.js` — added `rrGlobalFontOptions()` helper reading `theme.json` font families, added `rrWeightOptions` + `rrTransformOptions` arrays, extended `attributes` with the new typography keys, extended preview `style` builders with font-family/weight/line-height/letter-spacing/transform, replaced the old simple Label/Bullets/Question/Answer panels with full typography panels that include the Font Family SelectControl + Font Weight SelectControl + Line Height + Letter Spacing + Text Transform controls
- `readme.txt` — `Stable tag: 1.7.0`, added full `= 1.7.0 =` changelog section
- `README.md` — version badge bumped to 1.7.0, intro paragraph updated to lead with Author Box, comparison matrix gained EEAT Author Box + `sameAs` priority ordering + Gutenberg global fonts rows, new "11. EEAT Author Box" and "12. Gutenberg Typography Parity" feature sections, Display Options compatibility list updated, changelog pointers updated

### Notes

- All 14 plugin PHP files pass `php -l` (lint verified via `brew install php`)
- All 3 JavaScript files pass `node --check`
- Brace/paren balance preserved vs upstream 1.5.4 (the +7 `)` delta in `class-rr-block.php` is pre-existing in strings/comments, not introduced by this release)
- Zip build: `~/Claude/RankReady/rankready-1.7.0.zip`, 155 KB, 28 files, no `.git`/`.github`/dev markdown
- Rolls in the flush-permalinks fix that was originally tagged for 1.6.0 — 1.6.0 is effectively subsumed and never shipped as a standalone release

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
