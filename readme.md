# Contao Migrator

**Eine Contao-5-Website auf einen neuen Host umziehen** — als portables Paket herunterladen, ein Paket
importieren oder direkt von Server zu Server übertragen. Kein „noch ein Backup-Tool", sondern gezielt für
den **Umzug** gebaut: serialize-sichere URL-/Domain-Umschreibung, Konfigurations-Abgleich, Versions-Gate und
Nachbereitung am Ziel.

Paket: `vtinnovations/migrator` · Namespace: `Vtinnovations\Migrator` · Lizenz: LGPL-3.0-or-later
Author: V&T Innovations · https://v-t.one

---

## Funktionen

- **Mode A — manuell**: portables `.tcmig`-Paket exportieren, herunterladen, auf dem neuen Host wieder importieren.
- **Mode B — direkt**: Server-zu-Server-Push, HMAC-signiert, ohne manuelles Herunterladen/Hochladen.
- **Split-Download**: das Paket in beliebig kleine Teile (Max-MB frei wählbar) zerlegen — für Hosts mit strengem
  `upload_max_filesize` / `post_max_size`.
- **Sequentieller Multi-Upload**: alle Teile auf einmal auswählen; der Browser lädt sie **einzeln nacheinander**
  hoch (timeout-sicher), fügt sie zusammen und startet den Import.
- **Serialize-sichere URL-Umschreibung**: rechnet `s:LEN:`-Präfixe in serialisierten Blobs korrekt neu; lässt
  DBAFS-UUIDs und `{{file::…}}`-Insert-Tags unangetastet; aktualisiert `tl_page.dns`/`useSSL`.
- **composer.json-Audit vor dem Versand**: warnt vor PATH-/VCS-Repos, `@dev`-Constraints, deaktiviertem
  Packagist u. a. — mit **Ein-Klick-Fix** (vendor/ mitschicken + portable composer.json), sodass das Ziel
  gar kein `composer install` braucht.
- **Passphrase-Signierung**: verschlüsselt Geheimnisse und signiert das Paket; am Ziel per Passphrase verifiziert.
- **Aufträge abbrechen & löschen**: laufende Jobs mitten im Lauf stoppen, alte Jobs samt Paket/Backup entfernen.
- **Eigenständiges Recovery-Panel**: eine Datei im Web-Root, die eine Migration steuert und `contao:migrate`
  ausführen kann — auch wenn das Backend nach einem versionsübergreifenden Restore nicht mehr lädt.
- **Zweisprachig** (Deutsch/Englisch), abhängig von der Backend-Sprache.
- **Resumable & shell-frei**: läuft auf Shared Hosting (Plesk) ohne `mysqldump`/Shell, hält Zeitbudgets ein und
  setzt via Cron oder Browser-Poll fort.

---

## Voraussetzungen

- PHP **8.2+**
- Contao **5.3+** (Symfony 6.4 / 7)
- `ext-json`, `ext-zlib`
- Schreibrechte auf `var/migrator/`
- Für den unbeaufsichtigten Betrieb: ein System-Cron auf `contao:cron` (sonst treibt der Browser den Job an).

---

## Installation

Bezug über die V&T-Innovations-Paketquelle bzw. per Artefakt-Upload im **Contao Manager**
(Pakete → Hochladen). Nach der Installation:

```
vendor/bin/contao-console cache:clear
```

Das Backend-Modul erscheint unter der Menügruppe **Migrator → Contao Migrator**.

---

## Lizenzierung

**Kostenlos, aber lizenzpflichtig.** Das Plugin ist gratis — du brauchst nur einen **kostenlosen
Lizenzschlüssel** von **https://www.v-t.one** (kostenlos registrieren). Der Schlüssel wird pro Domain
ausgestellt und beim ersten erfolgreichen Verify an die Domain gebunden.

