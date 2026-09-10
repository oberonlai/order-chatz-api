# REST: POST /conversations/{id}/messages

## Goal

Expose reply as REST under existing namespace `order-chatz/v1`.

## Acceptance

### Auth (same as GET)
Given valid Application Password with `manage_options` OR valid site token
When POST `/order-chatz/v1/conversations/{id}/messages` with `{"message":"hi"}`
Then 201/200 with message payload

### Unauthenticated
Given no auth
When POST
Then 401/403

### Validation
Given auth but missing `message`
When POST
Then 400

### Not found
Given auth and unknown id
When POST
Then 404

## Tasks
- [x] Register route on ConversationsController
- [x] Wire ReplyService
- [x] Update README.md + readme.txt
- [x] REST/controller tests
