# OrderChatz API

Companion plugin: REST API for OrderChatz DM conversations (list, detail, **reply** including media + quote). **Does not modify OrderChatz core.**

- **Version:** 1.2.1
- **Text Domain:** `otzapi`
- **Requires:** WordPress 6.5+, PHP 8.0+, active OrderChatz (`OTZ_VERSION`)

**Author:** [WPBrewer](https://wpbrewer.com/)

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

Each item in `messages[]` (and list/detail `last_message`, same normalizer) is read from DB only — no live LINE call. Quote-related fields:

| Field | Type | Description |
| --- | --- | --- |
| `line_message_id` | string\|null | LINE message ID for this row |
| `quote_token` | string\|null | Token to pass on POST when quoting **this** message |
| `quoted_message_id` | string\|null | If this message was itself a quote reply, parent LINE message id |

Missing DB values are `null` (never invented).

Example message object:

```json
{
  "line_user_id": "Uxxxx",
  "sender_type": "user",
  "group_id": null,
  "sent_date": "2026-09-10",
  "sent_time": "08:00:00",
  "sent_at": "2026-09-10 08:00:00",
  "message_type": "text",
  "message_content": "Hello",
  "sender_name": "Customer",
  "line_message_id": "mid-1",
  "quote_token": "qt-1",
  "quoted_message_id": null
}
```

### POST `/conversations/{id}/messages`

Send an outbound reply (text or media). **留言／引用回覆** = quote reply via `quote_token` + `quoted_message_id` (there is no separate LINE comment table; customer「備註」is private notes and is **not** this API).

**Shared body fields:**

| Field | Required | Description |
| --- | --- | --- |
| `type` | no | `text` (default) \| `image` \| `video` \| `file` \| `sticker` |
| `quote_token` | no | LINE quote token (引用) |
| `quoted_message_id` | no | Quoted LINE message id (引用) |

**Per-type fields:**

| type | Required fields |
| --- | --- |
| `text` | `message` or `text` (max 5000) |
| `image` | `image_url` (HTTPS) |
| `video` | `video_url` (HTTPS), `video_name` — LINE gets a **text** summary with watch link (mirrors OrderChatz AJAX) |
| `file` | `file_url` (HTTPS), `file_name` — LINE gets a **text** summary with download link |
| `sticker` | `package_id`, `sticker_id` |

**Auth:** same as GET (Application Password `manage_options` **or** site token).

**Behavior:**

1. Prefer OrderChatz public helpers when loaded:
   - `MessageQueryService::getLatestReplyToken` → reply API, else push; reply failure falls back to push
   - Image: `sendReplyImageMessage` / `sendPushImageMessage` + `saveImageMessage`
   - File/video: text summary via `sendReplyMessage` / `sendPushMessage` + `saveFileMessage` / `saveVideoMessage`
   - Sticker: `sendReplyStickerMessage` / `sendPushStickerMessage` + `saveStickerMessage`
   - Text: `saveOutboundMessage`
2. Text-only **fallback** (no OrderChatz classes): `otz_access_token` LINE push + monthly `otz_messages_YYYY_MM` write.
3. Media requires OrderChatz collaborators (`501` if unavailable). **v1 accepts HTTPS URLs only** — no multipart upload.

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
    "message_type": "image",
    "message_content": "https://cdn.example.com/a.jpg",
    "sender_name": "..."
  }
}
```

**Errors:** `400` validation / unknown type, `401`/`403` auth, `404` unknown conversation, `501` media without OrderChatz, `502` LINE send failure.

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

# Text reply
curl -sS -u 'admin:APPLICATION_PASSWORD' \
  -H 'Content-Type: application/json' \
  -d '{"message":"Thanks for your order!"}' \
  'https://SITE/wp-json/order-chatz/v1/conversations/123/messages'

# Quote (留言／引用) reply
curl -sS -u 'admin:APPLICATION_PASSWORD' \
  -H 'Content-Type: application/json' \
  -d '{"type":"text","message":"Got it","quote_token":"QUOTE_TOKEN","quoted_message_id":"LINE_MID"}' \
  'https://SITE/wp-json/order-chatz/v1/conversations/123/messages'

# Image
curl -sS -u 'admin:APPLICATION_PASSWORD' \
  -H 'Content-Type: application/json' \
  -d '{"type":"image","image_url":"https://cdn.example.com/a.jpg"}' \
  'https://SITE/wp-json/order-chatz/v1/conversations/123/messages'

# File
curl -sS -u 'admin:APPLICATION_PASSWORD' \
  -H 'Content-Type: application/json' \
  -d '{"type":"file","file_url":"https://cdn.example.com/doc.pdf","file_name":"doc.pdf"}' \
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

- Reply prefers OrderChatz send stack; text fallback needs `otz_access_token` and an existing monthly messages table.
- Media is **URL-based only** in 1.2.0 (no multipart upload endpoint).
- 留言回覆 = quote reply only (not 備註).

## License

GPL-2.0+

## Changelog

### 1.2.1

- GET `messages[]` / `last_message` expose `line_message_id`, `quote_token`, `quoted_message_id` from DB.

### 1.2.0
- Media reply types: `image`, `video`, `file`, `sticker` on POST `/conversations/{id}/messages`.
- Quote / 留言回覆 via `quote_token` + `quoted_message_id` on text and media.
- Document URL-only media; OrderChatz required for media sends.

### 1.1.0
- POST `/conversations/{id}/messages` outbound text reply via OrderChatz helpers (fallback LINE push + DB write).
- Dev tooling: PHPUnit, PHPStan, PHPCS, coverage gate, build script.

### 1.0.1
- REST namespace: `order-chatz/v1` (was `order-chatz-api/v1`).

### 1.0.0
- Initial read-only conversations API.
