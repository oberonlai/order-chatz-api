# OrderChatz API

Companion plugin: REST API for OrderChatz DM conversations (list, detail, **reply**). **Does not modify OrderChatz core.**

- **Version:** 1.1.0
- **Text Domain:** `otzapi`
- **Requires:** WordPress 6.5+, PHP 8.0+, active OrderChatz (`OTZ_VERSION`)

Ticket: Firstmate FM-93 / Linear WPB-354

## Install

1. Install and activate **OrderChatz**
2. Upload `order-chatz-api` and activate **OrderChatz API**
3. Open **OrderChatz → API** (or **Settings → API**) and generate a site token

## Auth

Either:

1. **WordPress Application Password** for a user with `manage_options` (Basic auth / logged-in REST), or
2. **Site token** via:
   - Header `X-OTZ-API-Token: <token>`, or
   - Header `Authorization: Bearer <token>`

Token grants access to the endpoints below. Option key: `otzapi_site_token` (plaintext).

## Endpoints

Namespace: `order-chatz/v1`  
Base URL: `https://example.com/wp-json/order-chatz/v1`

### GET `/conversations`

List DM friends from `{prefix}otz_users` (`status=active`, `source_type=user`).

| Query | Default | Description |
| --- | --- | --- |
| `unread` | `0` | `1` = only rows with unread inbound messages |
| `last_from_customer` | `0` | `1` = last message sender is customer (`user`) |
| `limit` | `20` | Max `100` |
| `offset` | `0` | Pagination offset |

Response shape:

```json
{
  "items": [ { "id": 1, "line_user_id": "...", "unread_count": 2, "last_message": {}, "last_from_customer": true, "...": "..." } ],
  "total": 42,
  "limit": 20,
  "offset": 0
}
```

### GET `/conversations/{id}`

`{id}` = `otz_users.id`. Returns friend, recent DM messages (empty `group_id`, last 2 month partitions), and up to 5 WooCommerce order summaries when `wp_user_id` is set and WooCommerce is available.

### POST `/conversations/{id}/messages`

Send an outbound **text** reply to the conversation (media later).

**Body (JSON):**

| Field | Required | Description |
| --- | --- | --- |
| `message` | yes | Text body (max 5000 chars). Alias: `text`. |
| `quote_token` | no | LINE quote token |
| `quoted_message_id` | no | Quoted LINE message id |

**Auth:** same as GET (Application Password `manage_options` **or** site token).

**Behavior:**

1. Prefer OrderChatz public helpers when loaded:
   - `OrderChatz\Ajax\Message\MessageQueryService::getLatestReplyToken`
   - `LineApiService::sendReplyMessage` / `sendPushMessage` (reply token → reply API, else push; reply failure falls back to push)
   - `MessageStorageService::saveOutboundMessage`
2. If those classes are not available, a **minimal fallback** uses `otz_access_token` to call LINE Messaging API push and writes into the current `{prefix}otz_messages_YYYY_MM` partition (same schema OrderChatz uses). Documented assumption: OrderChatz owns schema/token options; this plugin does not alter core.

**Success:** `201`

```json
{
  "success": true,
  "api_used": "push",
  "line_message_id": "...",
  "quote_token": "...",
  "conversation_id": 123,
  "message": {
    "line_user_id": "...",
    "sender_type": "ACCOUNT",
    "message_type": "text",
    "message_content": "hello",
    "sender_name": "..."
  }
}
```

**Errors:** `400` invalid/oversized message or missing LINE access token (fallback), `401`/`403` auth, `404` unknown conversation, `502` LINE send failure.

## Example curls

Replace `SITE`, credentials, and token.

### Application Password

```bash
# List conversations
curl -sS -u 'admin:APPLICATION_PASSWORD' \
  'https://SITE/wp-json/order-chatz/v1/conversations?limit=20'

# Detail
curl -sS -u 'admin:APPLICATION_PASSWORD' \
  'https://SITE/wp-json/order-chatz/v1/conversations/123'

# Reply
curl -sS -u 'admin:APPLICATION_PASSWORD' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Thanks for your order!"}' \
  'https://SITE/wp-json/order-chatz/v1/conversations/123/messages'
```

### Site token (`X-OTZ-API-Token`)

```bash
curl -sS -H 'X-OTZ-API-Token: YOUR_SITE_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Hello from API"}' \
  'https://SITE/wp-json/order-chatz/v1/conversations/123/messages'
```

### Site token (Bearer)

```bash
curl -sS -H 'Authorization: Bearer YOUR_SITE_TOKEN' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Hello"}' \
  'https://SITE/wp-json/order-chatz/v1/conversations/123/messages'
```

## Unread / last message rules

- **Unread:** inbound (`sender_type` = `user`, case-insensitive), empty `group_id`, `CONCAT(sent_date,' ',sent_time) > read_time`. If `read_time` is empty, cutoff is `NOW() - 3 hours`. Scans last **2** month tables `{prefix}otz_messages_YYYY_MM`.
- **Last message:** latest DM (empty group) for `line_user_id`.
- **last_from_customer:** last message `sender_type` is `user`.

## Development

```bash
composer install
composer test:install   # drops/recreates wordpress_test
composer test
composer phpstan
composer phpcs
```

## Assumptions / blockers

- Reply prefers OrderChatz send stack; fallback needs `otz_access_token` and an existing monthly messages table.
- Media reply is out of scope for 1.1.0 (text only).

## License

GPL-2.0+

## Changelog

### 1.1.0
- POST `/conversations/{id}/messages` outbound text reply via OrderChatz helpers (fallback LINE push + DB write).
- Dev tooling: PHPUnit, PHPStan, PHPCS, coverage gate, build script.

### 1.0.1
- REST namespace: `order-chatz/v1` (was `order-chatz-api/v1`).

### 1.0.0
- Initial read-only conversations API.
