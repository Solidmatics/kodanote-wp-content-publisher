# Kodanote Content Publisher

The WordPress publishing integration for Kodanote. This repository contains the
plugin side only; the Kodanote backend still needs to implement this protocol.
The source has no build step. See [readme.txt](readme.txt) for installation,
features, tracking behavior, and limitations, and [NOTICE.md](NOTICE.md) for
attribution and license details.

## Configuration

Set these before WordPress loads plugins in `wp-config.php`:

```php
// Example addresses only: replace these with the implemented service URLs.
define('KODANOTE_API_BASE_URL', 'https://publishing.example.com/api');
define('KODANOTE_DASHBOARD_URL', 'https://dashboard.example.com');
```

The API URL defaults to an empty string. There is no automatic hostname-based
environment selection. Configure the per-site shared secret in **Kodanote >
Settings**. Deployed endpoints should use HTTPS. The dashboard link defaults to
`https://www.kodanote.com`.

## WordPress endpoints

Routes live under `/wp-json/kodanote/v1`:

| Method | Route | Purpose |
| --- | --- | --- |
| GET | `/handshake` | Respond to a pending, single-use installation challenge |
| POST | `/trigger-sync` | Pull articles, or receive an `articles` array directly |
| POST | `/force-republish` | Republish the requested `article_id` |
| POST | `/push-image` | Attach an image to a synced article |
| POST | `/conversion-event` | Proxy browser conversion events with a short-lived tracker token |
| POST | `/article-pageview` | Proxy browser pageviews/time-on-page; origin checks and rate limiting |

Publishing endpoints require both the site's shared API key and an HMAC-SHA256
signature. For JSON requests send:

```text
Authorization: Bearer <site-api-key>
Content-Type: application/json
X-Kodanote-Signature: <hex HMAC-SHA256 of the exact raw JSON body using site-api-key>
```

`X-Kodanote-API-Key` is supported when hosting strips `Authorization`. The body
fallback is named `kodanote_api_key`; the signature parameter is `_kodanote_sig`.
Multipart signatures cover the JSON encoding of sorted parameters, excluding
`file` and `_kodanote_sig`, with unescaped slashes and Unicode. The handlers in
`kodanote-content-publisher.php` are the source of truth for request validation
and response details.

Example push body (sign precisely the bytes you send):

```json
{
  "auto_publish": true,
  "articles": [
    {
      "id": "example-article-1",
      "title": "A publishing update",
      "content": "<p>Article content.</p>",
      "excerpt": "Article summary."
    }
  ]
}
```

Push publishing does not need an outbound base URL. Publication callbacks,
pulling, verification, and analytics do. The service must account for article
identity, updates, deletions, retries, and partial failures in the inherited sync
protocol; do not treat an HTTP success alone as proof every article published.

## Service endpoints to implement

Paths below are appended to `KODANOTE_API_BASE_URL`:

| Method | Path | Contract |
| --- | --- | --- |
| GET | `/articles/sync` | Article envelope including `articles`; supports `since` and connection-test `limit=1` |
| POST | `/plugin/initiate-handshake` | Receives `site_url` and `installation_token`; challenges WordPress and returns a site API key after proof validation |
| POST | `/webhooks/wordpress` | Receives signed event/data/timestamp/site URL envelopes |
| POST | `/conversion-events` | Receives signed conversion event batches |
| POST | `/article-pageviews` | Receives signed article pageview/time-on-page events |

Outbound requests identify the plugin with `X-Kodanote-Plugin-Version`.
Authenticated requests use Bearer credentials; signed event requests also send
`X-Kodanote-Signature`. Implement the handshake proof checks before issuing any
API key. See `attempt_auto_verification()` and `rest_handshake_callback()` for
the full exchange.

## Development checks

PHP source and assets run directly in WordPress; no bundling is required. Run PHP
syntax checks for every PHP file and `node --check` for each JavaScript asset.
`php -n tests/smoke.php` exercises the renamed integration boundaries with a small
WordPress stub harness. It does not replace testing on a real WordPress site
against the implemented Kodanote backend.
