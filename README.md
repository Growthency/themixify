# Themify — Advanced Blogging & Affiliate WordPress Theme

A fast, beautiful, SEO-first WordPress theme with a **built-in growth suite**. Every
system from a modern content + affiliate site is baked into the theme — no stack of
plugins required — and it's engineered for a **100/100 PageSpeed** score on mobile
and desktop.

Everything is generic and brandable: your logo, colors, fonts, menus, homepage and
content are all controlled from the WordPress admin.

## Install

1. Zip the `themify` folder (or copy it) into `wp-content/themes/themify`.
2. **Appearance → Themes → Activate** "Themify".
3. **Settings → Reading**: leave the front page as-is — Themify's **front page is the
   Homepage Builder** (Themify → Homepage). Set a **Posts page** if you want a separate blog.
4. **Appearance → Menus**: assign menus to the *Primary Menu (Header)* and *Footer Menu* locations.
5. Open the new **Themify** menu in the admin sidebar — all tools live there.

Requires WordPress 6.0+ and PHP 7.4+.

## The Themify control panel (admin → Themify)

| Tool | What it does | What you need to configure |
|---|---|---|
| **Dashboard** | Links + quick access to every tool | — |
| **General** | Layout (sidebar/full), sticky header, breadcrumbs, excerpt length, and the **Performance** toggles | Optional |
| **Colors & Fonts** | Live theme palette + typography → drives the `--tf-*` CSS variables (no flash on load) | Optional; pick your brand colors |
| **Homepage** | Block builder for the front page: hero, post grids, categories, rich text, CTA | Build your homepage |
| **Header & Footer Code** | Paste any code into `<head>`, after `<body>`, or before `</body>` — analytics, verification tags, pixels | Paste snippets |
| **Custom CSS** | Global + per-post custom CSS | Optional |
| **SEO** | Titles, meta descriptions, Open Graph, canonical, robots, per-post SEO box, and **site verification** (Google/Bing/Pinterest/Yandex) | Verification codes |
| **Analytics** | GA4 tag injection **+** a GA4 + Search Console dashboard | GA4 ID; service account for the dashboard (below) |
| **Indexing** | IndexNow key + auto-submit on publish, Google Indexing API, submission log | Nothing for IndexNow (auto); service account for Google Indexing |
| **Rank Tracker** | Tracks your Google positions for chosen keywords via SerpAPI | A free SerpAPI key |
| **SEO Health** | One-click on-page SEO audit of your key pages | — |
| **Affiliate Links** | Cloaked `/go/slug` links, click counts, auto `rel="nofollow sponsored"` on outbound links, `[themify_button]` shortcode | Add your links |
| **Image Optimizer** | Auto WebP + compression + resize on upload, WebP serving, bulk optimize — *replaces Smush* | Optional |
| **Speed & Cache** | Full-page cache (anonymous), HTML minify, browser-cache/.htaccess helper, one-click purge — *replaces LiteSpeed Cache (PHP level)* | Optional |
| **Footer** | Copyright, social icons, payment badges, footer widgets | Optional |
| **AI Writer** | Generate SEO article drafts with Claude and save them as posts | An Anthropic API key |

### Run with zero plugins
Themify is designed to be self-sufficient. On a typical site you can safely deactivate:
**Rank Math / Yoast** (built-in SEO takes over), **Site Kit** (built-in Analytics + Search Console),
**Simple Author Box** (built-in author box), **Smush** (built-in Image Optimizer), and
**LiteSpeed / WP-cache plugins** (built-in Speed & Cache). Keep a **backup plugin** (e.g. UpdraftPlus) —
that's infrastructure, not a feature. If your content uses a page builder (Elementor, etc.), keep it;
Themify natively supports the **block editor (Gutenberg)**, so block-built content needs no plugin.

### Google Analytics + Search Console dashboard (service account)
The **Analytics** and Google **Indexing** tools read Google's APIs with a *service account*:
1. In Google Cloud, create a project and a **service account**; create a **JSON key**.
2. Enable the **Google Analytics Data API**, **Search Console API**, and (for Google
   Indexing) the **Indexing API**.
3. In GA4 → Admin → Property Access, add the service-account email as a **Viewer**.
4. In Search Console, add it as an **Owner/Full user** for your property.
5. In **Themify → Analytics**, paste the service-account **email**, **private key**,
   your **GA4 Property ID**, and site URL. (Just want the GA4 tag on the site? Only the
   **Measurement ID** is required.)

### IndexNow / Rank Tracker / AI Writer
- **IndexNow** works out of the box — a key is generated and the key file is served
  automatically; new/updated posts are pinged on publish.
- **Rank Tracker** needs a free [SerpAPI](https://serpapi.com/) key (250 searches/mo).
  You can add a backup key to stack quotas.
- **AI Writer** needs an [Anthropic API key](https://console.anthropic.com/) and lets
  you pick the Claude model.

## Why it's fast (100/100)
- One small stylesheet + one tiny **deferred**, framework-free script (no jQuery on the front end).
- **Critical CSS** is inlined so the page paints instantly — including your brand colors.
- Head bloat (emoji script, oEmbed, generator tags, jQuery Migrate) is removed.
- The first content image is prioritized (LCP); the rest lazy-load.
- Fonts default to a fast native stack; Google Fonts (Inter + Playfair) are opt-in.
- Every external API call (analytics, ranks, indexing, audits) is cached and only runs
  in the admin — never on a visitor's page load.

## Developer notes
- Architecture: `functions.php` → `inc/loader.php` auto-includes modules. Add a feature
  by dropping a file into `inc/modules/`, `inc/seo/`, or a template — see **CONTRACT.md**
  for the full conventions (prefixes, option keys, admin framework, CSS tokens, hooks).
- No build step. Pure PHP + CSS + vanilla JS.
- SEO meta/schema automatically stand down if Yoast, Rank Math, AIOSEO, or TSF is active.

## To finish before shipping
- Add a `screenshot.png` (1200×900) for the Appearance → Themes preview.
- Optionally add `languages/themify.pot` for translations.
