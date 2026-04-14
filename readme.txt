=== RankReady – LLM SEO, EEAT & AI Optimization ===
Contributors: posimyth
Tags: llm seo, ai summary, schema markup, llms.txt, eeat
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.7.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI summaries, FAQ Generator with brand entity injection, Article JSON-LD schema with speakable, LLMs.txt generator, Markdown endpoints, bulk author changer. Built for LLM SEO, EEAT, and AI Overviews.

== Description ==

RankReady optimizes your WordPress site for AI search engines, LLM crawlers, and Google AI Overviews.

**Features:**

* **AI Summary** — Auto-generates key takeaways on publish/update via OpenAI. Content-hash caching prevents API waste.
* **LLMs.txt Generator** — Serves /llms.txt and /llms-full.txt following the llmstxt.org specification. Helps AI models understand your site.
* **Markdown Endpoints** — Every post available as clean Markdown at its URL + .md (e.g., /post-slug.md). YAML frontmatter, content negotiation via Accept header, auto-discovery via link tag.
* **Article JSON-LD Schema** — Speakable markup injected automatically. Works alongside Yoast, Rank Math, AIOSEO.
* **Gutenberg Block** — Full style controls: colors, border, padding, fonts.
* **Elementor Widget** — Drag-and-drop AI summary widget with style controls.
* **Bulk Author Changer** — Reassign authors across any post type with preview and progress tracking.
* **FAQ Generator** — DataForSEO-powered question discovery + OpenAI answers with semantic triple brand injection. FAQPage JSON-LD schema.
* **Bulk Regenerate** — Regenerate all summaries or FAQs in batches with progress bar.

== Installation ==

1. Upload the `rankready` folder to `/wp-content/plugins/`.
2. Activate the plugin.
3. Go to RankReady in the admin menu.
4. Enter your OpenAI API key in the Settings tab.
5. Configure LLMs.txt and Markdown in the LLM Optimization tab.

== Changelog ==

= 1.7.0 =
* New: RankReady Author Box — full EEAT author system with Gutenberg block, Elementor widget, and Person JSON-LD schema
* New: 23 author profile fields added under Users → Profile → "RankReady Author Box" — every field maps directly to Schema.org Person
* New: Schema.org Person emission with @id, jobTitle, worksFor (Organization), description, image (ImageObject), knowsAbout, sameAs, alumniOf, hasCredential (degree/certification), memberOf, award, contactPoint, publishingPrinciples, identifier (PropertyValue for ORCID + Wikidata)
* New: Priority sameAs ordering — Wikidata → Wikipedia → ORCID → Google Scholar → LinkedIn → GitHub → YouTube → X → personal site
* New: ORCID dual emission — sameAs URL + identifier PropertyValue for stronger entity disambiguation
* New: Education, certifications, memberships, and awards as repeaters — each row emits its own schema node
* New: Topics of Expertise field → Person.knowsAbout[] (the highest-signal field for AI topical clustering per 2026 citation research)
* New: Started Year field auto-calculates verifiable years of experience (no fake counters)
* New: 3 layouts — Card (end-of-article), Compact (sidebar), Inline byline (Healthline-style)
* New: Per-post "Author Trust" fields — Fact-checked by, Reviewed by, Last reviewed date → Article.reviewedBy[] + lastReviewed
* New: Site-wide Editorial Policy URL → Person.publishingPrinciples on every author
* New: ProfilePage schema wrapper emitted via wp_head on author archive pages (no visible template override)
* New: Schema merge into Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework, Slim SEO via each plugin's Person filter — zero duplicate schema when an SEO plugin is active
* New: Gutenberg Author Box block with Content, Box Style, Heading, Name, Meta, Bio, Headshot, and Social panels
* New: Elementor Author Box widget with full Group_Control_Typography on every text element (Elementor Global Fonts + Global Colors work natively)
* New: Settings tab "Author Box" with master toggle, auto-display, default layout, post types, schema enable, editorial + fact-check policy URLs
* New: Full typography parity across all Gutenberg blocks — font family, weight, line height, letter spacing, text transform added to Summary + FAQ blocks
* New: Global font support in Gutenberg blocks — reads theme.json fontFamilies so Nexter Theme, Nexter Blocks, Kadence, Astra, and any block theme's global fonts appear automatically
* Fix: Self-healing rewrite rules — llms.txt and .md endpoints now auto-recover if missing from DB without requiring manual Permalinks save (rolled in from 1.6.0)
* Fix: Rewrite rules now re-flush on every settings save, even when toggle value is unchanged
* Fix: Smart conflict detection — skips llms.txt self-heal when Rank Math or Yoast is already handling it

