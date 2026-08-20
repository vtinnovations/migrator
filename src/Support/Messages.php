<?php

/*
 * Contao Migrator
 *
 * Package: vtinnovations/migrator
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

declare(strict_types=1);

namespace Vtinnovations\Migrator\Support;

/**
 * Central, display-time translator for the pipeline's job log + fail/pause reasons. Steps keep
 * emitting their English messages; every message flows through Job::addLog (and the runner sets
 * job->error) which passes it here with the job's language (meta['lang'], captured from the
 * backend user when the job was created). English is the pass-through default.
 *
 * Rules are applied sequentially (preg_replace with rule arrays), so a composed message gets each
 * of its known fragments translated in one pass. Rules are anchored where the message is the whole
 * string, or prefix-only where a variable tail is appended with `.`; dynamic numbers are preserved
 * via capture groups.
 */
final class Messages
{
    /** @var array<string, list<string>> lang => list of regex patterns */
    private const PATTERNS = [
        'de' => [
            // --- Preflight ---
            '#^Preflight: (\d+) root host\(s\), scanning DB for URLs\.$#u',
            '#^Insufficient disk space: need ~(\d+) MiB, have (\d+) MiB free\.$#u',
            '#^URL scan (\d+)/(\d+) columns\.$#u',
            '#^No URL candidates to scan\.$#u',
            '#^Preflight done: (\d+) URL candidate column\(s\)\.$#u',
            '#^composer\.json needs review before sending — (\d+) warning\(s\)\.$#u',
            // --- Database dump ---
            '#^Schema dumped, starting data\.$#u',
            '#^Dumped (\d+)/(\d+) tables\.$#u',
            '#^Database dump complete \((\d+) tables\)\.$#u',
            // --- File archive ---
            '#^Enumerated (\d+) files for archiving(.*)\.$#u',
            '# \(incl\. vendor/ — composer-fix\)#u',
            '#^Archived (\d+)/(\d+) files\.$#u',
            '#^File archive complete \((\d+) files\)\.$#u',
            '#^Could not create files/ dir\.$#u',
            // --- Secrets ---
            '#^Encrypted (\d+) secret\(s\)\.$#u',
            '#^Secrets step: nothing encrypted \(destination must re-enter secrets\)\.$#u',
            '#^Could not create secrets dir\.$#u',
            '#^Could not write secrets\.enc\.$#u',
            '#^Could not decrypt secrets vault: #u',
            // --- Finalize export ---
            '#^Export finalized — package ready for download\.$#u',
            '#^No manifest to finalize\.$#u',
            // --- Unpack / verify ---
            '#^Unpacked (\d+) entries into staging\.$#u',
            '#^Package already unpacked\.$#u',
            '#^Package has no manifest\.json — not a valid \.tcmig archive\.$#u',
            '#^Uploaded package not found: #u',
            '#^Manifest missing in staging\.$#u',
            '#^Verified (\d+)/(\d+) chunks\.$#u',
            '#^Package verified \((\d+) chunks, signature OK\)\.$#u',
            '#^This package is passphrase-signed — supply the export passphrase to verify it\.$#u',
            '#^Manifest signature invalid — wrong passphrase\. Re-enter the export passphrase\.$#u',
            '#^Manifest signature invalid — tampered package\.$#u',
            '#^Checksum mismatch: #u',
            '#^Missing chunk: #u',
            // --- Compatibility ---
            '#^Compatibility OK\.$#u',
            '#^Compatibility warnings acknowledged by operator\.$#u',
            '#^Compatibility warnings — operator confirmation required: #u',
            '#^Manifest missing for compatibility check\.$#u',
            // --- Capture dest config ---
            '#^Captured (\d+) destination config key\(s\)\.$#u',
            // --- Safety snapshot ---
            '#^Snapshotted (\d+) env file\(s\) for rollback\.$#u',
            '#^Could not create snapshot dir\.$#u',
            '#^Could not snapshot #u',
            // --- Extract files ---
            '#^No file chunks to extract\.$#u',
            '#^Extracted (\d+)/(\d+) file chunk\(s\)\.$#u',
            '#^All (\d+) file chunk\(s\) extracted\.$#u',
            '#^Manifest missing for extract\.$#u',
            '#^Missing file chunk: #u',
            // --- Restore database ---
            '#^Schema restored \((\d+) tables\), loading data\.$#u',
            '#^Created (\d+)/(\d+) tables\.$#u',
            '#^Loaded (\d+)/(\d+) data chunks\.$#u',
            '#^Database restored \((\d+) data chunks\)\.$#u',
            '#^Manifest missing for DB restore\.$#u',
            '#^Schema file missing: #u',
            '#^Missing data chunk: #u',
            // --- URL replace ---
            '#^URL replacement needs an operator-confirmed host map\.$#u',
            '#^Host map is a no-op — nothing to rewrite\.$#u',
            '#^No URL candidates — nothing to rewrite\.$#u',
            '#^Rewriting (.*) …$#u',
            '#^URL rewrite (\d+)/(\d+) columns\.$#u',
            '#^URL rewrite complete \((\d+) value\(s\) changed\)\.$#u',
            '#^Manifest missing for URL replacement\.$#u',
            // --- Config reconcile ---
            '#^Manifest missing for config reconcile\.$#u',
            // --- Post migration ---
            '#^vendor/ shipped with the package — NO "composer install" needed; the destination runs the exact source code and versions\.#u',
            '# A portable composer\.json override was applied\.#u',
            '#^Pure move: run "composer install --no-dev" on the destination so its Contao \+ extensions match the source versions from the migrated composer\.lock \(downgrades included\)\. Do NOT run contao:migrate — the restored database already matches the source schema\.$#u',
            '#^Applied portable composer\.json override from the package\.$#u',
            '#^Could not apply composer\.json override \(copy failed\)\.$#u',
            '#^assets:install failed: #u',
            '#^Cache purged \((\d+) env dir\(s\)\)\. #u',
            '#Bundle web assets re-published\.#u',
            '#assets:install could NOT run automatically — run "vendor/bin/contao-console assets:install --symlink --relative public" manually or the backend/frontend stays unstyled\. #u',
            '#vendor/ shipped — no composer install needed\.#u',
            '#Next: run "composer install --no-dev" so the destination matches the source versions\.#u',
            // --- Finalize import ---
            '#^Migration import complete\. Verify the site, then run "contao:migrate"\.$#u',
            // --- Build / push (Mode B) ---
            '#^Package already built\.$#u',
            '#^Package built \((\d+) bytes\)\.$#u',
            '#^Could not checksum built package\.$#u',
            '#^Built package missing for push\.$#u',
            '#^Mode B push needs remoteUrl and pairing token\.$#u',
            '#^Malformed pairing token \(expected "session\.key"\)\.$#u',
            '#^Could not open package for push\.$#u',
            '#^Unexpected empty read while pushing package\.$#u',
            '#^Destination rejected even a (\d+) KiB chunk \(HTTP 413\)\. Raise the destination web server.s client_max_body_size / post_max_size\.$#u',
            '#^Pushed (\d+)/(\d+) bytes in (\d+) chunks\.$#u',
            '#^Transfer complete — remote import job (.*)\.$#u',
            '#^Finalize rejected: #u',
            // --- Runner ---
            '#^Paused at step "(.*)"\.$#u',
            '#^Job "(.*)" not found\.$#u',
            // --- Backend split-upload controller + assembler ---
            '#^Missing part index\.$#u',
            '#^No valid part uploaded\.$#u',
            '#^No parts uploaded\.$#u',
            '#^Invalid request token\.$#u',
            '#^Job not found\.$#u',
            '#^Missing chunk (\d+) during assembly\.$#u',
            '#^Assembled payload checksum mismatch \(corrupt or tampered transfer\)\.$#u',
            '#^Cancelled by operator\.$#u',
            '#^Cancel the job before deleting it\.$#u',
        ],
    ];

