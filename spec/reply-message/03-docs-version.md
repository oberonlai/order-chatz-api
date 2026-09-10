# 03 — Docs and version

**Risk tier:** 🟢

## Acceptance criteria

**Scenario**: README documents the new endpoint  
  **Given** the feature is implemented  
  **When** a developer opens `README.md`  
  **Then** they see method, auth, JSON body, response shape, and curl examples for POST messages

**Scenario**: Version bumped to 1.1.0  
  **Given** a write capability is added  
  **When** checking plugin header, `OTZAPI_VERSION`, `readme.txt` Stable tag  
  **Then** version is `1.1.0` and changelog mentions reply endpoint

## Tasks

- [ ] Update README.md (remove "read-only" where outdated; add POST docs)
- [ ] Bump version to 1.1.0 in header, constant, readme.txt, README
- [ ] Note that site token grants send as well as read