= 1.5.4 =
* New: Enterprise Headless WordPress support — public read-only REST API for Next.js, Nuxt, Astro, SvelteKit, Gatsby, Faust.js, Atlas
* New: Public endpoints at rankready/v1/public/ — faq/{id}, summary/{id}, schema/{id}, post/{id}, post-by-slug/{slug}, list, revalidate
* New: rankready_faq, rankready_summary, rankready_schema exposed as core REST fields on /wp/v2/posts/{id}
* New: HTTP caching — ETag + Last-Modified + Cache-Control s-maxage + stale-while-revalidate + 304 Not Modified
* New: CORS hardening — allowlist with Vary: Origin and Access-Control-Expose-Headers
* New: Rate limiting — transient per-IP (default 120/min), real IP detection via Cloudflare / X-Forwarded-For / X-Real-IP
* New: On-Demand Revalidation webhook — fire-and-forget POST to Next.js / Nuxt on FAQ / summary updates, hash_equals() secret verification
* New: WPGraphQL integration — conditional rankReadyFaq, rankReadySummary, rankReadySchema fields
* New: Multilingual support — Polylang and WPML language detection, translations map, lang query arg
* New: RFC 7807 Problem Details error format (application/problem+json) on 4xx / 5xx public endpoint errors
* New: Admin Headless tab with master toggle, CORS origins, cache TTL, rate limit, revalidate URL / secret, WPGraphQL toggle, endpoint reference
* Security: Per-page capped at 100 on list endpoint, password-protected posts return 403, no admin or PII data in any response
* Note: Headless mode is off by default — existing installs are unaffected

= 1.5.3 =
* Fix: FAQ bulk cron was getting stuck when a post threw a fatal error — queue persisted BEFORE generation so a failed post no longer blocks the entire queue
* Fix: Summary bulk cron had the same stuck-queue bug — now dequeues before generating
* Fix: Uncaught exceptions during FAQ/summary generation no longer kill the cron tick — wrapped in try/catch with error logging
* New: Batch processing per cron tick (up to 5 FAQ posts or BULK_BATCH summaries) within a time budget based on max_execution_time
* New: Failed post ID tracking (`rr_faq_failed_ids` option, last 100 failures) for debugging stuck queues
* New: Cron watchdog heartbeat (`rr_faq_cron_last_tick`, `rr_bulk_cron_last_tick`) for stuck-state detection
* New: Post-generation FAQ validation — rejects items with banned opener patterns (Yes/Sure/Of course), banned filler words (leverage/utilize/comprehensive), "follow the documentation" boilerplate, and one-sentence thin answers
* Improvement: Stronger search intent grounding in FAQ prompt — forces 60% of questions from real DataForSEO search queries when available
* Improvement: Brand-stuffing banned in FAQ questions — "How do I X in Elementor?" not "Can I use The Plus Addons to X?"
* Improvement: Temperature lowered from 0.5 to 0.3 for stricter instruction compliance

= 1.5.2 =
* Fix: Markdown links in FAQ answers now render as clickable HTML links in Gutenberg block, Elementor widget, and auto-display
* Fix: FAQ schema output now strips markdown link syntax cleanly instead of leaving raw brackets
* Improvement: Banned "Yes, [Brand Name]..." opener pattern in FAQ answer generation

= 1.5.1 =
* Fix: FAQ generation failing with "No valid FAQ items" — OpenAI returns `faqs` wrapper key which was not handled
* Fix: Added universal JSON response wrapper detection — handles `faq`, `faqs`, `questions`, `items`, and any unknown wrapper key
* Fix: DataForSEO API timeout reduced from 20s to 5s per call to fit within 30s max_execution_time
* Fix: OpenAI timeout reduced from 60s to 15s — total worst-case generation time now 25s (was 100s)
* Improvement: FAQ generation consistently completes in ~9s on shared hosting