- Kostenlosen Schlüssel auf v-t.one holen, dann im Backend-Modul aktivieren (Schlüssel eintragen).
- **Grace-Fenster**: das Ergebnis wird lokal (`var/migrator/license.json`) zwischengespeichert; ein kurzer
  Server-Ausfall sperrt nicht sofort. Re-Check läuft, sobald der Cache älter als 24 h ist; eine serverseitige
  Sperre/Kündigung greift dann spätestens innerhalb eines Tages.
- Ohne gültige Lizenz bleibt das gesamte Plugin gesperrt (Backend-Modul, AJAX-Endpunkte, Mode-B-Empfang,
  Recovery-Panel-Aktionen).

---

## Nutzung

### 1) Export (Mode A)

Tab **Export** → optional eine Passphrase setzen → **Export starten**. Der Fortschritt läuft im Monitor;
danach steht der Download bereit.

**Splitten**: im Monitor **Max. MB pro Teil** eingeben → **Teile vorbereiten** → einzelne Teil-Links oder
**Alle nacheinander laden**. Das Aneinanderhängen der Teile in Reihenfolge ergibt wieder das Original.

### 2) Import (Mode A)

Tab **Import**:

- **Einzeldatei**: `.tcmig` hochladen (+ ggf. Passphrase) → **Hochladen & importieren**.
- **Geteilt**: alle Teile auf einmal auswählen → sie werden **nacheinander** hochgeladen, zusammengefügt und der
  Import startet automatisch.

### 3) Server-zu-Server (Mode B)

Der Migrator läuft auf **beiden** Installationen. Eine ist das **ZIEL** (neuer Host, empfängt), die andere die
**QUELLE** (alter Host, sendet).

