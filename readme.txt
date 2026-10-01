=== SHIED WORLD Schema Markup ===
Contributors: shiedworld
Tags: schema, json-ld, structured data, seo, schema.org
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Tested up to PHP: 8.4
Stable tag: 1.0.3
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Add custom JSON-LD schema markup to any page, post, or custom post type, plus site-wide schema. Built by SHIED WORLD.

== Description ==

SHIED WORLD Schema Markup is a universal schema markup plugin for WordPress. Add named JSON-LD schema blocks to pages, posts, and custom post types, and define site-wide schema that outputs on every page.

Features:

* Site-wide schema builder (outputs on every page)
* Page and post level named schema blocks
* The complete official schema.org vocabulary, with property fields for every type, plus Custom / Other raw JSON-LD
* Placeholder variables such as {{post_title}}, {{post_url}}, {{site_name}}, and more
* Merged frontend output as a single application/ld+json @graph script in the footer
* Post type checklist so the meta box only appears where you want it
* Rule-based suggestion engine with no external API calls
* Optional AI suggestions using your own OpenAI, Anthropic, Gemini, DeepSeek, Groq, or OpenRouter key (encrypted at rest)
* Google Rich Results Test links and live JSON-LD preview

== Optional AI Suggestions and External Services ==

The AI suggestion feature is optional. The plugin ships with no API key, and nothing is sent to any third party service unless you configure this feature yourself.

To use it you open the AI BYOK tab, select a provider, choose a model, and save your own API key for that provider. After that the plugin sends a request to the provider you selected, using the key you saved, and only at the moment you click the Suggest with AI button while editing a post. No request is made while you browse the settings, save a post, or view the front end, and no request is made automatically or by default.

The data sent depends on the "Send full post content to AI suggestions" option, which is OFF by default.

By default (full content mode OFF) the request contains:
* The post type (or 'site' for site-wide suggestions)
* The post title (or site name)
* The post's headings (H1 to H6), in document order, up to 25 of them, with their level
* Up to 350 characters of the post body (HTML tags and shortcodes stripped)
* The post's primary category or tag, if one is assigned
* The post's manual excerpt, up to 300 characters, when the post has one set; for site-wide suggestions the site description is sent instead
* The post permalink URL (or site home URL)

If you turn full content mode ON, the same list is sent with a larger body allowance (up to 3,500 characters instead of 350). That option is off by default because a short summary plus the heading outline is enough to classify the page, and sending the whole body costs significantly more tokens and can reach free-tier API limits faster.

The plugin also queries your provider's own models endpoint once every 24 hours, using your saved key for that provider, to populate the model dropdown. That request sends no post or site content. If it fails, or if no key is saved yet, the plugin falls back to a short built-in list of that provider's documented models and makes no further requests until the cache expires.

No other content from your site is sent. The reply from the provider is a short list of suggested schema.org types with a one-line reason each. These appear as separate cards in the editor and a schema block is created only if you accept one.

The request goes directly from your site to the provider you chose, using that provider's own API endpoint. It is not routed through any server operated by the plugin author. The plugin author has no access to your key and receives no copy of the data you send. The provider's own terms of service and privacy policy apply to that data.

== External Services ==

This plugin contacts a third party service only when you enable the optional AI suggestion feature, choose a provider, and save your own API key for that provider. Data is sent only when the user clicks the suggestion button, using their own API key. The services that can be used are listed below, each with links to its own privacy policy and terms:

