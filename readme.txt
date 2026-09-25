=== Kodanote Content Publisher ===
Tags: content, publishing, scheduling, automation
Requires at least: 5.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.3.113
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Kodanote to sync, schedule, and publish your content with your preferred categories and authors.

== Description ==

Kodanote helps teams create, schedule, and publish content. This plugin provides the WordPress side of that workflow.

The Kodanote backend integration is still to be implemented. Installing this plugin alone does not connect an account. Outbound service requests remain disabled until a publishing API URL is explicitly configured.

= Features =

* Article sync, authenticated push publishing, and scheduled publication.
* Automatic site verification through a challenge-response handshake.
* Default categories and authors, article updates, and publication webhooks.
* Featured images, infographics, YouTube embeds, author boxes, and SEO metadata.
* Compatibility with supported SEO, translation, and page-builder plugins.
* Sitemap discovery and fallback sitemap generation.
* Markdown versions of public, unprotected managed articles.
* Conversion tracking, article pageviews, and time-on-page reporting through the configured service.
* Adaptive background sync, diagnostics, and sync notifications.

= Service configuration =

Define KODANOTE_API_BASE_URL in wp-config.php using the publishing service's actual base URL, including its API path. No production endpoint is assumed. Use HTTPS for deployed sites. Local development endpoints can be configured explicitly.

Optionally define KODANOTE_DASHBOARD_URL to point the "Open Kodanote" link at your dashboard. Its default is https://www.kodanote.com.

Enter the site's shared API key in Kodanote > Settings. Automatic verification requires the backend handshake endpoint. Pull sync requires the article sync endpoint. Authenticated push can receive content without an outbound service URL, but webhooks and tracking require that URL.

See README.md for the route and authentication contract to implement in the backend.

= Privacy and data =

The configured publishing service receives the site URL, plugin and WordPress versions during verification, credentials or authentication proofs, and article metadata and publication events during sync.

When both the API URL and API key are configured, the included browser tracker runs on frontend pages. It stores a visitor identifier and article attribution in localStorage, observes supported Google Ads and Meta Pixel conversion calls, and sends conversion values/currency, page URLs, referrers, article attribution, pageviews, and time-on-page through WordPress to the configured service. The plugin does not provide a consent-management interface. Configure site privacy disclosures and consent handling for the tracking behavior before deployment.

The API key is used server-side and is not exposed to the browser tracker. This plugin has no hardcoded external publishing or analytics service endpoint.

== Installation ==

1. Upload the kodanote-content-publisher directory into wp-content/plugins, or upload its ZIP through Plugins > Add New > Upload Plugin.
2. Activate Kodanote Content Publisher.
3. Configure the publishing service URL in wp-config.php when the backend is available.
4. Open Kodanote > Settings, enter the site's API key, and choose a default category and author.
5. Test the connection and sync articles, or send authenticated push requests from the backend.

== Frequently Asked Questions ==

= Does this connect to a working Kodanote publishing service today? =

No. The WordPress integration in the Kodanote backend still needs to implement the documented contract.

= Does it import another plugin's existing data? =

No. Kodanote uses its own options, tables, metadata, routes, and cron hooks. This is a separate plugin, intended for a fresh installation.

= Can I edit articles in WordPress? =

Yes. Categories, authors, SEO fields, and other WordPress settings remain available. Editing a managed article's body marks it as user-managed so later syncs preserve those edits. Managed articles can be trashed; permanent deletion is blocked while the plugin manages them.

= What happens on deactivation or uninstall? =

Deactivation stops scheduled plugin work and removes its marked physical robots.txt block. Published WordPress posts and media remain. Uninstall removes plugin settings, sync tables, metadata, and scheduled events; it does not delete published posts or media.

= Are frontend shortcode templates included? =

The kodanote shortcode supports the dashboard and articles template names. The copied source did not include either frontend template. It returns an administrator-only explanation when a template is missing. Use the WordPress admin dashboard to manage content.

== Changelog ==

= 1.3.113 =
* Initial Kodanote edition with renamed plugin identifiers, REST namespace, headers, storage, assets, and translation domain.
* Kodanote branding and explicit service URL configuration.
* Retained publishing, scheduling, verification, analytics, and content-rendering features.
* Protected private/password-protected articles from the public Markdown endpoint.
* Disabled debug logging by default and removed credentials from browser diagnostics.
* Improved uninstall cleanup and safe handling of missing frontend templates.

== License ==

This plugin is distributed under GPLv2 or later, without warranty. See LICENSE for the license text and NOTICE.md for source attribution and modification details.
