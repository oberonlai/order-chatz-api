# 01 — REST endpoint: reply message

**Risk tier:** 🔴 (auth boundary + untrusted input + write path)

## User story

**As an** API client (site token or admin Application Password)  
**I want to** `POST` a text message to `/conversations/{id}/messages`  
**So that** the LINE friend receives the reply and OrderChatz stores the outbound message

## Acceptance criteria

**Scenario**: Unauthorized request is rejected  
  **Given** no Application Password session and no valid site token  
  **When** `POST /wp-json/order-chatz/v1/conversations/1/messages` with body `{"message":"hi"}`  
  **Then** the response status is `401` or `403`  
  **And** nothing is sent to LINE and no outbound row is written

**Scenario**: Site token may send  
  **Given** a valid `X-OTZ-API-Token` matching `otzapi_site_token`  
  **And** a friend row exists with id `1`  
  **And** LINE send will succeed (mocked)  
  **When** `POST .../conversations/1/messages` with `{"message":"hello"}`  
  **Then** the response status is `201`  
  **And** the body includes `api_used` (`reply` or `push`) and a `message` payload

**Scenario**: Application Password with manage_options may send  
  **Given** a logged-in user with `manage_options`  
  **And** a friend row exists  
  **And** LINE send will succeed (mocked)  
  **When** `POST .../conversations/{id}/messages` with a non-empty message  
  **Then** the response status is `201`

**Scenario**: Missing conversation returns 404  
  **Given** authenticated request  
  **When** posting to a non-existent `{id}`  
  **Then** `WP_Error` code `otzapi_not_found` with status `404`

**Scenario**: Empty message is rejected  
  **Given** authenticated request and existing friend  
  **When** body is `{"message":""}` or missing `message`  
  **Then** status `400` with code `otzapi_invalid_message`

**Scenario**: Oversized message is rejected  
  **Given** authenticated request and existing friend  
  **When** `message` length exceeds `5000` characters  
  **Then** status `400` with code `otzapi_invalid_message`

**Scenario**: LINE failure never claims success  
  **Given** authenticated request and existing friend  
  **And** LINE API returns an error (mocked)  
  **When** posting a valid message  
  **Then** status is `502` (or `400` when token/config missing)  
  **And** response is a `WP_Error` with the LINE error message  
  **And** no success payload is returned

## Tasks

- [ ] Register `POST /conversations/(?P<id>\d+)/messages` under `order-chatz/v1`
- [ ] Reuse `Auth::permission_callback`
- [ ] Validate `message` (required string, trim, non-empty, max 5000)
- [ ] Delegate to `MessageSender`; map results to REST response
- [ ] PHPUnit coverage for each scenario above