= 1.5 =
* New: Schema Automation tab — enable/disable toggles for Article, FAQPage, HowTo, ItemList, and Speakable schema with SEO plugin compatibility guide
* New: HowTo JSON-LD schema auto-detection — scans post content for step patterns (Step N headings, numbered headings, ordered lists) and injects HowTo schema automatically
* New: HowTo schema skips injection when Rank Math or Yoast HowTo blocks already exist in post content
* New: ItemList JSON-LD schema auto-detection — scans listicle posts ("Best N", "Top N", "N Best") and injects ItemList schema with item names, URLs, descriptions, and images
* New: ItemList and HowTo are mutually exclusive — a post gets one or the other based on title patterns
* New: Auto-detects active SEO plugin (Rank Math, Yoast, AIOSEO) and shows which schema types are handled by each
* New: WP-Cron background schema scanner — HowTo/ItemList detection runs every 5 minutes via wp-cron.php, zero performance impact on page loads
* New: Schema batch size control with dynamic server recommendation (shared hosting: 5, mid-range: 15, VPS: 25)
* New: Schema scanner progress dashboard — scanned/pending counts, estimated time, next cron run
* New: Server resource detection — reads PHP memory_limit, max_execution_time, PHP version for batch recommendations
* New: Smart FAQ algorithm — People Also Ask (PAA) questions from Google SERP, comparison queries, Reddit-style questions
* New: Page-type-aware FAQ — auto-detects docs, landing pages, comparisons, tutorials, and blog posts for tailored question styles
* New: FAQ questions for docs pages — troubleshooting, compatibility, configuration questions
* New: FAQ questions for landing pages — buyer objections, pricing, decision-making, "is it worth it" questions
* New: FAQ questions for comparison pages — "which is better", migration, feature difference questions
* New: Multi-source question discovery — PAA + keyword suggestions + related keywords + Google related searches
* New: Levenshtein fuzzy deduplication — removes near-duplicate questions (< 30% string distance)
* New: Smart question ranking — PAA weighted highest, then comparison queries, then keyword suggestions
* New: LLM-optimized answers — each FAQ answer designed as standalone knowledge unit AI chatbots can cite
* New: JSON response wrapper handling — auto-detects {faq:[...]}, {questions:[...]}, {items:[...]} response formats
* New: Schema scan REST endpoints — /schema/status and /schema/recommendation for programmatic access
* Fix: robots.txt file operations now use WP_Filesystem API instead of file_put_contents/file_get_contents (WordPress.org compliance)

