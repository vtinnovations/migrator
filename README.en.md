<p align="right"><a href="README.md">🇩🇪 Deutsche Version</a></p>

# Contao Migrator

Contao bundle that moves a Contao&nbsp;5 site to a new host — as a portable `.tcmig` package (manual export/import) or via direct server-to-server transfer, including serialize-safe URL rewriting.

*This is the English alternate-language version. [README.md](README.md) is the canonical German version.*

## 1. Project Overview

Contao Migrator is a Contao&nbsp;5 backend bundle that packages a complete installation (database, file tree, selected secrets) into a single signed `.tcmig` package and restores it on a destination installation. Two transfer paths are available:

- **Mode A — manual:** export as a download, import via upload (splittable into parts to work around upload limits).
- **Mode B — server-to-server:** the source installation builds the package and transfers it directly and signed to a paired destination installation, which starts the import automatically.

A dedicated replacement algorithm rewrites the old hostname everywhere in the database — including inside PHP-serialized values, without corrupting them.

## 2. Current Implementation and Production Status

The bundle is fully implemented — no placeholder and no planned functionality. Every documented feature is traced through to its actual execution path (route/form → controller or backend module → service → job pipeline → persistence). The project ships its own audit test suite (`tests/Audit/`) that continuously verifies, among other things, the wiring of the licence surface, the separation of sensitive responsibilities, and translation completeness.

## 3. Supported Framework and Runtime Versions

| Component | Version |
|---|---|
| PHP | `^8.2` |
| Contao | `contao/core-bundle` `^5.3` |
| Symfony components | `symfony/config`, `symfony/dependency-injection`, `symfony/http-foundation`, `symfony/http-kernel` — each `^6.4 \|\| ^7.0` |
| Doctrine DBAL | `^3.6 \|\| ^4.0` |
| Contao Manager | via `contao/manager-plugin` `^2.0` (automatic bundle detection) |

Database: the dump/restore logic targets **MySQL/MariaDB** (uses `SHOW CREATE TABLE`, `UNHEX()` literals, among others). A portable schema-reconstruction path also exists, but in practice is only exercised by the automated test suite (SQLite).

## 4. System Requirements

- PHP `^8.2` with the `ext-json` and `ext-zlib` extensions (required).
- `ext-sodium` **recommended**: secrets (APP_SECRET, encryption keys) are encrypted preferentially with Argon2id + XChaCha20-Poly1305; without `sodium` an OpenSSL fallback (PBKDF2-SHA256 + AES-256-GCM) is used automatically.
- `ext-curl` optional: the server-to-server transfer uses curl when available, otherwise a PHP stream fallback.
- Sufficient free disk space in the project directory (the preflight check estimates the requirement with a safety factor and aborts beforehand if insufficient).
- For unattended progress: a Contao cron job running regularly (see Section 7).

## 5. Installation

```bash
composer require vtinnovations/migrator
```

As a regular `contao-bundle` package, the bundle is automatically detected and registered by Contao Manager (`Vtinnovations\Migrator\ContaoManager\Plugin`, declared in `composer.json` → `extra.contao-manager-plugin`). No manual bundle registration is required.

## 6. Composer, Package Manager and Framework Setup

Optional bundle configuration under the `vtinnovations_migrator` key in the project's Symfony configuration, e.g. `config/packages/vtinnovations_migrator.yaml`:

```yaml
vtinnovations_migrator:
    scratch_dir: '%kernel.project_dir%/var/migrator'   # default value
    time_budget: 20.0                                   # seconds per job tick, default value
```

| Option | Default | Meaning |
|---|---|---|
| `scratch_dir` | `%kernel.project_dir%/var/migrator` | Working directory for jobs, backups and staging. |
| `time_budget` | `20.0` | Maximum runtime (seconds) of a single job tick before state is persisted and execution yields to the next tick. |

Additional operational settings (file-export exclude list, chunk sizes, backup retention count, default split-download volume size, `MAILER_DSN` handling) are managed in `var/migrator/config.json`, with sensible defaults when the file is absent.

## 7. Required Executables and Configuration

- **Contao cron:** the bundle registers a cron job (`vtinnovations_migrator.tick_active_jobs`, interval `minutely`) that advances every running job once per minute, even without an open backend window. Contao's own web-cron, however, only fires on front-end traffic — for reliable progress, configure a real system cron:
  ```bash
  * * * * * php vendor/bin/contao-console contao:cron
  ```
