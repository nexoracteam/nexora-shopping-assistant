=== Nexora Shopping Assistant for WooCommerce ===
Contributors: nexoracreation
Tags: woocommerce, shopping assistant, ai, chatbot, product recommendations
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A bring-your-own-key AI shopping assistant that recommends products from your WooCommerce catalogue and adds simple products to the cart.

== Description ==

Nexora Shopping Assistant adds a chat popup to your WooCommerce store. Shoppers describe what they want in their own words, and the assistant answers with a short reply and product cards from your catalogue.

You connect the assistant to an AI provider using **your own API key** for Groq, Google Gemini or OpenAI. The plugin itself is free; provider usage is billed by the provider to your account.

= Features =

* Product cards are checked against the public, purchasable products that were sent to the AI model. Product IDs the model invents are rejected, and prices and stock are re-read from WooCommerce before display.
* Simple products can be added to the cart from the chat. WooCommerce's own add-to-cart validation runs. Variable and other product types link to their product page.
* Choose a primary provider and optional fallback providers. Available models are discovered from your provider account; retired models are blocked.
* Optional store context: build a bounded snapshot of public policy pages, products, categories and menus to give the assistant store-specific answers.
* Customise the assistant name, welcome and failure messages, colours, fonts, logo, avatar and the system prompt.
* Open the assistant with the floating launcher, the `[nexora_shopping_assistant]` shortcode, a `#nexora-shopping-assistant` link, or the JavaScript API.
* Privacy controls: transcript storage is off by default, configurable retention, WordPress personal-data export and erasure for account-linked records, and an optional customer privacy notice.
* API keys are encrypted at rest and never sent to the browser.

= Important limitations =

* AI-generated text can be inaccurate. Review your system prompt, store policies and sample answers before enabling the assistant for customers.
* Bundles, grouped products and similar extension product types are not recommended or added to the cart from chat.
* Network activation on multisite is not supported; activate the plugin on each site.
* English user interface. A translation template (`languages/nexora-shopping-assistant.pot`) is included.

== Installation ==

1. Make sure WooCommerce is installed and active, and that the server runs PHP 8.1 or newer with the mbstring extension and Sodium or OpenSSL.
2. Recommended: add a unique, random secret of at least 32 characters to `wp-config.php` before saving API keys. Without it, WordPress salts are used for encryption:
   `define( 'CONVOCART_ENCRYPTION_KEY', 'your-unique-random-secret' );`
   (The constant keeps its original name for compatibility with existing installations.)
3. Install the plugin from **Plugins → Add New** (or upload the ZIP) and activate it.
4. Open **Nexora Assistant → Providers**. Save an API key, wait for the model list to load, choose and save a model, then click **Test connection**.
5. Choose a primary provider. Fallback providers are off until you select them.
6. Open **Knowledge Base** and confirm that product synchronization completes.
7. Optionally, run **Analyze** to build store context.
8. In **Settings & Styling**, adjust the assistant, then turn on **Enable assistant**. The assistant is off on new installations until you do this.

= Upgrading from ConvoCart AI =

This plugin was previously distributed as "ConvoCart AI" (folder `convocart`). It reuses the same database tables, settings and encrypted keys, so no data migration is needed.

1. Back up your site and database.
2. Deactivate **ConvoCart AI**.
3. Install and activate **Nexora Shopping Assistant for WooCommerce**. Your settings, keys and data are picked up automatically.
4. Before deleting the old ConvoCart AI plugin, make sure **Preserve data on uninstall** is enabled in Settings & Styling. The old plugin's uninstaller removes the shared data when that option is off.

The `[convocart]` shortcode, `#convocart` links, the `window.ConvoCart` JavaScript API, the `CONVOCART_ENCRYPTION_KEY` and `CONVOCART_TRUSTED_PROXIES` constants, and the `/wp-json/convocart/v1/` REST routes continue to work.

== Frequently Asked Questions ==

= Is the shortcode an inline chat? =