1. **Am ZIEL** (Karte „Empfangen"): **Kopplungstoken erzeugen** — Einmal-Token, nur für diese eine Übertragung
   und nur kurz gültig.
2. Token **kopieren** und zur QUELLE bringen.
3. **An der QUELLE** (Karte „Senden"): Basis-URL des Ziels + Token einfügen, **Passphrase** setzen →
   **Erstellen & senden**.
4. Die Quelle baut das Paket und pusht es HMAC-signiert direkt ans Ziel; dort startet der Import automatisch.

> **Wichtig:** Das Mode-B-Paket muss **passphrase-signiert** sein — dieselbe Passphrase wird am Ziel zur
> Verifizierung gebraucht. Notieren.

### composer.json-Audit + Ein-Klick-Fix

Vor Export/Push prüft der Migrator die Projekt-`composer.json` auf Dinge, die ein `composer install` am Ziel
brechen (lokale PATH-Repos, VCS-Repos mit Zugangsdaten, `@dev`, deaktiviertes Packagist, Path-Pakete ohne
`version`). Bei Funden pausiert der Job mit einer Warnung. Zwei Optionen:

- **Trotzdem fortfahren** — Warnungen bestätigen.
- **Fürs Migrations-Paket beheben** — schickt `vendor/` mit **und** schreibt eine portable `composer.json` ins
  Paket. Das Ziel braucht dann **kein** `composer install`. Die Live-`composer.json` der Quelle bleibt
  unangetastet.

### Aufträge abbrechen & löschen

- **Abbrechen** (laufender Job): stoppt mitten im Lauf. Im Monitor oder in der Auftragsliste.
- **Löschen** (fertiger Job): entfernt den Auftrag samt gebautem Paket, Backup- und Staging-Verzeichnis.
  Laufende Jobs müssen erst abgebrochen werden.

---

## Recovery-Panel (Break-Glass)

Bei jedem Kernel-Boot kopiert das Bundle `public/_tcmig-recovery.php` in den Web-Root. Diese Datei läuft
**unabhängig vom Contao-Backend** (eigene Token-Auth über `var/migrator/auth.token`) — nützlich, wenn das
Backend nach einem versionsübergreifenden Restore nicht mehr lädt.

Aufruf: `https://<host>/_tcmig-recovery.php`. Der Operator-Token steht im Backend-Modul (Tab „Wiederherstellung")
bzw. in `var/migrator/auth.token`. Funktionen: aktiven Job antreiben, **contao:migrate** ausführen (+ Assets neu
veröffentlichen), Kopplungstoken erzeugen, Passphrase nachreichen, Job abbrechen.

> Der Token landet als `?key=…` im Access-Log — nach Gebrauch rotieren, falls relevant.

---

## Nach der Migration (am Ziel)

Ein Umzug ist ein **reiner MOVE**, kein Upgrade: das Ziel soll exakt die Quell-Versionen fahren.

- **Mit Ein-Klick-Fix** (vendor/ mitgeschickt): kein `composer install` nötig — der Code ist schon da.
- **Ohne**: am Ziel `composer install --no-dev` ausführen (honoriert die `composer.lock`, inkl. Downgrades),
  danach `cache:clear` und `assets:install`. **Kein** `contao:migrate` bei einem reinen Move — das
  wiederhergestellte Schema passt bereits.

---

## Konfiguration (`var/migrator/config.json`)

| Schlüssel | Standard | Bedeutung |
|---|---|---|
| `excludes` | vendor, var/cache, var/log, var/migrator, node_modules, .git, … | Vom Datei-Archiv ausgeschlossene Pfade |
| `archive_chunk_bytes` | 52428800 (50 MiB) | Zielgröße je `files.NNN.tar.gz` |
| `push_chunk_bytes` | 4194304 (4 MiB) | Start-Chunk beim Mode-B-Push (halbiert sich adaptiv bei HTTP 413) |
| `download_volume_bytes` | 83886080 (80 MiB) | Standard-Volumengröße für Split-Download (0 = aus) |
| `db_rows_per_chunk` | 2000 | Zeilen pro DB-INSERT-Chunk |
| `db_max_insert_bytes` | 1048576 (1 MiB) | Byte-Deckel je INSERT (unter `max_allowed_packet`) |
| `retention_backups` | 3 | Anzahl aufbewahrter Backups |
| `mailer_policy` | keep | `keep` = Ziel-`MAILER_DSN` behalten, `carry` = Quelle übernehmen |
| `preserve_destination_admins` | true | Ziel-Admin-Konten beim Import erhalten |

---

## Sicherheit

- Mode-B-Endpunkte sind öffentlich, aber **HMAC-authentifiziert** mit dem Einmal-Kopplungsschlüssel;
  Vergleiche in konstanter Zeit (`hash_equals`).
- Kopplungs-Token: einmalig, zeitlich begrenzt (Sliding-Window verlängert nur während einer laufenden
  Übertragung), 0600 gespeichert, beim Finalize verbraucht.
- Passphrasen liegen nur in transienten 0600-Sidecars — nie im Job-JSON.
- Backend-AJAX + Cancel/Delete/Upload sind CSRF-geschützt und laufen unter dem Contao-Backend-Firewall
  (`_scope: backend`).
- SSL-Verify bleibt beim Push aktiv.

---

## Sprache

Deutsch/Englisch je nach Backend-Sprache des Nutzers. Pipeline-Meldungen, Status/Schritt-Namen und die
composer.json-Warnungen werden bei der Anzeige übersetzt; das Recovery-Panel ist auf Deutsch.

---

## Grenzen / Hinweise

- Kein DB-Rollback: `SafetySnapshotStep` sichert nur `.env`. Bei kritischen Zielen vorher die Ziel-DB dumpen.
- Generierte `public/bundles/`-Assets reisen nicht mit — am Ziel via `assets:install` neu veröffentlichen
  (der Migrator macht das in der Nachbereitung automatisch).
- Private/Path-Pakete: entweder den **Ein-Klick-Fix** nutzen (vendor/ mitschicken) oder sicherstellen, dass die
  Repos am Ziel erreichbar sind.

---

## Support

V&T Innovations — https://v-t.one
