# Docs + version bump

**Risk Tier**: 🟢 Green — documentation and version metadata only

## User Stories

**As a** client integrator
**I want** README to document GET message quote fields
**So that** I know which keys to read for quote reply

**Scenario**: README documents GET message fields
  **Given** the plugin ships 1.2.1
  **When** a developer reads GET `/conversations/{id}`
  **Then** docs list `line_message_id`, `quote_token`, `quoted_message_id` on `messages[]` (and note `last_message` consistency)

**Scenario**: Version bumped
  **Given** previous version was 1.2.0
  **When** this change ships
  **Then** plugin header, `OTZAPI_VERSION`, README, and readme.txt Stable tag are `1.2.1`

## Development Tasks

### Interface / Docs
- [x] Update README GET `/conversations/{id}` with message quote field table + example snippet
- [x] Bump version to 1.2.1 in `order-chatz-api.php`, README, `readme.txt` changelog
