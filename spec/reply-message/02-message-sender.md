# 02 — MessageSender service

**Risk tier:** 🔴 (external sink + DB write)

## User story

**As the** REST layer  
**I want** a service that mirrors OrderChatz `otz_send_message`  
**So that** reply-token → push fallback and outbound persistence stay consistent with admin chat

## Acceptance criteria

**Scenario**: Prefer reply API when a fresh reply token exists  
  **Given** OrderChatz `MessageQueryService` / `LineApiService` are available  
  **And** `getLatestReplyToken` returns a token  
  **And** `sendReplyMessage` succeeds  
  **When** `send_text` is called  
  **Then** `api_used` is `reply`  
  **And** the reply token is marked used  
  **And** outbound message is saved

**Scenario**: Fall back to push when reply fails or no token  
  **Given** no reply token **or** reply API fails  
  **When** `send_text` is called  
  **Then** `sendPushMessage` is used  
  **And** on success `api_used` is `push` and outbound is saved

**Scenario**: Fallback path without OrderChatz classes still pushes  
  **Given** OrderChatz Ajax Message classes are not loadable  
  **And** option `otz_access_token` is set  
  **When** `send_text` is called  
  **Then** a LINE Messaging API push request is made  
  **And** on HTTP 200 an outbound row is inserted into the current month partition  
  **And** on failure a `WP_Error` is returned (no outbound row)

**Scenario**: Missing access token fails cleanly  
  **Given** `otz_access_token` is empty  
  **When** `send_text` is called on the fallback path  
  **Then** a `WP_Error` with status `400` explains the missing token

## Tasks

- [ ] Add `OrderChatzApi\Services\MessageSender`
- [ ] Prefer OrderChatz classes when `class_exists`
- [ ] Implement LINE HTTP + outbound insert fallback
- [ ] Never save outbound before LINE success
- [ ] Unit/integration tests with `pre_http_request` mocks