- No external executable (no `mysqldump`, no `tar`, no `exec()`) is required — database dump/restore and archiving are pure PHP implementations.
- After an import, run `composer install --no-dev` on the destination, **unless** the export package was built with the "Fix it for the migration package" option (in which case the package already ships `vendor/`).

## 8. Filesystem Permissions

- Write access to `var/migrator/` (or the configured `scratch_dir`) for the web-server process.
- Write access to the web root directory (`public/` or `web/`), so the standalone recovery panel can be mirrored there on every kernel boot.
- Sensitive files (operator token, transient passphrase sidecar, licence state) are created with `chmod 0600`.

## 9. Backend and Administration Access

The bundle provides **no frontend module** — the entire feature set is reachable exclusively through the Contao backend, with the exception of the standalone, token-protected recovery panel (Section 17).

- **Backend module:** Contao backend → "migrator" group → "Contao Migrator".
- **Licence management:** Contao backend → Settings → "V-T.ONE Licence management" section → "Migrator" field.

Access is governed by Contao's regular backend user/group permissions for backend modules and for the Settings screen; the bundle defines no permission layer of its own.

## 10. Frontend Integration

Not applicable — there is no frontend module, no content element, and no publicly visible part of the regular page build. The two publicly reachable HTTP endpoints (Mode B receive and the licence-update endpoint) are pure server-to-server interfaces with no user interface.

## 11. Navigation Modules and Tabs (Actual Order)

Within the "Contao Migrator" backend module, in exactly this order:

1. **Export**
2. **Import**
3. **Server-to-server**
4. **Recovery panel** (link to the standalone panel)

Above the tabs, a live monitor appears whenever a job is active (progress bar, log, confirmation forms for paused steps where applicable); below the tabs follows the list of the last 12 jobs with state, progress and actions (cancel/delete).

## 12. Verified Features of Every Essential Module

### Export (Mode A)

- Builds a `.tcmig` package (database dump, file tree, optionally encrypted secrets) and signs the manifest.
- Optional export passphrase: encrypts `APP_SECRET`, the Contao encryption key and the database encryption key for carry-over to the destination; without a passphrase these values must be re-entered by hand on the destination.
- A pre-send audit of `composer.json` (local path/VCS repositories, disabled Packagist, `dev` version constraints, unstable `minimum-stability`, unpinned path packages) pauses the job exactly once; the operator may choose "Proceed anyway" or "Fix it for the migration package" (ships `vendor/` plus a portable, pinned `composer.json`).
- Download as a single file, or split into configurably sized parts (default 80&nbsp;MiB), including a "download all sequentially" helper.
- Retention: the most recently produced local backups are kept (default: 3), older ones removed automatically.

### Import (Mode A)

- Single-file upload, or split upload (`.001`, `.002`, … uploaded sequentially and assembled server-side) — works around the host's upload size limit.
- The manifest signature and every individual data-chunk checksum are verified **before** any destructive step; passphrase-signed packages prompt interactively for the passphrase.
- Compatibility check: warns on a PHP downgrade or a differing Contao major version between source and destination, and requires a deliberate confirmation.
- A safety snapshot of the existing `.env`/`.env.local` files is taken before any destructive step.
- Host rewrite: once the operator confirms the host mapping, every database column the preflight scan flagged is rewritten — **serialize-safe**, so PHP-serialized values remain valid.
- Configuration reconciliation: destination-specific values (`DATABASE_URL`, `TRUSTED_PROXIES`, `TRUSTED_HOSTS`, optionally `MAILER_DSN`) are preserved, carried source secrets are injected; if a destination-owned value is unavailable, the source's leftover line in `.env.local` is safely commented out rather than applied, and the operator is prompted to supply it manually.
- Post-processing: bundle web assets are re-published (`assets:install --symlink --relative`), the Contao upload directory is made reachable under the web root via symlink (or copy as a fallback) when needed, `var/cache` is purged. A shipped `vendor/` directory makes `composer install` unnecessary; otherwise it is logged as the next required step. `contao:migrate` is deliberately **not** run automatically (a pure host move, not a version migration).

### Server-to-Server Transfer (Mode B)

- The **destination** mints a single-use, time-boxed pairing token ("Receive" card) and hands it to the operator.
- The **source** enters the destination URL and the token, optionally sets a passphrase, and starts "Build & push" — builds the package and transfers it in signed, adaptively shrinkable chunks directly to the destination endpoint; the destination starts the import automatically once the transfer completes and checksums verify.
- The pairing is single-use and expires automatically.

