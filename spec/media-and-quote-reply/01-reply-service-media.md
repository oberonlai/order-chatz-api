# ReplyService — Media + Quote

**Risk Tier**: 🔴 Red — outbound LINE messaging + untrusted URL/id input

## User Stories

**As an** API client
**I want to** send image / video / file / sticker replies (and quote any type)
**So that** bots and integrations match OrderChatz admin media + 「回覆」quote behavior

**Scenario**: Image happy path (push)
  **Given** an active friend with `line_user_id` and OrderChatz-style fakes wired
  **And** no reply token is available
  **When** `send_image( $id, 'https://cdn.example.com/a.jpg' )` is called
  **Then** `sendPushImageMessage` is invoked with `originalContentUrl` + `previewImageUrl` set to that URL
  **And** `saveImageMessage` is called
  **And** the result is success with `api_used=push` and `message.message_type=image`

**Scenario**: File happy path (text summary to LINE)
  **Given** an active friend and fakes
  **When** `send_file( $id, 'https://cdn.example.com/doc.pdf', 'doc.pdf' )` is called
  **Then** LINE receives a **text** message containing the file name and download URL (OrderChatz mirror)
  **And** `saveFileMessage` persists `file_url` + `file_name`
  **And** success payload has `message_type=file`

**Scenario**: Quote on text
  **Given** an active friend and a push path
  **When** `send_text( $id, 'hi', { quote_token: 'qt-1', quoted_message_id: 'mid-9' } )` is called
  **Then** LINE push is called with `quote_token=qt-1`
  **And** storage receives `quoted_message_id=mid-9`

**Scenario**: Missing image URL returns 400
  **Given** an active friend
  **When** `send_image` is called with an empty or non-https URL
  **Then** a `WP_Error` `otzapi_invalid_media` (status 400) is returned and nothing is stored

**Scenario**: LINE failure returns 502
  **Given** push image returns `{ success: false }`
  **When** `send_image` runs
  **Then** `WP_Error` `otzapi_send_failed` (status 502) and storage is not called

**Scenario**: Unknown type rejected at service dispatcher (if exposed)
  **Given** a caller asks for type `foo`
  **When** the dispatcher runs
  **Then** `WP_Error` `otzapi_invalid_type` (status 400)

**Scenario**: Video requires URL + name
  **Given** an active friend
  **When** `send_video` is called with `video_url` + `video_name`
  **Then** LINE gets a text summary with watch link (OrderChatz `sendVideoMessage` mirror)
  **And** `saveVideoMessage` is called

**Scenario**: Sticker requires package_id + sticker_id
  **Given** an active friend
  **When** `send_sticker( $id, '1', '2', { quote_token: 'qt' } )` is called
  **Then** `sendPushStickerMessage` (or reply) is used
  **And** `saveStickerMessage` persists package/sticker ids

## Development Tasks

### API Layer
- [x] Extend fakes in unit tests for image/file/video/sticker LineApi + Storage methods (TDD red)
- [x] Implement `ReplyService::send_image` / `send_file` / `send_video` / `send_sticker` (+ optional `send` dispatcher)
- [x] Reuse reply-token → reply → push fallback; map storage to `saveImageMessage` / `saveFileMessage` / `saveVideoMessage` / `saveStickerMessage`
- [x] Validate https URLs for media; require file/video names; sticker ids
- [x] Keep existing text tests green; add quote-on-text assertion

### Integration Layer
- [x] Prefer OrderChatz collaborators when present; fallback may remain text-only or return 501 for media if collaborators missing (document)

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Unit suite with fakes | All media/quote scenarios pass |
| 2 | (Staging) POST image_url to real site | LINE receives image; DB row message_type=image |
