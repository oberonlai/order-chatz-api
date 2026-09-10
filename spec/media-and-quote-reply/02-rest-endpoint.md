# REST — Unified messages POST

**Risk Tier**: 🔴 Red — REST accepting untrusted input + auth boundary unchanged

## User Stories

**As an** API client
**I want** one POST route with a `type` field
**So that** text and media (plus quote) share auth and conversation id

**Scenario**: Text with quote fields
  **Given** auth succeeds for conversation `123`
  **When** POST JSON `{ "type":"text", "message":"Thanks", "quote_token":"qt", "quoted_message_id":"m1" }`
  **Then** response is `201` and ReplyService received the quote args

**Scenario**: Image body
  **Given** auth succeeds
  **When** POST `{ "type":"image", "image_url":"https://cdn.example.com/a.jpg" }`
  **Then** `201` with `message.message_type=image`

**Scenario**: Missing required media field
  **Given** auth succeeds
  **When** POST `{ "type":"image" }` without `image_url`
  **Then** `400`

**Scenario**: Unknown type
  **Given** auth succeeds
  **When** POST `{ "type":"foo" }`
  **Then** `400` `otzapi_invalid_type`

**Scenario**: Auth unchanged
  **Given** no Application Password and no site token
  **When** POST messages
  **Then** `401`/`403` (existing Auth)

## Body shapes

| type | Required fields | Optional shared |
|------|-----------------|-----------------|
| `text` (default) | `message` or `text` | `quote_token`, `quoted_message_id` |
| `image` | `image_url` (https) | quote fields |
| `video` | `video_url` (https), `video_name` | quote fields |
| `file` | `file_url` (https), `file_name` | quote fields |
| `sticker` | `package_id`, `sticker_id` | `quote_token`, `quoted_message_id` |

Auth: `manage_options` Application Password **OR** `X-OTZ-API-Token` / `Authorization: Bearer`.

Errors: `400` validation, `404` missing conversation, `502` LINE failure.

## Development Tasks

### API Layer
- [x] Update `register_rest_route` args: `type`; make `message` not globally required
- [x] `create_message` reads body, dispatches by `type` to ReplyService
- [x] Unit/controller-level coverage via ReplyService tests + thin controller wiring

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | curl text + quote_token | 201 |
| 2 | curl type=image without URL | 400 |