### Recovery Panel

See Section 17 (Operational Security).

### Licence Management

See Section 14.

### Job Control

- Every job runs in time-budgeted "ticks" (default 20&nbsp;s); progress is persisted after every tick, so an interrupted request loses at most the in-flight slice.
- While the backend window stays open, JavaScript drives the job continuously; the minutely cron job covers unattended progress.
- Cancellation is available for running, pending and paused jobs; deletion only once a job has completed, failed or been cancelled (removes the job record, package, backup and staging directory).

## 13. Permissions and Access Control

| Boundary | Protection |
|---|---|
| Backend module & AJAX routes (`/contao/migrator/...`) | Contao backend firewall (`_scope: backend`) + CSRF token (`REQUEST_TOKEN`) |
| Licence management (Contao → Settings) | regular Contao Settings-page permission; own form field with no extra route |
| Mode B receive (`/migrator/ingest/...`) | publicly reachable, but authenticated solely by a signed, single-use pairing — no backend login possible or needed |
| Licence-update endpoint (server-to-server) | publicly reachable, authenticated solely by a cryptographically signed request; initiated by V-T.ONE, no backend login involved |
| Recovery panel (`/_tcmig-recovery.php`) | operator token; reachable independently of the Contao backend |

Every protected operation re-verifies the valid entitlement state **at its own boundary** — not only once at the outer entry point — and checks exactly the tier that operation requires. Job processing re-checks before it carries a job forward: a Pro-tier job stops as soon as the entitlement lapses, whichever entry point started it. Hiding a control is never treated as access protection anywhere in the product.

## 14. Licensing and Entitlement Behaviour

Contao Migrator uses a **Trial/Free-and-Pro licensing model**: every use — including the free tier — requires an activated, signed licence; there is no anonymous mode without activation.

**Activation, refresh and removal** happen exclusively via Contao → Settings → "V-T.ONE Licence management" → "Migrator":

- **Verify & activate licence** — enter the key and activate it against the installation's configured hostname.
- **Update licence** — uses the stored key and the currently installed licence version, with no re-entry needed.
- **Remove licence** — deletes the local licence state; the installation immediately falls back to "not licensed".

**Actual licence states** (as shown in the backend):

| State | Meaning |
|---|---|
| Not licensed | No valid licence stored — the backend module shows only a link to the Settings page; no feature is usable. |
| Trial licence active | Signed trial licence, currently valid. |
| Free licence active | Signed Free licence, currently valid. |
| Pro licence active | Signed Pro licence, currently valid. |
| Pro licence expired — Free feature set active | An expired Pro licence retains the Free feature set **only** when the signed licence explicitly permits it. |
| Licence expired | Trial/Free with no fallback, or Pro without a permitted fallback — access locked. |
| Licence cannot be verified on this installation | Tampered, corrupted, or bound to a different domain. |

Also shown: the masked key, package, "Valid from", "Valid until" (or "unlimited"), and "Last verified".

**Binding:** a licence is bound to an exact hostname configured on the Contao root pages (no `www.` matching, no subdomain equivalence). Without a matching configured domain, activation cannot succeed — the hint on the Settings page lists the configured hosts.

**Checks:** ordinary entitlement checks run entirely locally and without network access. Activation and refresh contact a single, fixed-in-code HTTPS service. In addition, V-T.ONE may deliver a licence update to the installation server-side (signed, without a backend login, authenticated solely by a request signature).

**Storage:** the licence state is kept outside the public web root, under `var/migrator/`.

### Feature tiers

The feature set is divided into two tiers. Both are enforced server-side.

| Feature area | Free | Pro |
|---|:--:|:--:|
| Export, import and recovery | Included | Included |
| Direct server-to-server transfer | Not included | Included |

- **Export, import and recovery form the Free feature set.** Any valid licence opens the backend module, both manual transfer directions, and every action of the recovery panel.
- **Direct server-to-server transfer is the paid feature.** An active trial licence includes it, because a trial period exists precisely to evaluate that feature; it lapses when the trial does. It is not part of the Free fallback of an expired Pro licence.
- **Both installations need the entitlement.** A direct transfer requires it on the sending *and* the receiving installation. One licence can cover both hosts when both hostnames belong to its licensed domain scope. An installation on the Free feature set cannot act as the destination for another installation's direct transfer.
- **Existing data stays reachable.** Progress display, downloading a package that already exists, and cancelling or deleting a job all remain in the Free feature set. A lapsed Pro licence therefore never leaves a job the operator can no longer clean up.
- **The feature set can be extended without changing package.** V-T.ONE can enable direct transfer for an existing licence without altering its package. Such an extension only ever adds; it can never remove anything the package itself already grants.

