=== RankReady – LLM SEO, EEAT & AI Optimization ===
Contributors: posimyth
Tags: llm seo, ai seo, llms.txt, schema markup, eeat, ai overviews, chatgpt, perplexity, faq, structured data
Requires at least: 6.2
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.5.2
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

= 0.5.2 =
* Fix: Bulk Author Changer was missing custom post types — only `post`, `page`, `attachment`, and a few Elementor CPTs were showing up. Root cause: the picker filtered by `public => true` which excludes the very common pattern of CPTs registered as `public => false, show_ui => true` (LearnDash, WooCommerce, custom internal types). Now uses a broader filter that catches both public and admin-visible CPTs, with a hard exclude list for system types (attachment, nav_menu_item, wp_block, wp_template/part, wp_navigation, revision, customize_changeset, etc).
* Fix: Same broader filter applied to the FAQ tab and AI Summary tab post type pickers — every user-facing post type picker in the plugin now sees the same complete CPT list.
* Fix: List is now alphabetically sorted by label so plugin CPTs surface alongside `post`/`page` instead of getting buried at the bottom.
* New: Two filter hooks for advanced customization — `rankready_allowed_post_types` and `rankready_author_post_types`.

= 0.5.1 =
* Repo went public at github.com/adityaarsharma/rankready. Auto-updates now flow from public GitHub releases — no token, no per-site config, install once and forget.
* Plugin Update Checker (PUC) now uses anonymous public GitHub API (no PAT required). Removed the RANKREADY_GITHUB_TOKEN constant requirement.
* Restored full marketing README with feature comparison table, schema flow diagrams, headless API examples, EEAT Author Box documentation, and v1.0 roadmap.
* Added LICENSE file (GPL-2.0-or-later) and SEO discoverability (repo description, topics).

= 0.5.0 =
* Internal dev baseline. Snapshot of all features built in earlier 0.x → 1.7.x iterations, repackaged as v0.5 in the new private adityaarsharma/rankready repo for controlled rollout. From this version forward, updates ship via GitHub releases + Plugin Update Checker (PUC).
* Features: AI Summary generator, FAQ generator with brand entity injection, Author Box with EEAT schema, Article JSON-LD with speakable, FAQPage JSON-LD, LLMs.txt + llms-full.txt generator, Markdown endpoints (.md), Robots.txt LLM crawler controls (31 bots), Bulk Author Changer, Content Freshness Alerts, Health Check, API usage tracking, Auto-Generate FAQ on Publish.
* Auto-updates: PUC integrated. Sites with `RANKREADY_GITHUB_TOKEN` defined in wp-config.php will pull new releases automatically.
