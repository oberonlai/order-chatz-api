# Repository — GET message quote fields

**Risk Tier**: 🔴 Red — touches `$wpdb` SELECT on message partitions (read path)

## User Stories

**As an** API client (e.g. LINE 官方帳號 agent)
**I want** each message in GET conversation detail to include `line_message_id` and `quote_token`
**So that** I can POST a quote reply targeting a specific stored message without inventing tokens

**Scenario**: Normalized message exposes stored quote fields
  **Given** a DB message row with `line_message_id=mid-1`, `quote_token=qt-1`, `quoted_message_id=mid-parent`
  **When** `normalize_message()` / `get_messages()` builds the API payload
  **Then** the message object includes `line_message_id` = `"mid-1"`, `quote_token` = `"qt-1"`, `quoted_message_id` = `"mid-parent"`

**Scenario**: Missing DB values are null or empty (never invented)
  **Given** a DB message row where `line_message_id`, `quote_token`, and `quoted_message_id` are NULL or empty
  **When** the message is normalized
  **Then** those keys are present with `null` or `""`
  **And** no fake token is generated

**Scenario**: List last_message stays consistent
  **Given** `get_last_message()` uses `get_messages(..., 1)` and the same normalizer
  **When** list/detail returns `last_message`
  **Then** `last_message` includes the same three keys when data exists

**Scenario**: GET remains DB-only
  **Given** a conversation detail request
  **When** messages are loaded
  **Then** no live LINE API call is required to populate quote fields

## Field contract

| Key | Type | Meaning |
| --- | --- | --- |
| `line_message_id` | string\|null | LINE message ID stored for this row |
| `quote_token` | string\|null | Token to use when quoting *this* message on POST |
| `quoted_message_id` | string\|null | If this message was itself a quote reply, the parent LINE id |

Do **not** invent tokens when DB has none. Private notes / 備註 remain out of scope.

## Development Tasks

### Data / Service Layer
- [x] Failing tests first: assert `normalize_message` / `get_messages` output includes the three keys (with and without DB values)
- [x] Extend `get_messages()` SELECT to include `line_message_id`, `quote_token`, `quoted_message_id`
- [x] Map those columns in `normalize_message()` as string|null (null/empty when missing)

## Manual Test Script

| Step | Action | Expected Result |
| --- | --- | --- |
| 1 | Insert fixture row with quote columns; GET conversation | `messages[]` entry has matching fields |
| 2 | Insert row with NULLs; GET | keys present, null/empty, no invented token |
