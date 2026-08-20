# Approved signature vectors

This directory is intentionally empty in the repository.

Positive signature verification cannot be proven by this client on its own: V-T.ONE holds the
private signing key, and fabricating a vector would prove nothing. The positive tests in
`tests/Transfer/SignedPacketTest.php` therefore **skip** (they never silently pass) until an
approved captured packet is placed here.

| File | What to capture |
|---|---|
| `activation-response.json` | The complete decoded response body of a real `POST https://www.v-t.one/api/v1/verify` with `action=activate` for **project_slug `migrator`**: `status`, `request_id`, `server_time`, `license_payload_b64`, `integrity{project, project_slug, license_version, license_md5, generated_at, key_id, signature_algorithm, signature}`. |
| `updater-request.json` | A real inbound push: `{"headers":{"X-VT-Request-ID":…,"X-VT-Timestamp":…,"X-VT-Nonce":…,"X-VT-Key-ID":…,"X-VT-Signature":…},"body":"<the exact raw request body bytes>"}`. The body must be byte-identical to what was signed — do not re-encode or pretty-print it. |

Rules:

- These files contain real licence material. They stay in `tests/`, which is excluded from the
  distributed archive (`composer.json` → `archive.exclude`) and refused by `tools/release-guard.php`.
- Never print their contents from a test, a log or a CI job.
- Do not edit them. A single changed byte is supposed to fail verification — that is what
  `testApprovedActivationResponseFailsAfterASingleByteMutation` asserts.
- The alternative to a captured packet is an explicitly authorised live integration test against
  V-T.ONE; automated runs must never contact the live endpoints.
