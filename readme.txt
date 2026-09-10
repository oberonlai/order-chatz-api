=== OrderChatz API ===
Contributors: wpbrewer, oberonlai
Tags: orderchatz, rest-api, conversations, line
Requires at least: 6.5
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 1.2.1
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

REST API for OrderChatz DM conversations (list, detail, reply). Soft-depends on OrderChatz.

== Description ==

Companion plugin for OrderChatz. Exposes:

* GET /wp-json/order-chatz/v1/conversations
* GET /wp-json/order-chatz/v1/conversations/{id}
* POST /wp-json/order-chatz/v1/conversations/{id}/messages

Auth via WordPress Application Password (manage_options) or a site token (X-OTZ-API-Token / Bearer).

Does not modify OrderChatz core. Soft-depends on OrderChatz (admin notice if missing).

Reply prefers OrderChatz LineApiService / MessageStorageService when available; otherwise uses otz_access_token + monthly message tables.

== Installation ==

1. Install and activate OrderChatz.
2. Upload order-chatz-api and activate it.
3. Open OrderChatz → API and generate a site token.

== Changelog ==

= 1.2.1 =
* GET conversations/{id} messages[] (and last_message) include line_message_id, quote_token, quoted_message_id from DB.

= 1.2.0 =
* Media reply (image/video/file/sticker) + quote reply on POST conversations/{id}/messages (URL-based; 留言=引用).

= 1.1.0 =
* POST conversations/{id}/messages text reply (OrderChatz helpers or LINE fallback).

= 1.0.1 =
* Initial release (FM-93 / WPB-354): conversations list + detail, Application Password + site token auth.
