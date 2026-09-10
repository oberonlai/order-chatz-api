# Feature: reply-message

Send (reply/push) a text message to an OrderChatz DM conversation via REST.

## Goal

Expose `POST /wp-json/order-chatz/v1/conversations/{id}/messages` so external tools can reply to a LINE friend the same way OrderChatz admin AJAX `otz_send_message` does — prefer reply token, fall back to push, persist outbound row only after LINE success.

## Scope

- Text message body only (`message` JSON field).
- Auth: existing Application Password (`manage_options`) **or** site token (`X-OTZ-API-Token` / Bearer). Token grants send.
- Companion plugin only — do not modify OrderChatz core.
- Prefer OrderChatz `LineApiService` / `MessageQueryService` / `MessageStorageService` when classes exist; otherwise thin fallback sender.

## Out of scope

- Images, files, stickers, video, quote_token (optional later).
- Live LINE integration tests (no channel credentials in CI).
- Health / MCP / webhook / digest.

## Build order

| # | Area | Risk | File |
|---|------|------|------|
| 01 | REST route + validation + auth | 🔴 | `01-rest-endpoint.md` |
| 02 | MessageSender (LINE reply/push + outbound store) | 🔴 | `02-message-sender.md` |
| 03 | Docs / version bump | 🟢 | `03-docs-version.md` |

## Dependencies

- Active OrderChatz (`OTZ_VERSION`) in production.
- Option `otz_access_token` for LINE Channel Access Token.
- Tables `{prefix}otz_users` and `{prefix}otz_messages_YYYY_MM`.