No. `[nexora_shopping_assistant]` renders a button that opens the shared chat popup. Several buttons on one page open the same conversation. Use `[nexora_shopping_assistant id="footer"]` to label individual buttons.

= Does a saved API key mean the assistant works? =

No. A saved key only means it is stored encrypted. Save a model and run **Test connection**, which sends a short test request to the provider.

= Are AI provider costs included? =

No. You pay your chosen provider directly. Each customer message can result in several provider requests when retries or fallback providers are used. Set spending limits in your provider account.

= What is stored when transcript logging is off? =

A short-term conversation memory (the last 10 messages and up to 8 product IDs) is kept for up to 24 hours of inactivity so follow-up questions work. Feedback that a customer chooses to send includes the latest question, answer and optional comment.

= Customers behind Cloudflare or a proxy see "please wait" errors. =

Requests are rate-limited per IP address. If your host does not restore visitor IP addresses, configure trusted real-IP restoration or define `CONVOCART_TRUSTED_PROXIES` in `wp-config.php` with your proxy's current IP ranges.

= Should page caching include the assistant's endpoints? =

No. Exclude `/wp-json/convocart/v1/*` and the `admin-ajax.php?action=convocart_nonce` endpoint from page/CDN caching.

= Why was my analyzed store context cleared? =

Relevant content changes (pages, products, menus, site title and similar) clear the snapshot so outdated or newly private content is not sent to AI providers. Run Analyze again to rebuild it.

= How do I report a security issue? =

Please report security issues privately through the contact page at https://nexoracreation.com/contact-us/. Do not include API keys or customer data in reports.

== External services ==

This plugin connects to the AI provider services that the site administrator explicitly configures. No data is sent to any provider until an administrator saves that provider's API key, and customer conversations are only sent to providers selected as primary or fallback. The plugin does not send data to Nexora Creation.

= Groq =

Used to generate shopping-assistant replies when Groq is configured.

* Chat replies: when a shopper sends a message, and when an administrator runs **Test connection**, the plugin sends a request to `https://api.groq.com/openai/v1/chat/completions` containing the system prompt (assistant name, store name, optional analyzed store context), up to 8 candidate products (name, price, currency, stock status, attributes, categories, and description excerpts), the shopper's recent conversation messages and the current message, and the saved API key.
* Model discovery: after a key is saved, when the Providers screen loads, and hourly via WP-Cron, the plugin requests `https://api.groq.com/openai/v1/models` with the API key. No conversation data is sent.
* Groq terms: https://console.groq.com/docs/legal/services-agreement and https://groq.com/terms-of-use
* Groq privacy policy: https://groq.com/privacy-policy

= Google Gemini API =

Used to generate shopping-assistant replies when Gemini is configured.

* Chat replies: when a shopper sends a message, and when an administrator runs **Test connection**, the plugin sends a request to `https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent` with the same categories of data listed for Groq, and the saved API key.
* Model discovery: after a key is saved, when the Providers screen loads, and hourly via WP-Cron, the plugin requests `https://generativelanguage.googleapis.com/v1beta/models` with the API key. No conversation data is sent.
* Gemini API terms: https://ai.google.dev/gemini-api/terms
* Google privacy policy: https://policies.google.com/privacy

= OpenAI =

Used to generate shopping-assistant replies when OpenAI is configured.

* Chat replies: when a shopper sends a message, and when an administrator runs **Test connection**, the plugin sends a request to `https://api.openai.com/v1/chat/completions` with the same categories of data listed for Groq, and the saved API key.
* Model discovery: after a key is saved, when the Providers screen loads, and hourly via WP-Cron, the plugin requests `https://api.openai.com/v1/models` with the API key. No conversation data is sent.
* OpenAI services agreement: https://openai.com/policies/services-agreement/
* OpenAI privacy policy: https://openai.com/policies/privacy-policy/

The plugin does not send shopper names, email addresses, IP addresses, account details or cart contents to these services. Shoppers may still type personal information into their messages, which is sent as part of the conversation. Use the customer privacy notice setting to tell shoppers that messages are processed by AI providers.

