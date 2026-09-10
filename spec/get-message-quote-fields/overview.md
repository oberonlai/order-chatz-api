# GET Message Quote Fields Implementation Plan

## Codebase Analysis
- Namespace: `OrderChatzApi` | Layers: Rest / Services / Admin
- Convention: final classes, `$wpdb` prepared SQL, private `normalize_message()` for API shape
- Reusable: `ConversationRepository::get_messages()` + `normalize_message()` (also feeds list `last_message` via `get_last_message`)
- Conflicts: none — columns `line_message_id`, `quote_token`, `quoted_message_id` already exist on monthly `otz_messages_YYYY_MM`; POST already accepts quote fields

## References
- Source: existing `spec/media-and-quote-reply/` + `ConversationRepository` / README POST docs
- Key patterns:
  - POST reply returns top-level `line_message_id` / `quote_token` from LINE send
  - DB stores per-message `quote_token` (token to quote *this* message), `quoted_message_id`, `line_message_id`
  - GET must read DB only — do not invent tokens or call LINE
- Gaps: GET `messages[]` / list `last_message` omit quote fields today
- Auto-loaded skill: `wp-backend`

## Operation Flow
1. Client authenticates (Application Password or site token)
2. Client GET `/conversations/{id}` (or list where `last_message` is present)
3. Repository SELECTs message rows including quote columns from monthly partitions
4. `normalize_message()` maps `line_message_id`, `quote_token`, `quoted_message_id` (null/empty when DB has none)
5. Client uses a message’s `quote_token` (+ optional id) on POST to quote-reply

---

## (1) Repository SELECT + normalize
- Extend `get_messages()` SELECT with quote/id columns
- Map fields in `normalize_message()`; keep list `last_message` consistent (same normalizer)
- Unit/integration tests assert keys present; never invent tokens

→ Details: [01-repository-quote-fields.md](./01-repository-quote-fields.md)

---

## (2) Docs + version bump
- Document GET message quote fields in README
- Bump patch to 1.2.1 (header, constant, readme.txt)

→ Details: [02-docs-version.md](./02-docs-version.md)

---
**Plan saved to**: `spec/get-message-quote-fields/`

**Next step**: `/todo spec/get-message-quote-fields/01-repository-quote-fields.md --tdd=unit`
TDD recommended: normalizer / get_messages output shape with fixtures (unit + light DB).