The licence section in Settings shows which feature areas the installed licence includes, so "why is server-to-server transfer locked?" is answered directly on the page.

## 15. Feature Status Table

| Feature | Status |
|---|---|
| Export as a `.tcmig` package | Available |
| Import via upload (single file or split) | Available |
| Server-to-server transfer (Mode B) | Pro only |
| Serialize-safe host rewriting | Available |
| Secret carry-over (APP_SECRET etc., passphrase-encrypted) | Available |
| Pre-send `composer.json` audit | Available |
| Compatibility check (PHP/Contao version) | Available |
| Unattended progress via cron | Available |
| Standalone recovery panel | Free and Pro |
| Licence management (activate/update/remove) | Available |
| Minting a Mode B pairing token | Pro only |
| Feature differentiation by licence package (Free vs. Pro) | Available |
| Frontend module / content element | Not applicable |

*Every feature in this table requires an activated, valid licence — including those marked "Free and Pro" (see Section 14). "Pro only" marks features that additionally require the Pro feature set.*

## 16. Security Model

- **Access control:** backend actions require an authenticated Contao backend session behind the backend firewall, plus a valid CSRF token.
- **Server-side entitlement enforcement:** every protected operation (backend actions, job ticks, the receive endpoint, the recovery panel) independently re-checks the authenticated licence state, not only once at the outer entry point.
- **Authenticated requests:** outbound licence activation/refresh, and the inbound server-initiated licence-update endpoint, are secured via signed requests to a single, fixed-in-code HTTPS service; redirects are refused and TLS verification stays on.
- **Integrity and authenticity:** every `.tcmig` package's manifest is signed, and every database and file chunk carries a checksum; the import verifies both completely before any destructive step begins.
- **Private storage:** operational data (jobs, backups, licence state, operator token) is kept outside the public web root.
- **Safe failure behaviour:** a missing or invalid licence, a failed checksum, or an invalid signature blocks the operation instead of proceeding silently; an already-running job is not deleted in this case but resumes automatically once the licence becomes valid again.
- **Secret handling:** the export passphrase lives only in a transient, permission-restricted (0600) sidecar file — never inside the job record itself — and is deleted once no longer needed; carried application secrets travel encrypted inside the package.
- **Redacted logging:** the project's own automated test suite enforces that licence keys and confidential licence-communication content never reach logs, debug output, or the browser.

## 17. Operational Security

The standalone recovery panel (`_tcmig-recovery.php`) is automatically and idempotently mirrored into the web root on every kernel boot and works **independently of the Contao backend** — for example when a cross-version restore has not yet created a table the backend itself needs. It authenticates via the operator token and offers: status display, driving the active job, running `contao:migrate` (including re-publishing assets), minting a pairing token, supplying a passphrase, confirming composer warnings, and cancelling the job. Every mutating action checks the same authoritative entitlement state as the backend module, and checks exactly the tier it requires: the recovery actions belong to the Free feature set, while minting a pairing token requires the Pro feature set. Status stays readable even without a valid licence so a fault remains diagnosable; the driving actions stay disabled.

Because the token can be passed as a URL parameter and may therefore land in access logs, the panel is explicitly intended as a break-glass tool — rotate the token afterwards if that matters.

## 18. Runtime Directories

| Path | Contents |
|---|---|
| `var/migrator/` (configurable via `scratch_dir`) | jobs, backups, staging area, snapshots, incoming Mode B transfers, bundle configuration, and the private entitlement and operational state |
| `public/_tcmig-recovery.php` (or `web/...`) | automatically mirrored, standalone recovery panel |

## 19. External Communication

All outbound connections run exclusively over HTTPS to **a single, fixed-in-code destination service** (`https://www.v-t.one`):

| Purpose | Trigger |
|---|---|
| Licence activation / refresh | operator clicks "Activate" or "Update" on the Settings page |
| Invocation signal — transmits the project name and hostname, at most once per request | opening the backend module |
| Session signal — transmits the hostname and licence key to attribute the licence, at most once per authenticated backend session | opening the backend module with a valid licence |