* OpenAI: Privacy Policy (https://openai.com/policies/privacy-policy/) and Terms of Use (https://openai.com/policies/terms-of-use/)
* Anthropic: Privacy Policy (https://www.anthropic.com/legal/privacy) and Commercial Terms (https://www.anthropic.com/legal/commercial-terms)
* Google Gemini: Privacy Policy (https://policies.google.com/privacy) and Terms of Service (https://ai.google.dev/gemini-api/terms)
* DeepSeek: Privacy Policy (https://cdn.deepseek.com/policies/en-US/deepseek-privacy-policy.html)
* Groq: Privacy Policy (https://groq.com/privacy-policy/) and Terms (https://groq.com/terms-of-use/)
* OpenRouter: Privacy Policy (https://openrouter.ai/privacy) and Terms of Service (https://openrouter.ai/terms)

== Credits and Bundled Data ==

This plugin bundles the official schema.org vocabulary, stored at includes/data/schemaorg-vocabulary.json, and uses it as the source of truth for the schema types and properties offered in the builder.

The schema.org vocabulary is published by the schema.org project. The copyrights in the schema are licensed under the Creative Commons Attribution-ShareAlike License, version 3.0. To view a copy of that license, visit https://creativecommons.org/licenses/by-sa/3.0/.

The vocabulary file is included as published, stored locally as a JSON file. No endorsement by the schema.org project or its sponsors is implied.

The social media icons shown on the plugin settings screen are the official brand glyphs for each platform, taken from Font Awesome Free. Font Awesome Free icons are released under the Creative Commons Attribution 4.0 International license, https://creativecommons.org/licenses/by/4.0/. Each icon is used only to link to the plugin author's own profile on that platform.

The Fiverr, Upwork and WhatsApp icons used in the settings page header and on the SEO Services tab are taken from Simple Icons, https://simpleicons.org/, which releases its icon data under CC0 1.0 Universal, https://creativecommons.org/publicdomain/zero/1.0/. Each of those icons is used only to link to the platform it represents, and the trademarks in those logos remain the property of their respective owners.

The plugin code itself is licensed under the GNU General Public License, version 3 or later. See the License and License URI fields above. Version 3 or later was chosen deliberately: the bundled schema.org vocabulary is licensed CC BY-SA 3.0, which is compatible with GPLv3 but not with GPLv2 alone, so GPLv3-or-later lets the plugin and that bundled data be redistributed together without a licensing conflict.

== Installation ==

1. Upload the `shied-world-schema-markup` folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Open the SHIED WORLD Schema Markup page in the admin menu to configure Site Schema and other tabs.
4. Edit any enabled post type and use the SHIED WORLD Schema Markup meta box.

== Frequently Asked Questions ==

= Does this work with custom post types? =

Yes. Enable them under Post Type Settings. The list is built from get_post_types() so theme and plugin post types are included.

= Are AI keys stored in plain text? =

No. New keys are encrypted with authenticated AES-256-GCM using salt material from wp-config.php, preferring AUTH_KEY. The plugin never ships a shared fallback key. Keys saved by older, unauthenticated CBC encryption are intentionally rejected; enter those keys again once to save them in the current format.

= What data is sent when I use an AI suggestion? =

Data is sent only when you click Suggest with AI in the editor, using your own API key saved for a provider you chose. What that request contains depends on the "Send full post content to AI suggestions" option, which is off by default. With that option off, the request contains the post type (or 'site' for site-wide suggestions), the post title (or site name), the post's headings (H1 to H6) in document order up to 25 of them with their level, up to 350 characters of the post body with HTML tags and shortcodes stripped, the post's primary category or tag if one is assigned, the post's manual excerpt up to 300 characters when the post has one set (or the site description for site-wide suggestions), and the post permalink URL (or site home URL). Turning full content mode on sends the same list with a larger body allowance, up to 3,500 characters instead of 350. No excerpt is generated when a post has none set, and no post body is sent at all for site-wide suggestions. The request goes directly to that provider and is governed by that provider's terms and privacy policy. Nothing is sent by default, and nothing is sent automatically.

= Where is schema output printed? =

In wp_footer as one script tag with type application/ld+json using the @graph structure. If there is nothing to output, no script tag is printed.

== Additional Information ==

Plugin home page: https://shiedworld.com/shied-world-schema-markup-wordpress-plugin/

Developer: SHIED WORLD, https://shiedworld.com/

Privacy Policy: https://shiedworld.com/privacy-policy-shied-world-schema-markup/

Terms and Conditions: https://shiedworld.com/terms-conditions-shied-world-schema-markup/

== Changelog ==

= 1.0.3 =
* A brand new schema block now always opens with an empty Schema type box, so the type is chosen deliberately instead of appearing already filled in. Some browsers autofill text inputs before any page script runs and ignore autocomplete="off", which could leave a value such as Organization sitting in the box of a block that has no type set.
* A block that does have a type chosen normally keeps showing it. Only a box whose stored type is still empty is emptied again, and a box you are typing in at that moment is left alone.

= 1.0.2 =
* Schema blocks now start collapsed. A site with several saved blocks reopened every block fully expanded on each page load, which made the Site Schema tab and the post editor very long and left the saved blocks hard to scan. Blocks loaded from saved data now appear as a compact list of headers.
* A block you add or duplicate still opens expanded and ready to fill in. Each block also keeps its own open or closed state while you work on the others, so adding one block no longer expands every other block at the same time.
* The block collapse control now reports its open or closed state to screen readers through aria-expanded.

= 1.0.1 =
* Fixed the Site Schema tab silently failing to save. Saving could store an empty value and report no error, so the site-wide schema never reached the frontend output. The save now keeps your previously saved blocks when the submitted data cannot be read, and shows a clear message instead of failing quietly.
* Site Schema: a value containing a quote or a backslash (for example a business name written as Say "Hello" Ltd) is now saved correctly. The block data was previously unescaped twice by WordPress, which corrupted the stored JSON.
* Site Schema: saving with the builder script not loaded no longer deletes every saved block.

= 1.0.0 =
* Initial release.
* Renamed to SHIED WORLD Schema Markup (slug shied-world-schema-markup) at the request of the WordPress.org review team. The plugin folder name, plugin file, and text domain all changed accordingly, so any existing site must delete the old easy-schema-by-shied-world folder and upload this package fresh.
* Fixed AI suggestions reporting "This page already has good schema coverage" when the provider had actually cut the response short. The cutoff is now detected from the provider's finish reason and reported as a retryable error instead.
* Raised the AI response token limit and disabled unnecessary model reasoning, so a full reply is no longer truncated to a few unusable tokens.
* Schema fields that use a placeholder now show the resolved value, such as the current page URL, instead of a raw placeholder token.
* Placeholders that cannot be resolved are now omitted from the output instead of publishing the literal token text into the page.

== Screenshots ==

1. Site Schema builder — add site-wide schema that outputs on every page.
2. AI BYOK settings — bring your own API key from OpenAI, Anthropic, Gemini, DeepSeek, Groq, or OpenRouter, including free-tier options.
3. Schema Blocks panel in the post editor sidebar, with a quick link to jump to the meta box.
4. AI Suggestion and Add Schema Block buttons inside the post editor meta box.
5. Search and select from 750+ free schema.org types, fully categorized.
6. Over 100 property fields per schema type for complete, detailed markup.
7. AI-powered schema type suggestions based on your page content.
8. Live JSON-LD preview for every schema block before you publish.
9. One-click validation against Google's official Rich Results / Schema.org tester.
