# Reply Service

## Goal

Send a text reply to a DM conversation by calling OrderChatz’s existing
`LineApiService`, `MessageQueryService`, and `MessageStorageService` — do not
modify OrderChatz core.

## Acceptance

### Happy path
Given an active friend (`otz_users.id`) with a `line_user_id`
And OrderChatz send classes are available
When `ReplyService::send_text( $conversation_id, $message )` is called
Then it resolves `line_user_id` from `otz_users`
And prefers reply API when a reply token exists, else push API
And persists via `MessageStorageService::saveOutboundMessage`
And returns success payload with `api_used`, `line_message_id`, normalized message

### Missing conversation
Given unknown `conversation_id`
When send is called
Then it returns a WP_Error `otzapi_not_found` (404)

### Empty message
Given empty/whitespace message
When send is called
Then it returns WP_Error `otzapi_invalid_message` (400)

### OrderChatz send unavailable
Given OrderChatz classes missing
When send is called
Then it returns WP_Error `otzapi_send_unavailable` (501)

### LINE send failure
Given LINE API returns failure
When send is called
Then it returns WP_Error `otzapi_send_failed` (502) without claiming success

## Tasks
- [x] Implement `src/Services/ReplyService.php`
- [x] Unit tests with injectable fakes (no live LINE)