In addition, V-T.ONE may send a signed licence update **to** the installation server-side (inbound, server-to-server).

Both signals are sent server-side only; the licence key never appears in the browser view, in logs, or in client-side code.

During a server-to-server transfer (Mode B), the source installation communicates exclusively with the operator's own destination installation — no third-party service is involved.

Delivery of the invocation signals is best-effort: a failure is silently discarded and never affects licensing or rendering.

## 20. Logging and Redaction of Sensitive Data

Licence-communication handling is deliberately built without logging: there is no place through which confidential licence data could reach a log. Job logs (visible in the backend monitor) contain only progress and error messages about the migration itself, never licence keys or confidential licence-communication content. The automated test suite verifies this guarantee on every run.

## 21. Deployment

Standard Contao 5 deployment via Composer/Contao Manager:

```bash
composer require vtinnovations/migrator
vendor/bin/contao-console cache:clear
```

After completing an import (see Section 12), also check whether `composer install --no-dev` is required (not needed if the export was built with "Fix it for the migration package").

## 22. Cache Clearing

The import pipeline purges `var/cache` automatically as the final post-processing step, so the next request rebuilds the dependency-injection container. For a manual cache-clear run outside a migration, the regular Contao command applies:

```bash
vendor/bin/contao-console cache:clear
```

## 23. Testing

The project ships a PHPUnit test suite (`phpunit.xml.dist`), invokable via the script alias defined in `composer.json`:

```bash
composer test
```

Additional development tooling (not part of the distributed package, see `composer.json` → `archive.exclude`):

```bash
composer release-guard                                  # build guard to run before a release
php tools/verify-licence-surface.php /path/to/project    # runtime acceptance test of the licence surface inside a real Contao kernel
```

## 24. Troubleshooting

- **Backend unreachable after a cross-version restore:** use the standalone recovery panel at `/_tcmig-recovery.php?key=<operator-token>` (the token is also shown on the backend module's "Recovery panel" tab).
- **Import paused, asking for a passphrase:** the package was signed with a passphrase at export time — enter the same passphrase in the confirmation form.
- **Import paused with a compatibility warning:** a PHP downgrade or a differing Contao major version between source and destination — confirm deliberately with "Proceed anyway" or adjust the destination environment.
- **`composer install` fails on the destination:** the pre-send `composer.json` audit at export time usually already points at the cause (local path/VCS repositories, `dev` version constraints); alternatively repeat the export with "Fix it for the migration package".
- **Frontend unstyled after import:** can happen when `assets:install` could not run automatically on the destination, or the upload directory could not be linked into the web root — both cases are recorded in the job log together with the required manual remedy.
- **Migration stalls with no backend window open:** Contao's web-cron only fires on front-end traffic — set up a system cron calling `contao:cron` (see Section 7).

## 25. Genuine Known Limitations

- Contao's own web-cron is usually not sufficient for unattended progress; a real system cron is recommended.
- The database dump/restore logic targets MySQL/MariaDB; other database platforms are not the target scenario for production use.
- `contao:migrate` is deliberately not run automatically after an import — a pure host move is meant to keep the exact source versions, not upgrade at the same time; the operator runs this step themselves when a later Contao upgrade is due.
- Composer repositories of type local path or VCS, and `dev` version constraints, can prevent `composer install` from succeeding on the destination; the pre-send audit warns but does not decide automatically.
- Direct server-to-server transfer requires the capability on both the sending and the receiving installation; a Pro source cannot push into a Free destination.
- The feature set follows from the signed licence. If V-T.ONE later redefines what a package includes, an already-activated licence keeps its existing feature set until the operator runs "Update licence" or V-T.ONE delivers an update.

## 26. Licence and Copyright Information

- **Package:** `vtinnovations/migrator`
- **Licence (source code):** LGPL-3.0-or-later (see `composer.json` → `license`)
- **Copyright holder:** V&T Innovations Team
- **Website:** https://www.v-t.one

Using the migration functionality implemented in the bundle additionally requires an activated V-T.ONE licence (see Section 14) — independent of the package's own source-code licence.

*Note on this documentation:* `composer.json` defines no top-level `homepage` field; the website shown above is therefore the address recorded in the author entry and used consistently throughout the source code, `https://www.v-t.one`.

## 27. Further Links

- [Deutsche Version dieses Dokuments](README.md)