    /** @var array<string, list<string>> lang => list of replacements (index-aligned with PATTERNS) */
    private const REPLACEMENTS = [
        'de' => [
            'Preflight: $1 Root-Host(s), durchsuche DB nach URLs.',
            'Nicht genügend Speicherplatz: benötige ~$1 MiB, $2 MiB frei.',
            'URL-Scan $1/$2 Spalten.',
            'Keine URL-Kandidaten zu scannen.',
            'Preflight fertig: $1 URL-Kandidaten-Spalte(n).',
            'composer.json muss vor Versand geprüft werden — $1 Warnung(en).',
            'Schema gedumpt, beginne mit Daten.',
            '$1/$2 Tabellen gedumpt.',
            'Datenbank-Dump fertig ($1 Tabellen).',
            '$1 Dateien zum Archivieren erfasst$2.',
            ' (inkl. vendor/ — composer-fix)',
            'Archiviert $1/$2 Dateien.',
            'Datei-Archiv fertig ($1 Dateien).',
            'Konnte files/-Verzeichnis nicht erstellen.',
            '$1 Geheimnis(se) verschlüsselt.',
            'Secrets-Schritt: nichts verschlüsselt (Ziel muss Geheimnisse neu eingeben).',
            'Konnte secrets-Verzeichnis nicht erstellen.',
            'Konnte secrets.enc nicht schreiben.',
            'Konnte Secrets-Vault nicht entschlüsseln: ',
            'Export abgeschlossen — Paket bereit zum Download.',
            'Kein Manifest zum Abschließen.',
            '$1 Einträge ins Staging entpackt.',
            'Paket bereits entpackt.',
            'Paket hat keine manifest.json — kein gültiges .tcmig-Archiv.',
            'Hochgeladenes Paket nicht gefunden: ',
            'Manifest fehlt im Staging.',
            '$1/$2 Chunks verifiziert.',
            'Paket verifiziert ($1 Chunks, Signatur OK).',
            'Dieses Paket ist passphrase-signiert — Export-Passphrase zur Verifizierung angeben.',
            'Manifest-Signatur ungültig — falsche Passphrase. Export-Passphrase erneut eingeben.',
            'Manifest-Signatur ungültig — manipuliertes Paket.',
            'Prüfsummen-Fehler: ',
            'Fehlender Chunk: ',
            'Kompatibilität OK.',
            'Kompatibilitätswarnungen vom Betreiber bestätigt.',
            'Kompatibilitätswarnungen — Betreiber-Bestätigung erforderlich: ',
            'Manifest fehlt für Kompatibilitätsprüfung.',
            '$1 Ziel-Konfigurationsschlüssel erfasst.',
            '$1 Env-Datei(en) für Rollback gesichert.',
            'Konnte Snapshot-Verzeichnis nicht erstellen.',
            'Konnte nicht sichern ',
            'Keine Datei-Chunks zum Extrahieren.',
            '$1/$2 Datei-Chunk(s) extrahiert.',
            'Alle $1 Datei-Chunk(s) extrahiert.',
            'Manifest fehlt für Extraktion.',
            'Fehlender Datei-Chunk: ',
            'Schema wiederhergestellt ($1 Tabellen), lade Daten.',
            '$1/$2 Tabellen erstellt.',
            '$1/$2 Daten-Chunks geladen.',
            'Datenbank wiederhergestellt ($1 Daten-Chunks).',
            'Manifest fehlt für DB-Wiederherstellung.',
            'Schema-Datei fehlt: ',
            'Fehlender Daten-Chunk: ',
            'URL-Ersetzung braucht eine vom Betreiber bestätigte Host-Zuordnung.',
            'Host-Zuordnung ohne Wirkung — nichts umzuschreiben.',
            'Keine URL-Kandidaten — nichts umzuschreiben.',
            'Schreibe $1 um …',
            'URL-Umschreibung $1/$2 Spalten.',
            'URL-Umschreibung fertig ($1 Wert(e) geändert).',
            'Manifest fehlt für URL-Ersetzung.',
            'Manifest fehlt für Konfigurationsabgleich.',
            'vendor/ wurde mit dem Paket mitgeschickt — KEIN "composer install" nötig; das Ziel läuft mit exakt dem Quellcode und den Versionen der Quelle.',
            ' Eine portable composer.json wurde als Override angewendet.',
            'Reiner Umzug: führe am Ziel "composer install --no-dev" aus, damit Contao + Erweiterungen den Quell-Versionen aus der migrierten composer.lock entsprechen (inkl. Downgrades). contao:migrate NICHT ausführen — die wiederhergestellte Datenbank passt bereits zum Quell-Schema.',
            'Portable composer.json-Override aus dem Paket angewendet.',
            'composer.json-Override konnte nicht angewendet werden (Kopieren fehlgeschlagen).',
            'assets:install fehlgeschlagen: ',
            'Cache geleert ($1 Env-Verzeichnis(se)). ',
            'Bundle-Web-Assets neu veröffentlicht.',
            'assets:install konnte nicht automatisch laufen — führe "vendor/bin/contao-console assets:install --symlink --relative public" manuell aus, sonst bleibt Backend/Frontend ungestylt. ',
            'vendor/ mitgeschickt — kein composer install nötig.',
            'Nächster Schritt: "composer install --no-dev" ausführen, damit das Ziel den Quell-Versionen entspricht.',
            'Migrations-Import abgeschlossen. Seite prüfen, dann "contao:migrate" ausführen.',
            'Paket bereits gebaut.',
            'Paket gebaut ($1 Bytes).',
            'Prüfsumme des gebauten Pakets fehlgeschlagen.',
            'Gebautes Paket für Push fehlt.',
            'Modus-B-Push braucht remoteUrl und Kopplungstoken.',
            'Fehlerhafter Kopplungstoken (erwartet "session.key").',
            'Konnte Paket für Push nicht öffnen.',
            'Unerwartet leerer Lesevorgang beim Paket-Push.',
            'Ziel hat selbst einen $1 KiB-Chunk abgelehnt (HTTP 413). Erhöhe am Ziel-Webserver client_max_body_size / post_max_size.',
            '$1/$2 Bytes in $3 Chunks gepusht.',
            'Übertragung abgeschlossen — Remote-Import-Auftrag $1.',
            'Finalisierung abgelehnt: ',
            'Pausiert bei Schritt "$1".',
            'Auftrag "$1" nicht gefunden.',
            'Fehlender Teil-Index.',
            'Kein gültiger Teil hochgeladen.',
            'Keine Teile hochgeladen.',
            'Ungültiges Anfrage-Token.',
            'Auftrag nicht gefunden.',
            'Fehlender Chunk $1 bei der Zusammenführung.',
            'Zusammengeführtes Paket: Prüfsummen-Fehler (beschädigte oder manipulierte Übertragung).',
            'Vom Betreiber abgebrochen.',
            'Brich den Auftrag ab, bevor du ihn löschst.',
        ],
    ];