= 1.2 =
* New: Content Freshness Alerts — scan for stale posts losing AI visibility (65% of AI citations target content < 1 year old)
* New: Expanded AI crawler list — 31 bots (was 22) including anthropic-ai, GoogleOther, Meta-ExternalFetcher, MistralAI-User, PetalBot, Omgilibot, Brightbot, magpie-crawler, DataForSeoBot
* New: Freshness summary dashboard with fresh percentage, stale count, urgency levels (critical/high/moderate)
* Fix: PHP 8.0+ fatal error — generate_faq() declared `: array` return type but returned WP_Error on failure paths
* Fix: flush_rewrite_rules() moved from plugins_loaded to init hook (prevents corrupting other plugins' rewrite rules)
* Fix: FAQ OpenAI call now uses response_format json_object (prevents parse failures from markdown-wrapped JSON)
* Fix: llms-full.txt uses strip_shortcodes() instead of do_shortcode() (prevents expensive shortcode execution during bulk generation)
* Fix: Markdown endpoints now cached via transients (5 min TTL, keyed by post_modified) — prevents repeated content processing on bot crawls

= 0.4.6 =
* Security: Health check no longer exposes API key prefix in diagnostic output
* Security: DataForSEO verify endpoint no longer leaks login email or debug info in responses
* Security: All SQL queries in health check and migration use $wpdb->prepare() with positional placeholders
* Security: verify-dfs REST route now has sanitize_callback on all parameters
* Security: Multisite guard added to physical robots.txt sync — skips when is_multisite()
* Fix: get_term_link() return values checked for WP_Error before use in JSON-LD schema
* Fix: FAQ OpenAI call now checks HTTP status code before processing response (matches summary generator behavior)
* Fix: APS migration now runs only once via rr_aps_migrated flag instead of re-running on every version bump

= 0.4.5 =
* Fix: about (categories) and mentions (tags) schema now use get_object_taxonomies() — works with ALL custom post types and their custom taxonomies, not just default category/post_tag
* Fix: Hierarchical taxonomies (categories, blog-category, product_cat, etc.) map to about entities
* Fix: Non-hierarchical taxonomies (tags, blog-tag, product_tag, etc.) map to mentions entities

= 0.4.4 =
* New: Health Check diagnostic tool in Tools tab — scans all settings, API keys, coverage stats, rewrite rules, errors
* New: DataForSEO usage tracking — API calls and cost displayed alongside OpenAI usage in Tools tab
* New: Resume button for Start Over bulk operation — stop and pick up where you left off
* New: Start Over Stop now preserves queue for resume instead of clearing it
* Enhancement: API Usage card now shows both OpenAI and DataForSEO costs side by side

= 0.4.3 =
* New: Full SEO plugin compatibility — merges AI schema into Rank Math, Yoast, AIOSEO, SEOPress, The SEO Framework, and Slim SEO
* New: abstract property — Key Takeaways text as machine-readable summary for AI citation
* New: lastReviewed property — FAQ review date as freshness/trust signal
* New: reviewedBy property — post author as E-E-A-T signal with bio excerpt
* New: significantLink property — auto-extracted internal links from post content
* New: citation property — auto-extracted external links as CreativeWork references for AI fact-checking chains
* New: accessibilityFeature property — detects TOC, structural navigation (H2/H3), alt text, and long description
* New: hasPart now includes both Key Takeaways and FAQ sections as extractable WebPageElements
* New: rankready_ai_schema_properties filter for developers to extend AI properties
* Enhancement: All properties are dynamic — extracted from actual post content, categories, tags, and meta

= 0.4.2 =
* New: Speakable schema now merges into Rank Math's Article/BlogPosting via rank_math/json_ld filter — no longer skipped
* New: Speakable schema merges into Yoast's Article schema via wpseo_schema_graph filter
* New: hasPart WebPageElement — marks Key Takeaways as a structured section LLMs can extract directly
* New: about entities from categories — gives AI topic-level comprehension signals
* New: mentions entities from tags — secondary entity signals for AI Overviews
* New: SpeakableSpecification now includes .rr-faq-wrapper selector for voice search on FAQ content

= 0.4.1 =
* Fix: Theme builder detection rewritten — uses did_action('elementor/theme/before_do_single') for reliable detection when blog posts use Elementor Pro theme builder templates
* Fix: Nexter Theme Builder detection added — auto-display skips when Nexter single template is active
* Fix: Frontend styles now load reliably on theme builder pages (uses get_queried_object_id instead of get_the_ID)
* Fix: Auto-display no longer injects Key Takeaways or FAQ via the_content when a theme builder widget handles display
* New: Start Over is now a bulk operation — select post types and regenerate all Key Takeaways + FAQ from scratch with progress bar
* New: Start Over ignores auto-generate setting — always regenerates regardless of toggle
* New: Bulk Start Over REST endpoints (startover-bulk/start, process, stop)

= 0.4 =
* New: Product Context setting — describe your product/brand so AI never hallucinates wrong features or compatibility claims
* New: Auto-Generate toggle (default OFF) — summaries only generate via manual Regenerate, block, or Bulk. No surprise token usage on publish
* New: Estimated cost display (GPT-4o-mini pricing) above token usage in Tools tab
* Fix: Complete prompt rewrite for Key Takeaways — entity-rich, zero-hallucination, specific insights only
* Fix: Complete prompt rewrite for FAQ — strict fact-checking rules, no invented features/integrations/compatibility
* Fix: Product context injected into BOTH Key Takeaways and FAQ prompts — AI now respects brand facts
* Fix: Custom Prompt now applied to FAQ generation (was only used for summaries)
* Fix: FAQ questions no longer repeat — semantic deduplication removes near-duplicate PAA questions
* Fix: DFS questions sorted by search volume — most popular People Also Ask questions used first
* Fix: DFS fetches increased to 30 suggestions + 20 related for better question discovery
* Fix: FAQ temperature lowered from 0.7 to 0.5 for more factual, less creative answers

= 4.0.0 =
* CRITICAL FIX: Eliminated token burn loop — summary was double-firing via shutdown + cron on every publish
* CRITICAL FIX: FAQ wp_update_post() was re-triggering summary generation in an infinite chain
* CRITICAL FIX: Removed auto-FAQ-on-publish (was firing DataForSEO + OpenAI on every post save across all CPTs)
* Fix: Added re-entrancy guard ($generating flag) to block wp_update_post from re-triggering hooks
* Fix: Summary now uses cron-only path (no more shutdown + cron double-fire)
* Fix: Migration now handles posts with empty _rr_summary that blocked _aps_summary migration
* FAQ generation remains manual (Gutenberg block, Elementor widget) and bulk (Tools tab)

= 2.5.3 =
* Fix: Summary auto-display, FAQ auto-display, and schema injection now work on ALL public CPTs (not just selected ones)
* Fix: Removed all post type settings gates from generation, display, and schema — everything runs on all public CPTs
* New: DataForSEO Verify button with balance check and debug info on failure

= 2.5.2 =
* New: Auto-migrate summaries from AI Post Summary plugin (preserves all existing generated content)
* New: Per-post token usage tracking with detailed breakdown table in Tools tab
* New: Bulk operation live activity log — shows post title, link, status, and tokens used per item
* New: RankReady status column on ALL public post types (not just enabled ones)
* Fix: FAQ generation cooldown now returns proper WP_Error for consistent error handling
* Fix: Status column registration uses get_post_types() for custom post types like Blog

= 2.5.0 =
* New: FAQ Generator — auto-generate FAQ Q&A pairs using DataForSEO keyword research + OpenAI with brand entity injection
* New: FAQPage JSON-LD schema — compound with Article, duplicate detection for Rank Math/Yoast/AIOSEO FAQ blocks
* New: Semantic triple brand injection — builds brand-entity association in FAQ answers (+642% AI citation lift pattern)
* New: FAQ auto-display with position control (before/after content)
* New: Bulk FAQ Generation — generate FAQs for all existing posts with progress bar
* New: FAQ in Markdown endpoints — FAQ section appended to .md output
* New: FAQ Generator admin tab with DataForSEO credentials, brand terms, FAQ count, heading tag controls
* New: lastmod dates in llms.txt entries — LLMs prioritize fresh content
* New: FAQ cleanup on uninstall — all FAQ options and post meta removed
* Fix: Content freshness signal via dateModified bump when FAQ is generated

= 2.4.5 =
* Fix: robots.txt header uses plain ASCII instead of Unicode box-drawing characters — prevents garbled symbols in physical file
* Fix: Improved regex for cleaning up old RankReady blocks in physical robots.txt (handles both Unicode and ASCII formats)

= 2.4.4 =
* Fix: Auto-flush rewrite rules on plugin update — .md and llms.txt endpoints work immediately after zip update without manual deactivate/reactivate
* Fix: Version-based check ensures robots.txt sync and rewrite flush run once per update

= 2.4.3 =
* Fix: Stacked User-agent lines in single block — no more repeating Allow rules per crawler
* Fix: Clean, compact robots.txt output per RFC 9309 spec

= 2.4.2 =
* Fix: Activation hook now sets default values for robots_enable and robots_crawlers
* Fix: Physical robots.txt sync works on first activation without needing manual settings save

= 2.4.1 =
* Fix: Physical robots.txt support — detects and writes directly to physical robots.txt file
* Fix: When physical robots.txt exists (Rank Math editor, manual file, WP Import Export), WordPress filter never fires — RankReady now handles both cases
* New: Auto-sync on activation — writes RankReady block to physical robots.txt immediately
* New: Clean removal on deactivation — removes RankReady block from physical robots.txt
* New: Settings-triggered sync — any settings change auto-updates the physical file

= 2.4.0 =
* New: LLM Crawler Access settings — per-crawler toggles for 22 AI bots (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, etc.)
* New: Select/deselect all crawlers, grouped by company (OpenAI, Anthropic, Google, Apple, Meta, etc.)
* New: Enable/disable toggle for robots.txt crawler rules
* New: Smart deduplication — skips crawlers already defined by Rank Math or other plugins
* New: Global Allow directives for /llms.txt, /llms-full.txt, /*.md$ endpoints
* Fix: robots.txt rules are append-only — never modifies existing Rank Math, Yoast, or other plugin rules
* Fix: Crawlers driven by admin settings, not hardcoded list

= 2.3.0 =
* New: Taxonomy controls — Exclude Categories and Exclude Tags checkboxes in admin
* New: Show/Hide Categories Section toggle for the "Optional" section in llms.txt
* New: `rankready_exclude_from_llms` filter for developer-level post exclusions
* Fix: Removed X-Robots-Tag: noindex from llms.txt output — SEO plugins handle indexing
* Fix: llms.txt respects noindex from Rank Math (query-level meta_query), Yoast, AIOSEO, SEOPress
* Fix: Removed all hardcoded exclusions — no more auto-excluding by slug, word count, or page type
* Fix: Taxonomy exclusions applied at query level via tax_query for performance

= 2.1.0 =
* Security: Greedy .md rewrite rule scoped — excludes wp-admin, wp-content, wp-includes, wp-json
* Security: LLMs.txt cache flow bug fixed (missing return after cached output)
* Security: Memory exhaustion fix — bulk author capped at 10,000 with no_found_rows
* Security: Race condition guards on bulk operations (summary + author)
* Security: Constants wrapped in defined() guard to prevent conflicts
* Security: Anonymous closures replaced with named static methods
* Fix: SEOPress detection added to schema skip logic (was missing)
* Fix: Smart llms.txt conflict detection — skips if Rank Math/Yoast/AIOSEO/SEOPress already serves it
* Fix: WordPress trailing slash redirect loop on .txt and .md URLs
* Fix: Schema injection now checks enabled post types before injecting
* Fix: get_users() filtered to author-capable roles only (was returning all users)
* Fix: Elementor editor null check on Plugin instance
* Fix: str_word_count() replaced with locale-safe regex (works with CJK/Arabic)
* Fix: Activation hook registers rewrite rules before flushing
* Fix: Uninstall.php now flushes rewrite rules to clean up stale endpoints
* Fix: Bulk author change unhooks summary generation to prevent cascading API calls
* New: robots.txt integration — Allow directives for /llms.txt, /llms-full.txt, /*.md
* New: Visible "View as Markdown" link at bottom of content (like EDD)
* New: llms.txt metadata tells crawlers about .md availability and content negotiation
* New: Developer filters — rankready_inject_schema, rankready_force_llms_txt, rankready_show_md_link

= 2.0.0 =
* New: Tabbed admin interface (Settings, LLM Optimization, Tools, Info)
* New: LLMs.txt generator following llmstxt.org specification
* New: Optional /llms-full.txt with full post content inlined
* New: Markdown endpoints — /post-slug.md for LLM crawlers (llmstxt.org spec)
* New: Accept: text/markdown content negotiation (like Next.js/Cloudflare)
* New: Auto-discovery via link rel alternate type text/markdown
* New: Link HTTP header for markdown endpoint discovery
* New: Canonical Link header on .md responses pointing to HTML
* New: Vary: Accept header for CDN content negotiation
* New: YAML frontmatter in Markdown (title, date, author, tags, categories)
* New: Bulk Author Changer with preview, date range filter, progress bar
* New: Top-level admin menu with dedicated icon
* New: Info tab with quick stats
* New: Cache management for LLMs.txt
* Improved: Plugin now registered as top-level menu instead of under Settings
* Security: Nonce verification on all REST endpoints
* Security: Capability checks on all admin actions

= 1.0.0 =
* Initial release