== Privacy ==

* **Short-term memory:** the last 10 messages and up to 8 product IDs per conversation, stored as WordPress transients (or your object cache), expiring after 24 hours of inactivity.
* **Transcripts:** off by default. When enabled, messages are stored in the plugin's database tables and deleted after the configured retention period (default 30 days).
* **Feedback:** rating, optional comment and the latest question and answer, kept for the conversation retention period. Optional email notification to the store.
* **Analytics:** allowlisted interaction events with a pseudonymous session hash, kept for the configured period (default 90 days). Linking to customer accounts is off by default.
* **Rate limiting:** short-lived counters derived from a keyed hash of the IP address, session and user ID.
* **Personal data tools:** WordPress export and erasure cover records linked to customer accounts. Guest records cannot be found by email address.
* **Uninstall:** data, settings and keys are kept by default. Turn off **Preserve data on uninstall** before deleting the plugin to remove the plugin's tables and options.

The plugin adds suggested text to **Settings → Privacy → Policy Guide**. Review it before publishing your privacy policy.

== Screenshots ==

1. Chat popup with product recommendations and add-to-cart.
2. Providers screen with encrypted API keys, model selection and fallback order.
3. Settings & Styling screen.

== Changelog ==

= 1.2.3 =
* Updated the plugin URI to the dedicated Nexora Shopping Assistant product page.
* Corrected the security-report contact URL.
* Updated translation-template URL metadata. Provider API endpoints and legacy ConvoCart compatibility routes are unchanged.

= 1.2.2 =
* Renamed to Nexora Shopping Assistant for WooCommerce (slug and text domain `nexora-shopping-assistant`). Existing ConvoCart AI data, settings, keys, shortcode, hash links, JavaScript API and REST routes remain compatible.
* Added the `[nexora_shopping_assistant]` shortcode, the `#nexora-shopping-assistant` hash link and the `window.NexoraShoppingAssistant` JavaScript API alias.
* Security hardening: every custom-table query now passes table names through `$wpdb->prepare()` identifier placeholders; fixed-shape catalogue search query; stricter API-key validation before keys are used in HTTP headers.
* Fixed double-escaped assistant names in shortcode buttons and a double-escaped status code in admin notices.
* Diagnostic logging now only runs when `WP_DEBUG_LOG` is enabled.
* Admin screen and script strings are now translatable; added translator comments.
* Removed the custom `Update URI` header and the manual text-domain loader in favour of WordPress.org updates and translations.
* Restores plugin capabilities if an earlier ConvoCart AI uninstall removed them, and warns before the old plugin is deleted with data purging enabled.
* Added an External services section to this readme.

= 1.2.1 =
* Respect WooCommerce add-to-cart validation; limit in-chat purchases to simple products.
* Persist cart replay results and prevent concurrent or uncertain request takeover for 24 hours.
* Correct shortcode button styling and hash-only asset loading.
* Start new stores with the assistant disabled and no fallback providers.
* Preserve saved model IDs during migrations.
* Invalidate analyzed context on relevant changes, verify its sources, and add manual clearing.

= 1.2.0 =
* Automatic credential-scoped model discovery after key saves, in the Providers screen and via background jobs.
* Known retired models blocked in selection, settings and completion calls; upcoming shutdown dates displayed.

= 1.1.0 =
* Model-aware payloads, JSON response validation, reliable discovery and explicit fallback opt-out.
* Separate temporary memory and optional transcripts; feedback export, erasure and retention; corrected uninstall.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.2.3 =
URL and metadata maintenance update. No settings or data migration is required. Provider endpoints and legacy ConvoCart compatibility remain unchanged.

= 1.2.2 =
Renamed to Nexora Shopping Assistant. Deactivate ConvoCart AI before activating this version, and keep "Preserve data on uninstall" enabled before deleting the old plugin. Settings and data carry over.