    /** @var array<string, array<string, string>> job-state labels */
    private const STATE = [
        'de' => [
            'pending' => 'ausstehend',
            'running' => 'läuft',
            'paused' => 'pausiert',
            'completed' => 'abgeschlossen',
            'failed' => 'fehlgeschlagen',
            'cancelled' => 'abgebrochen',
        ],
    ];

    /** @var array<string, array<string, string>> step-name labels */
    private const STEP = [
        'de' => [
            'preflight' => 'Vorprüfung',
            'dump_database' => 'Datenbank-Dump',
            'archive_files' => 'Dateien archivieren',
            'secrets' => 'Geheimnisse',
            'finalize_export' => 'Export abschließen',
            'build_package' => 'Paket bauen',
            'push_package' => 'Paket senden',
            'unpack_package' => 'Paket entpacken',
            'verify_package' => 'Paket verifizieren',
            'compat_gate' => 'Kompatibilitätsprüfung',
            'capture_dest_config' => 'Ziel-Konfig erfassen',
            'safety_snapshot' => 'Sicherungs-Snapshot',
            'extract_files' => 'Dateien extrahieren',
            'restore_database' => 'Datenbank wiederherstellen',
            'url_replace' => 'URLs ersetzen',
            'config_reconcile' => 'Konfig abgleichen',
            'post_migration' => 'Nachbearbeitung',
            'finalize_import' => 'Import abschließen',
        ],
    ];

    public static function translate(string $lang, string $text): string
    {
        if ('de' !== $lang || '' === $text) {
            return $text;
        }

        $out = preg_replace(self::PATTERNS['de'], self::REPLACEMENTS['de'], $text);

        return \is_string($out) ? $out : $text;
    }

    public static function stateLabel(string $lang, string $value): string
    {
        return self::STATE[$lang][$value] ?? $value;
    }

    public static function stepLabel(string $lang, string $value): string
    {
        return self::STEP[$lang][$value] ?? $value;
    }
}
