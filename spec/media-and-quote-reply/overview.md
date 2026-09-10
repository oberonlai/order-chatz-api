# Media + Quote Reply Implementation Plan

## Codebase Analysis
- Namespace: `OrderChatzApi` | Layers: Rest / Services / Admin
- Convention: final classes, constructor DI for fakes, `WP_Error` with `status` in error data
- Reusable: `ReplyService` (text + OrderChatz collaborators), `ConversationsController::create_message`, Auth site-token / Application Password
- Conflicts: none — extend existing POST `/conversations/{id}/messages`; do not touch OrderChatz core

## References
- Source: OrderChatz Mac plugin `MessageHandlerRefactored` + `LineApiService` + `MessageStorageService` (`machineId f7c6849e…`)
- Key patterns:
  - Image: `sendReplyImageMessage` / `sendPushImageMessage` + `saveImageMessage` (`image_url`; preview = same URL)
  - File: LINE gets **text** summary with download link via `sendReplyMessage`/`sendPushMessage` + `saveFileMessage` (`file_url` + `file_name`)
  - Video: LINE gets **text** summary with watch link (not native video message) + `saveVideoMessage` (`video_url` + `video_name`)
  - Sticker: `sendReplyStickerMessage` / `sendPushStickerMessage` + `saveStickerMessage` (`package_id` + `sticker_id`)
- Gaps: multipart upload not in v1 (URL-based only); private notes/備註 are **out of scope**
- Auto-loaded skill: `wp-backend`

## Interpretation lock (留言／引用)
**留言回覆 = 引用回覆 (quote reply)** using `quote_token` + `quoted_message_id`.
There is no separate comment/留言 table or LINE post-comment feature in OrderChatz.
Customer「備註」/ private notes are **NOT** this feature and must not be implemented here.

## Operation Flow
1. Client authenticates (Application Password `manage_options` or site token)
2. Client POSTs to `/conversations/{id}/messages` with `type` + type-specific fields (+ optional quote fields)
3. Companion validates input and resolves friend from `otz_users`
4. Prefer OrderChatz LineApi + Storage + Query (reply-token → reply, else push; reply fail → push)
5. Persist via matching `save*Message` / `saveOutboundMessage`
6. Return `201` with `api_used`, type, storage echo — or `400` / `404` / `502`

---

## (1) ReplyService media + quote
- Extend `ReplyService` with `send_image` / `send_video` / `send_file` / `send_sticker`
- Strengthen text path quote coverage under TDD
- Mirror OrderChatz reply-then-push + storage method names

→ Details: [01-reply-service-media.md](./01-reply-service-media.md)

---

## (2) REST endpoint body shapes
- Single route kept: `POST /order-chatz/v1/conversations/{id}/messages`
- `type` discriminator; relax `message` required-when-text-only
- Dispatch to ReplyService; document errors

→ Details: [02-rest-endpoint.md](./02-rest-endpoint.md)

---

## (3) Docs + version bump
- README + readme.txt + plugin header → 1.2.0
- Curl examples for media + quote
- Document URL-only media (no multipart in v1)

→ Details: [03-docs-version.md](./03-docs-version.md)

---
**Plan saved to**: `spec/media-and-quote-reply/`

**Next step**: `/todo spec/media-and-quote-reply/01-reply-service-media.md --tdd=unit`
TDD recommended: business logic + REST validation with OrderChatz fakes (unit).
