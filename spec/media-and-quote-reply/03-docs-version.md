# Docs + Version 1.2.0

**Risk Tier**: 🟢 Green — docs / version metadata

## User Stories

**As a** developer integrating OrderChatz API
**I want** README examples for media + quote
**So that** I know URL-based media and that 留言 = quote reply

**Scenario**: Version bump
  **Given** current version 1.1.0
  **When** this feature ships
  **Then** plugin header, `OTZAPI_VERSION`, `readme.txt`, README show **1.2.0**

**Scenario**: Docs state 留言 interpretation
  **Given** README media/quote section
  **When** a reader looks for 「留言回覆」
  **Then** they see it means quote reply (`quote_token` / `quoted_message_id`), not 備註

**Scenario**: Multipart gap documented
  **Given** README assumptions
  **When** reader looks for file upload
  **Then** docs say v1 accepts **HTTPS URLs only** (no multipart upload)

## Development Tasks

### Interface Layer
- [x] Bump version to 1.2.0 in `order-chatz-api.php`, README, readme.txt
- [x] Document all body shapes + curl examples (image, file, quote text)
- [x] Explicit 留言 = quote; no 備註; URL-only media gap

## Manual Test Script

| Step | Action | Expected Result |
|------|--------|-----------------|
| 1 | Read README changelog 1.2.0 | Media + quote listed |
