# Approved signature vectors

This directory is intentionally empty in the repository.

Positive signature verification cannot be proven by this client on its own: V-T.ONE holds the
private signing key, and fabricating a vector would prove nothing. The positive tests in
`tests/Transfer/SignedPacketTest.php` therefore **skip** — they never silently pass — until an
approved captured exchange is placed here.

| File | What to capture |
|---|---|
| `activation-response.json` | One complete, unmodified successful activation response for this product's project slug, exactly as received. |
| `updater-request.json` | One complete, unmodified inbound server-initiated licence update: its request headers together with the exact raw request body bytes. |

The required field and header names are defined in the internal V-T.ONE integration
specification; capture the exchange verbatim rather than assembling it by hand from this file.

Rules:

- Both files must be byte-identical to what was signed. Do not re-encode, reformat or pretty-print
  them — a single changed byte is supposed to fail verification, which is what
  `testApprovedActivationResponseFailsAfterASingleByteMutation` asserts.
- These files contain real licence material. They are excluded from version control by
  `.gitignore`, excluded from the distributed archive by `composer.json` → `archive.exclude`, and
  refused by `tools/release-guard.php`.
- Never print their contents from a test, a log, or a CI job.
- The alternative to a captured exchange is an explicitly authorised live integration test.
  Automated runs must never contact live V-T.ONE services.
