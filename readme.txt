=== RankReady – AI & LLM SEO for ChatGPT, Perplexity & Google AI ===
Contributors: posimyth, adityaarsharma
Tags: ai seo, llms.txt, schema markup, chatgpt, faq
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-first SEO for WordPress. Get cited by ChatGPT, Perplexity & Google AI Overviews. LLMs.txt, FAQ schema, EEAT author box, AI crawler controls.

== Description ==

**RankReady is the AI/LLM SEO toolkit for WordPress.** It optimizes your content for the new generation of search — ChatGPT, Perplexity, Google AI Overviews, Gemini, Claude — without conflicting with your existing SEO plugin.

Built by [POSIMYTH](https://posimyth.com) (makers of The Plus Addons for Elementor & NexterWP), RankReady ships every feature you need to be discovered, read, and cited by AI search engines.

= 🎯 What RankReady Does =

Traditional SEO plugins (Rank Math, Yoast, AIOSEO) optimize for Google's blue-link results. **RankReady optimizes for the layer above that** — the AI summaries, citations, and answer engines that increasingly intercept your traffic before users see Google's results.

= 🚀 Free Features (everything below, no upsell) =

* **LLMs.txt + LLMs-full.txt Generator** — Serves the [llmstxt.org](https://llmstxt.org) standard at `/llms.txt` and `/llms-full.txt`. Helps AI crawlers understand your site structure. **Unlimited.**
* **AI Crawler Controls (31 Bots)** — Granular allow/block for GPTBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot-Extended, CCBot, Bytespider, and 24 more. Auto-syncs to physical `robots.txt`.
* **AI Summary Generator** — Generate "Key Takeaways" for any post via OpenAI. Auto-injected as a styled block + `Speakable` schema for voice/AI assistants. **5 free per month.**
* **FAQ Generator with FAQPage Schema** — Discovers real user questions via DataForSEO + answers via OpenAI. Outputs FAQPage JSON-LD that Google AI Overviews and Perplexity preferentially cite. **5 free per month.**
* **Article + Speakable JSON-LD Schema** — Auto-injected on every post. Coexists smartly with Rank Math / Yoast / AIOSEO (no duplicate schema).
* **Markdown Endpoints** — Every post available as clean Markdown at `/post-slug.md` with YAML frontmatter and content negotiation via `Accept: text/markdown`. AI agents read this format natively.
* **Basic Author Box** — Display-only author bio with photo, headline, and topics. Bio, headshot, job title, and year-started fields support EEAT signals.
* **AI Crawler Detection Counter** — Live counter of how many AI bots visited your site this week.
* **Bulk Author Changer** — Reassign authors across any post type (including CPTs) with preview and progress bar.
* **Content Signals** — Adds `ai-train`, `search`, `ai-input` directives to robots.txt per [contentsignals.org](https://contentsignals.org).
* **Health Check Score** — Single-glance AI Readiness score for your site.
* **Gutenberg Block + Elementor Widget** — Drop-in display for AI Summary and Author Box, full style controls.

= 🤝 Works With Your Existing SEO Plugin =

RankReady is **designed to coexist** with the SEO plugin you already use. It detects active SEO plugins and either skips its own output (when there'd be a duplicate) or merges its data into theirs:

* **Rank Math** — Person/Article schema fields are merged into Rank Math's existing graph via filters.
* **Yoast SEO** — Same merge pattern. RankReady never emits duplicate Person nodes when Yoast is active.
* **All in One SEO (AIOSEO)** — Same merge pattern.
* **SEOPress, SEO Framework, Slim SEO** — Coexists; RankReady supplies AI-specific fields none of them cover (LLMs.txt, AI crawler control, markdown endpoints).

You don't replace your SEO plugin. You add RankReady on top.

= 🌟 RankReady Pro — More Coming Soon =

This release ships with everything above for free. RankReady Pro (launching in a future release) will add **more value** on top — not lock existing free features behind a paywall:

* Unlimited AI Summaries + FAQ generation
* Auto-generate on publish + bulk-process all existing posts
* Full EEAT Author Schema (Person JSON-LD with credentials, Wikidata, ORCID, sameAs)
* AI Crawler Analytics dashboard (which bots, what pages, when)
* HowTo + ItemList schema auto-detection
* Headless REST API for Next.js / Nuxt / Astro
* Custom Post Type support
* Per-post AI Readiness Score with fix suggestions

Every feature in the free version stays free, forever.

= 🔐 Privacy & Third-Party Services =

RankReady is privacy-respecting by default. POSIMYTH does not collect, store, or transmit any data from your site. The plugin only contacts third-party services when **you explicitly enter API credentials and trigger a generation**:

* **OpenAI** ([Terms](https://openai.com/policies/terms-of-use) · [Privacy](https://openai.com/policies/privacy-policy)) — When you generate an AI Summary or FAQ, the post's content (title + body excerpt) is sent to OpenAI's API using **your own API key** to receive the generated text. No content leaves your site without an active generation request initiated by you.
* **DataForSEO** ([Terms](https://dataforseo.com/terms-and-conditions) · [Privacy](https://dataforseo.com/privacy-policy)) — When you generate FAQs, the post's primary keyword is sent to DataForSEO using **your own credentials** to discover related user questions.
* **WordPress.org** — Plugin update checks are handled by core WordPress and follow your site's existing update settings. RankReady does not add any update checks beyond what WordPress already does.

No telemetry. No analytics. No "phone home". Your API keys are stored only in your own `wp_options` table.

== Installation ==

= Easy install (recommended) =

1. In WordPress admin, go to **Plugins → Add New**.
2. Search for **"RankReady"**.
3. Click **Install Now**, then **Activate**.
4. Visit **RankReady** in the admin menu.
5. Add your OpenAI API key in the **Settings** tab (required for AI Summary + FAQ generation).
6. Optionally enable LLMs.txt, Markdown endpoints, and AI crawler controls in the **AI Crawlers** tab.

= Manual install =

1. Download the plugin zip.
2. Go to **Plugins → Add New → Upload Plugin** and select the zip.
3. Activate, then follow steps 4–6 above.

= After install =

* Visit your site at `/llms.txt` to confirm the LLMs.txt file is being served.
* Open any post and use the **AI Summary** meta box to generate your first summary.
* Add the **RankReady Author Box** Gutenberg block (or Elementor widget) to a post to display the author bio.

== Frequently Asked Questions ==

= Do I need to remove my existing SEO plugin? =

No. RankReady is designed to work **alongside** Rank Math, Yoast, AIOSEO, SEOPress, SEO Framework, and Slim SEO. It detects them and avoids emitting duplicate schema. You can keep your current SEO plugin and add RankReady for the AI/LLM-specific features none of them cover.

= Do I need an OpenAI API key? =

Only if you want to use the **AI Summary** or **FAQ Generator** features. The LLMs.txt generator, Markdown endpoints, AI crawler controls, Article schema, and Author Box all work without any API key.

= Are there usage limits? =

The free version generates **5 AI Summaries** and **5 FAQ generations** per calendar month. Limits reset on the 1st of each month. All other features (LLMs.txt, robots.txt controls, schema, markdown endpoints, author box, bulk author changer) are unlimited.

= What's an "llms.txt" file? =

`llms.txt` is an emerging standard ([llmstxt.org](https://llmstxt.org)) that lets AI models like ChatGPT, Perplexity, and Claude understand your site's structure faster. Think of it as an "AI sitemap" — a curated index of your most important content optimized for LLM consumption. RankReady generates and serves both `/llms.txt` (index) and `/llms-full.txt` (full content) automatically.

= Will this slow down my site? =

No. All AI generation happens in the WordPress admin (not on page load). Schema and markdown endpoints add a few hundred bytes to the page. The LLMs.txt and robots.txt files are static-cached. Frontend impact: zero.

= Does this work with my caching plugin? =

Yes. RankReady is tested with WP Rocket, LiteSpeed Cache, W3 Total Cache, WP Super Cache, WP Fastest Cache, Breeze, SG Optimizer, Hummingbird, Comet Cache, Cache Enabler, Swift Performance, and Pantheon. It also handles Cloudflare APO correctly.

= Does this support Custom Post Types? =

The free version supports **Posts and Pages**. Custom Post Type (CPT) support across all features is included in the upcoming Pro version.

= Where is my data stored? =

Everything stays on your own WordPress site. Your OpenAI key, DataForSEO credentials, generated summaries, FAQs, and author profiles all live in your own `wp_options` and `wp_postmeta` tables. POSIMYTH does not see, collect, or transmit any of your data.

= Will RankReady conflict with my existing schema? =

No. Before injecting any schema, RankReady checks if Rank Math, Yoast, AIOSEO, or another schema-generating plugin is active. If yes, it skips its own output (or merges its fields into the existing schema graph) to prevent duplicates. Verifiable with Google's Rich Results Test.

= How do I uninstall it cleanly? =

By default, RankReady **preserves your data on uninstall** — your settings, API keys, summaries, FAQ data, and author profiles all survive. If you want a complete wipe, enable the "Delete all data on uninstall" toggle in the **Advanced → Tools** tab before uninstalling.

= Is the source code available? =

Yes. RankReady is open source under GPL-2.0-or-later. Source: [github.com/adityaarsharma/rankready](https://github.com/adityaarsharma/rankready).

== Screenshots ==

1. RankReady Dashboard — see your AI SEO status at a glance with stats and quick navigation.
2. Content AI tab — generate AI Summaries and FAQs for any post; track monthly usage.
3. Authority tab — Author Box settings + Schema controls (FAQPage, Article, Speakable).
4. AI Crawlers tab — block or allow 31 AI bots, generate LLMs.txt, enable Markdown endpoints.
5. Settings tab — single-screen config for OpenAI, DataForSEO, and brand context.
6. Author Box on the frontend — clean EEAT-optimized bio displayed at the end of every post.
7. AI Summary block — auto-injected "Key Takeaways" with Speakable schema for voice assistants.
8. FAQ block — expandable Q&A with FAQPage JSON-LD that AI Overviews preferentially cite.

== Changelog ==

= 1.0.0 — 2026-04-23 =

**First public freemium release for WordPress.org.**

* New: Freemium model — 5 AI Summaries + 5 FAQ generations per calendar month, all other features unlimited.
* New: Plan banner and usage meters in the Dashboard.
* New: Pro feature preview — locked sections clearly marked with "Launching with RankReady Pro" labels (no upsell pressure, no buy buttons in v1.0).
* New: Author profile page splits Free fields (Identity, Experience) from Pro fields (Credentials, Verified Identity, Social, Contact).
* New: Privacy & Third-Party Services disclosure section per WordPress.org guidelines.
* Improved: Admin UI — single-card pattern for Summary Display + FAQ Display (consistent layout, no collapsed accordions).
* Improved: Pro feature gates use a uniform card design with lock icon + "Launching with RankReady Pro" copy.
* Improved: Sticky tab navigation, scale-on-press feedback, 3-layer card shadows, tabular-nums for dynamic counters, staggered enter animations — applied per the make-interfaces-feel-better design checklist.
* Improved: Tab labels are text-only (icons removed for a cleaner WP-native look).
* Removed: Plugin Update Checker (PUC) library — WordPress.org distribution uses native WP update flow only.
* Removed: GitHub-based auto-update path — free version updates exclusively via WordPress.org.
* Branding: Plugin URI → posimyth.com. Author → POSIMYTH Inc. & Aditya Sharma.

For the full pre-1.0.0 development history (versions 0.5.0 through 0.6.7.2), see the [GitHub repository](https://github.com/adityaarsharma/rankready/blob/main/CHANGELOG.md).

== Upgrade Notice ==

= 1.0.0 =
First public release on WordPress.org. Existing users on the GitHub-distributed v0.6.x: this version removes the GitHub auto-update path. After upgrading to 1.0.0, all future updates will arrive via WordPress.org's standard plugin updater.
