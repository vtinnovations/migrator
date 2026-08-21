<p align="right"><a href="README.en.md">🇬🇧 English version</a></p>

# Contao Migrator

Contao-Bundle zum Umzug einer Contao&nbsp;5-Website auf einen neuen Host — als portables `.tcmig`-Paket (manueller Export/Import) oder direkt per Server-zu-Server-Übertragung, inklusive serialisierungssicherer URL-Umschreibung.

## 1. Projektübersicht

Contao Migrator ist ein Backend-Bundle für Contao&nbsp;5, das eine komplette Installation (Datenbank, Dateibaum, ausgewählte Geheimnisse) als ein einziges, signiertes `.tcmig`-Paket sichert und auf einer Zielinstallation wiederherstellt. Zwei Übertragungswege stehen zur Verfügung:

- **Modus A — manuell:** Export als Download, Import per Upload (bei Bedarf in Teile gesplittet, um Upload-Limits zu umgehen).
- **Modus B — Server-zu-Server:** Die Quellinstallation baut das Paket und überträgt es direkt und signiert an eine gekoppelte Zielinstallation, die den Import automatisch startet.

Ein eigener Ersetzungsalgorithmus sorgt dafür, dass der alte Hostname überall in der Datenbank ersetzt wird — auch innerhalb PHP-serialisierter Werte, ohne diese zu beschädigen.

## 2. Aktueller Implementierungs- und Produktionsstatus

Das Bundle ist vollständig implementiert, kein Platzhalter und keine geplante Funktion. Jede hier dokumentierte Funktion ist bis zur tatsächlichen Ausführung nachvollzogen (Route/Formular → Controller bzw. Backend-Modul → Dienst → Job-Pipeline → Persistenz). Das Projekt bringt eine eigene Audit-Testsuite (`tests/Audit/`) mit, die unter anderem die Verdrahtung der Lizenzoberfläche, die Trennung sensibler Verantwortlichkeiten und die Vollständigkeit der Übersetzungen laufend prüft.

## 3. Unterstützte Framework- und Laufzeitversionen

| Komponente | Version |
|---|---|
| PHP | `^8.2` |
| Contao | `contao/core-bundle` `^5.3` |
| Symfony-Komponenten | `symfony/config`, `symfony/dependency-injection`, `symfony/http-foundation`, `symfony/http-kernel` — je `^6.4 \|\| ^7.0` |
| Doctrine DBAL | `^3.6 \|\| ^4.0` |
| Contao Manager | über `contao/manager-plugin` `^2.0` (automatische Bundle-Erkennung) |

Datenbank: Die Dump-/Restore-Logik ist auf **MySQL/MariaDB** ausgelegt (u.&nbsp;a. `SHOW CREATE TABLE`, `UNHEX()`-Literale). Eine portable Schema-Rekonstruktion existiert zusätzlich, wird in der Praxis aber nur vom automatisierten Testkorpus (SQLite) genutzt.

## 4. Systemvoraussetzungen

- PHP `^8.2` mit den Erweiterungen `ext-json` und `ext-zlib` (Pflicht).
- `ext-sodium` **empfohlen**: Geheimnisse (APP_SECRET, Verschlüsselungsschlüssel) werden bevorzugt mit Argon2id + XChaCha20-Poly1305 verschlüsselt; ohne `sodium` greift automatisch ein OpenSSL-Fallback (PBKDF2-SHA256 + AES-256-GCM).
- `ext-curl` optional: Die Server-zu-Server-Übertragung nutzt curl, wenn verfügbar, sonst einen PHP-Stream-Fallback.
- Ausreichend freier Plattenspeicher im Projektverzeichnis (die Preflight-Prüfung schätzt den Bedarf mit Sicherheitsfaktor und bricht andernfalls vorab ab).
- Für unbeaufsichtigten Fortschritt: ein Contao-Cron, der regelmäßig läuft (siehe Abschnitt 7).

## 5. Installation

```bash
composer require vtinnovations/migrator
```

Als reguläres `contao-bundle`-Paket wird das Bundle über den Contao Manager automatisch erkannt und registriert (`Vtinnovations\Migrator\ContaoManager\Plugin`, deklariert in `composer.json` → `extra.contao-manager-plugin`). Eine manuelle Bundle-Registrierung ist nicht erforderlich.

## 6. Composer-, Paketmanager- und Framework-Einrichtung

Bundle-Konfiguration (optional) unter dem Schlüssel `vtinnovations_migrator` in der Symfony-Konfiguration des Projekts, z.&nbsp;B. `config/packages/vtinnovations_migrator.yaml`:

```yaml
vtinnovations_migrator:
    scratch_dir: '%kernel.project_dir%/var/migrator'   # Standardwert
    time_budget: 20.0                                   # Sekunden pro Job-Tick, Standardwert
```

| Option | Standardwert | Bedeutung |
|---|---|---|
| `scratch_dir` | `%kernel.project_dir%/var/migrator` | Arbeitsverzeichnis für Aufträge, Backups und Staging. |
| `time_budget` | `20.0` | Maximale Laufzeit (Sekunden) eines einzelnen Job-Ticks, bevor der Zustand gespeichert und die Ausführung an den nächsten Tick übergeben wird. |

Zusätzliche Betriebseinstellungen (Ausschlussliste für den Datei-Export, Chunk-Größen, Aufbewahrungsanzahl für Backups, Standard-Volumengröße für den geteilten Download, Verhalten für `MAILER_DSN`) werden in `var/migrator/config.json` verwaltet, mit sinnvollen Standardwerten, falls die Datei fehlt.

## 7. Erforderliche ausführbare Programme und Konfiguration

- **Contao-Cron:** Das Bundle registriert einen Cronjob (`vtinnovations_migrator.tick_active_jobs`, Intervall `minutely`), der jeden laufenden Auftrag einmal pro Minute antreibt, auch ohne geöffnetes Backend-Fenster. Contaos eigener Web-Cron feuert jedoch nur bei Frontend-Zugriffen — für zuverlässigen Fortschritt sollte ein echter System-Cron eingerichtet werden:
  ```bash
  * * * * * php vendor/bin/contao-console contao:cron
  ```
- Kein externes ausführbares Programm (kein `mysqldump`, kein `tar`, kein `exec()`) wird vorausgesetzt — Datenbank-Dump/-Restore und Archivierung sind reine PHP-Implementierungen.
- Nach einem Import ist `composer install --no-dev` auf dem Zielsystem auszuführen, **außer** das Exportpaket wurde mit der Option „Fürs Migrations-Paket beheben“ erzeugt (dann liefert das Paket `vendor/` bereits mit).

## 8. Dateisystemberechtigungen

- Schreibzugriff auf `var/migrator/` (bzw. den konfigurierten `scratch_dir`) für den Webserver-Prozess.
- Schreibzugriff auf das Web-Root-Verzeichnis (`public/` bzw. `web/`), damit das eigenständige Wiederherstellungs-Panel bei jedem Kernel-Boot dorthin gespiegelt werden kann.
- Sensible Dateien (Betreiber-Token, Passphrase-Zwischenspeicher, Lizenzstatus) werden mit `chmod 0600` angelegt.

## 9. Backend- und Administrationszugriff

Das Bundle stellt **kein Frontend-Modul** bereit — die gesamte Funktion ist ausschließlich über das Contao-Backend erreichbar, mit Ausnahme des eigenständigen, tokengeschützten Wiederherstellungs-Panels (Abschnitt 12).

- **Backend-Modul:** Contao-Backend → Gruppe „migrator“ → „Contao Migrator“.
- **Lizenzverwaltung:** Contao-Backend → Einstellungen → Abschnitt „V-T.ONE Licence management“ → Feld „Migrator“.

Der Zugriff unterliegt der regulären Contao-Backend-Benutzer-/Gruppenberechtigung für Backend-Module bzw. für die Einstellungsseite; das Bundle definiert keine eigene Berechtigungsebene.

## 10. Frontend-Integration

Nicht zutreffend — es existiert kein Frontendmodul, kein Inhaltselement und kein öffentlich sichtbarer Bestandteil im regulären Seitenaufbau. Die beiden öffentlich erreichbaren HTTP-Endpunkte (Modus-B-Empfang und der Lizenz-Update-Endpunkt) sind reine Server-zu-Server-Schnittstellen ohne Benutzeroberfläche.

## 11. Navigationsmodule und Reiter (tatsächliche Reihenfolge)

Innerhalb des Backend-Moduls „Contao Migrator“, in exakt dieser Reihenfolge:

1. **Export**
2. **Import**
3. **Server-zu-Server**
4. **Wiederherstellung** (Verweis auf das eigenständige Panel)

Oberhalb der Reiter erscheint bei aktivem Auftrag ein Live-Monitor (Fortschrittsbalken, Protokoll, ggf. Bestätigungsformulare für pausierte Schritte); unterhalb der Reiter folgt die Liste der letzten 12 Aufträge mit Status, Fortschritt und Aktionen (Abbrechen/Löschen).

## 12. Verifizierte Funktionen aller wesentlichen Module

### Export (Modus A)

- Baut ein `.tcmig`-Paket (Datenbank-Dump, Dateibaum, optional verschlüsselte Geheimnisse) und signiert das Manifest.
- Optionale Export-Passphrase: verschlüsselt `APP_SECRET`, den Contao-Verschlüsselungsschlüssel und den Datenbank-Verschlüsselungsschlüssel für die Übernahme auf dem Zielsystem; ohne Passphrase müssen diese Werte am Ziel von Hand nachgetragen werden.
- Vorab-Prüfung der `composer.json` (lokale Pfad-/VCS-Repositories, deaktiviertes Packagist, `dev`-Versionsbindungen, instabile `minimum-stability`, ungepinnte Pfadpakete) pausiert den Auftrag genau einmal; der Bediener kann „Trotzdem fortfahren“ oder „Fürs Migrations-Paket beheben“ wählen (liefert `vendor/` und eine portable, gepinnte `composer.json` mit).
- Download als Einzeldatei oder in konfigurierbar große Teile gesplittet (Standard 80&nbsp;MiB), inklusive „Alle nacheinander laden“-Hilfsfunktion.
- Aufbewahrung: die zuletzt erzeugten lokalen Backups werden behalten (Standard: 3), ältere automatisch entfernt.

### Import (Modus A)

- Einzeldatei-Upload oder geteilter Upload (`.001`, `.002`, … werden sequenziell hochgeladen und serverseitig zusammengesetzt) — umgeht Upload-Größenlimits des Hosts.
- Manifestsignatur und jede einzelne Datenblock-Prüfsumme werden **vor** jedem destruktiven Schritt verifiziert; bei passphrase-signierten Paketen wird die Passphrase interaktiv nachgefragt.
- Kompatibilitätsprüfung: warnt bei einem PHP-Downgrade oder einer abweichenden Contao-Hauptversion zwischen Quelle und Ziel und verlangt eine bewusste Bestätigung.
- Sicherheitsschnappschuss der bestehenden `.env`/`.env.local`-Dateien vor jedem destruktiven Schritt.
- Host-Umschreibung: nach Bestätigung der Host-Zuordnung durch den Bediener werden alle vom Preflight erkannten Datenbankspalten umgeschrieben — **serialisierungssicher**, damit PHP-serialisierte Werte gültig bleiben.
- Konfigurationsabgleich: zielspezifische Werte (`DATABASE_URL`, `TRUSTED_PROXIES`, `TRUSTED_HOSTS`, optional `MAILER_DSN`) werden erhalten, mitgelieferte Quellgeheimnisse eingespielt; fehlt ein zielspezifischer Wert, wird die aus der Quelle stammende Zeile in `.env.local` sicherheitshalber auskommentiert statt übernommen, und der Bediener wird zum manuellen Nachtragen aufgefordert.
- Nachbereitung: Bundle-Web-Assets werden neu veröffentlicht (`assets:install --symlink --relative`), das Contao-Upload-Verzeichnis wird bei Bedarf per Symlink (oder Kopie als Fallback) im Web-Root erreichbar gemacht, `var/cache` wird geleert. Ein mitgeliefertes `vendor/`-Verzeichnis macht `composer install` überflüssig; andernfalls wird es als nächster Schritt protokolliert. `contao:migrate` wird bewusst **nicht** automatisch ausgeführt (reiner Host-Umzug, keine Versions-Migration).

### Server-zu-Server-Übertragung (Modus B)

- **Ziel** erzeugt einen einmaligen, zeitlich begrenzten Kopplungstoken („Empfangen“-Karte) und übergibt ihn dem Bediener.
- **Quelle** fügt Ziel-URL und Token ein, setzt optional eine Passphrase und startet „Erstellen & senden“ — baut das Paket und überträgt es in signierten, adaptiv verkleinerbaren Blöcken direkt an den Ziel-Endpunkt; das Ziel startet den Import automatisch nach Abschluss und Prüfsummenverifikation.
- Die Kopplung ist einmalig nutzbar und läuft nach Ablauf automatisch ab.

### Wiederherstellungs-Panel

Siehe Abschnitt 17 (Betriebssicherheit).

### Lizenzverwaltung

Siehe Abschnitt 14.

### Auftragssteuerung

- Jeder Auftrag läuft in zeitbudgetierten „Ticks“ (Standard 20&nbsp;s); Fortschritt wird nach jedem Tick persistiert, sodass eine unterbrochene Anfrage höchstens das laufende Teilstück verliert.
- Solange das Backend-Fenster geöffnet ist, treibt JavaScript den Auftrag fortlaufend an; der minütliche Cronjob übernimmt dies unbeaufsichtigt.
- Abbrechen ist für laufende, wartende und pausierte Aufträge möglich; Löschen erst, nachdem ein Auftrag abgeschlossen, fehlgeschlagen oder abgebrochen ist (entfernt Auftragsdaten, Paket, Backup und Staging-Verzeichnis).

## 13. Berechtigungen und Zugriffskontrolle

| Grenze | Absicherung |
|---|---|
| Backend-Modul & AJAX-Routen (`/contao/migrator/...`) | Contao-Backend-Firewall (`_scope: backend`) + CSRF-Token (`REQUEST_TOKEN`) |
| Lizenzverwaltung (Contao → Einstellungen) | reguläre Contao-Einstellungsseiten-Berechtigung; eigenes Formularfeld ohne zusätzliche Route |
| Modus-B-Empfang (`/migrator/ingest/...`) | öffentlich erreichbar, aber ausschließlich über eine signierte, einmalige Kopplung authentifiziert — keine Backend-Anmeldung nötig oder möglich |
| Lizenz-Update-Endpunkt (server-zu-server) | öffentlich erreichbar, ausschließlich über eine kryptographisch signierte Anfrage authentifiziert; von V-T.ONE initiiert, ohne Backend-Anmeldung |
| Wiederherstellungs-Panel (`/_tcmig-recovery.php`) | Betreiber-Token; unabhängig vom Contao-Backend erreichbar |

Jede geschützte Operation prüft den gültigen Berechtigungsstatus **unmittelbar an ihrer eigenen Grenze** erneut — nicht nur einmalig am äußeren Zugriffspunkt — und prüft dabei genau die Berechtigungsstufe, die diese Operation voraussetzt. Auch die Auftragsverarbeitung prüft erneut, bevor sie einen Auftrag weiterführt: Ein Auftrag der Pro-Stufe bleibt stehen, sobald die Berechtigung entfällt, unabhängig davon, über welchen Zugriffspunkt er angestoßen wurde. Das Ausblenden einer Schaltfläche gilt an keiner Stelle als Zugriffsschutz.

## 14. Lizenz- und Berechtigungsverhalten

Contao Migrator verwendet ein **Trial/Free-und-Pro-Lizenzmodell**: Jede Nutzung — auch die kostenlose Stufe — erfordert eine aktivierte, signierte Lizenz; es gibt keinen anonymen Modus ohne Aktivierung.

**Aktivierung, Aktualisierung, Entfernung** erfolgen ausschließlich über Contao → Einstellungen → „V-T.ONE Licence management“ → „Migrator“:

- **Lizenz prüfen & aktivieren** — Schlüssel eingeben und gegen den konfigurierten Hostnamen der Installation aktivieren.
- **Lizenz aktualisieren** — verwendet den gespeicherten Schlüssel und die aktuell installierte Lizenzversion, ohne erneute Eingabe.
- **Lizenz entfernen** — löscht den lokalen Lizenzstatus, die Installation fällt sofort auf „nicht lizenziert“ zurück.

**Tatsächliche Lizenzzustände** (wie im Backend angezeigt):

| Zustand | Bedeutung |
|---|---|
| Nicht lizenziert | Keine gültige Lizenz gespeichert — das Backend-Modul zeigt nur einen Verweis auf die Einstellungsseite, keine Funktion ist nutzbar. |
| Testlizenz aktiv | Signierte Trial-Lizenz, aktuell gültig. |
| Free-Lizenz aktiv | Signierte Free-Lizenz, aktuell gültig. |
| Pro-Lizenz aktiv | Signierte Pro-Lizenz, aktuell gültig. |
| Pro-Lizenz abgelaufen — Free-Funktionsumfang aktiv | Eine abgelaufene Pro-Lizenz behält den Free-Funktionsumfang **nur**, wenn die signierte Lizenz dies ausdrücklich erlaubt. |
| Lizenz abgelaufen | Trial/Free ohne Rückfallebene, oder Pro ohne erlaubten Rückfall — Zugriff gesperrt. |
| Lizenz kann auf dieser Installation nicht geprüft werden | Manipuliert, beschädigt oder an eine andere Domain gebunden. |

Angezeigt werden zusätzlich: maskierter Schlüssel, Paket, „Gültig ab“, „Gültig bis“ (bzw. „unbefristet“) und „Zuletzt geprüft“.

**Bindung:** Eine Lizenz wird an einen exakten, in den Contao-Root-Seiten konfigurierten Hostnamen gebunden (kein `www.`-Abgleich, keine Subdomain-Gleichsetzung). Fehlt eine passende Domain-Konfiguration, kann keine Aktivierung erfolgen — der Hinweis auf der Einstellungsseite zeigt die konfigurierten Hosts an.

**Prüfungen:** Übliche Berechtigungsprüfungen laufen vollständig lokal und ohne Netzwerkzugriff. Für Aktivierung und Aktualisierung wird ein einziger, fest im Code hinterlegter HTTPS-Dienst kontaktiert. Zusätzlich kann V-T.ONE serverseitig eine Lizenzaktualisierung an die Installation zustellen (signiert, ohne Backend-Anmeldung, ausschließlich über eine Anfragesignatur authentifiziert).

**Speicherung:** Der Lizenzstatus liegt außerhalb des öffentlichen Web-Roots unter `var/migrator/`.

### Funktionsstufen

Der Funktionsumfang ist in zwei Stufen unterteilt. Beide werden serverseitig durchgesetzt.

| Funktionsbereich | Free | Pro |
|---|:--:|:--:|
| Export, Import und Wiederherstellung | Enthalten | Enthalten |
| Direkte Server-zu-Server-Übertragung | Nicht enthalten | Enthalten |

- **Export, Import und Wiederherstellung bilden den Free-Funktionsumfang.** Jede gültige Lizenz öffnet das Backend-Modul, beide manuellen Übertragungsrichtungen sowie alle Aktionen des Wiederherstellungs-Panels.
- **Die direkte Server-zu-Server-Übertragung ist die kostenpflichtige Funktion.** Eine aktive Testlizenz enthält sie, da eine Testphase gerade der Bewertung dieser Funktion dient; mit Ablauf der Testlizenz entfällt sie. Sie ist nicht Teil des Free-Rückfalls einer abgelaufenen Pro-Lizenz.
- **Beide Installationen benötigen die Berechtigung.** Eine direkte Übertragung setzt sie auf der sendenden *und* der empfangenden Installation voraus. Eine Lizenz kann beide Hosts abdecken, wenn beide Hostnamen zu ihrem lizenzierten Domainumfang gehören. Eine Installation mit Free-Umfang kann nicht als Ziel für die direkte Übertragung einer anderen Installation dienen.
- **Bestehende Daten bleiben zugänglich.** Fortschrittsanzeige, Download eines bereits erzeugten Pakets sowie Abbrechen und Löschen bleiben im Free-Umfang. Eine abgelaufene Pro-Lizenz hinterlässt daher keinen Auftrag, den der Bediener nicht mehr aufräumen kann.
- **Der Umfang kann ohne Paketwechsel erweitert werden.** V-T.ONE kann die direkte Übertragung für eine bestehende Lizenz freischalten, ohne deren Paket zu ändern. Eine solche Freischaltung erweitert den Umfang ausschließlich; sie kann nie etwas entfernen, was das Paket selbst bereits gewährt.

Der Lizenzabschnitt in den Einstellungen zeigt an, welche Funktionsbereiche die installierte Lizenz enthält. Die Frage „Warum ist die Server-zu-Server-Übertragung gesperrt?“ ist damit direkt auf der Seite beantwortet.

## 15. Funktionsstatus-Tabelle

| Funktion | Status |
|---|---|
| Export als `.tcmig`-Paket | Verfügbar |
| Import per Upload (Einzeldatei oder geteilt) | Verfügbar |
| Server-zu-Server-Übertragung (Modus B) | Nur Pro |
| Serialisierungssichere Host-Umschreibung | Verfügbar |
| Geheimnis-Mitnahme (APP_SECRET u.&nbsp;a., passphrase-verschlüsselt) | Verfügbar |
| Vorab-Prüfung von `composer.json` | Verfügbar |
| Kompatibilitätsprüfung (PHP/Contao-Version) | Verfügbar |
| Unbeaufsichtigter Fortschritt per Cron | Verfügbar |
| Eigenständiges Wiederherstellungs-Panel | Free und Pro |
| Lizenzverwaltung (Aktivieren/Aktualisieren/Entfernen) | Verfügbar |
| Kopplungstoken für Modus B erzeugen | Nur Pro |
| Funktionsdifferenzierung nach Lizenzpaket (Free vs. Pro) | Verfügbar |
| Frontend-Modul / Inhaltselement | Nicht zutreffend |

*Jede Funktion dieser Tabelle setzt eine aktivierte, gültige Lizenz voraus — auch die mit „Free und Pro“ gekennzeichneten (siehe Abschnitt 14). „Nur Pro“ bezeichnet Funktionen, die zusätzlich den Pro-Funktionsumfang erfordern.*

## 16. Sicherheitsmodell

- **Zugriffskontrolle:** Backend-Aktionen erfordern eine authentifizierte Contao-Backend-Sitzung hinter der Backend-Firewall sowie ein gültiges CSRF-Token.
- **Serverseitige Berechtigungsdurchsetzung:** Jede geschützte Operation (Backend-Aktionen, Auftrags-Ticks, Empfangs-Endpunkt, Wiederherstellungs-Panel) prüft unabhängig und erneut den authentifizierten Lizenzstatus, nicht nur einmal am äußeren Zugriffspunkt.
- **Authentifizierte Anfragen:** Ausgehende Lizenzaktivierung/-aktualisierung sowie der eingehende, serverseitig ausgelöste Lizenz-Update-Endpunkt sind über signierte Anfragen zu einem einzigen, fest hinterlegten HTTPS-Dienst abgesichert; Weiterleitungen werden abgelehnt, TLS-Prüfung bleibt aktiv.
- **Integrität und Authentizität:** Das Manifest jedes `.tcmig`-Pakets ist signiert, jeder Datenbank- und Dateiblock trägt eine Prüfsumme; der Import verifiziert beides vollständig, bevor ein destruktiver Schritt beginnt.
- **Private Ablage:** Betriebsdaten (Aufträge, Backups, Lizenzstatus, Betreiber-Token) liegen außerhalb des öffentlichen Web-Roots.
- **Sicheres Fehlverhalten:** Ein fehlender oder ungültiger Lizenznachweis, eine fehlgeschlagene Prüfsumme oder eine ungültige Signatur blockiert die Operation, statt stillschweigend fortzufahren; ein bestehender, bereits laufender Auftrag wird dabei nicht gelöscht, sondern pausiert automatisch weitergeführt, sobald die Lizenz wieder gültig ist.
- **Umgang mit Geheimnissen:** Die Export-Passphrase liegt ausschließlich in einer temporären, restriktiv berechtigten (0600) Zwischendatei — niemals im Auftragsdatensatz selbst — und wird nach Gebrauch gelöscht; mitgenommene Anwendungsgeheimnisse reisen verschlüsselt im Paket.
- **Geschwärzte Protokollierung:** Die projekteigene automatisierte Testsuite erzwingt, dass Lizenzschlüssel und vertrauliche Inhalte der Lizenzkommunikation niemals in Protokollen, Debug-Ausgaben oder der Browseransicht erscheinen.

## 17. Betriebssicherheit

Das eigenständige Wiederherstellungs-Panel (`_tcmig-recovery.php`) wird bei jedem Kernel-Start automatisch und idempotent in das Web-Root-Verzeichnis gespiegelt und funktioniert **unabhängig vom Contao-Backend** — etwa wenn eine versionsübergreifende Wiederherstellung eine vom Backend benötigte Tabelle noch nicht angelegt hat. Es authentifiziert über das Betreiber-Token und bietet: Statusanzeige, Auftrag antreiben, `contao:migrate` ausführen (inkl. erneuter Asset-Veröffentlichung), Kopplungstoken erzeugen, Passphrase nachreichen, Composer-Warnungen bestätigen, Auftrag abbrechen. Jede verändernde Aktion prüft denselben autoritativen Berechtigungsstatus wie das Backend-Modul und dabei genau die Stufe, die sie voraussetzt: Die Wiederherstellungs-Aktionen gehören zum Free-Umfang, das Erzeugen eines Kopplungstokens erfordert den Pro-Umfang. Der Status bleibt auch ohne gültige Lizenz lesbar, damit eine Störung diagnostizierbar bleibt; die steuernden Aktionen bleiben gesperrt.

Da der Token als URL-Parameter übertragen werden kann und damit in Zugriffsprotokollen landen kann, ist das Panel ausdrücklich als Notfallwerkzeug gedacht — bei Bedarf sollte das Token danach rotiert werden.

## 18. Laufzeitverzeichnisse

| Pfad | Inhalt |
|---|---|
| `var/migrator/` (konfigurierbar über `scratch_dir`) | Aufträge, Backups, Staging-Bereich, Schnappschüsse, eingehende Modus-B-Übertragungen, Bundle-Konfiguration sowie der private Berechtigungs- und Betriebszustand |
| `public/_tcmig-recovery.php` (bzw. `web/...`) | automatisch gespiegeltes, eigenständiges Wiederherstellungs-Panel |

## 19. Externe Kommunikation

Alle ausgehenden Verbindungen laufen ausschließlich über HTTPS zu **einem einzigen, fest im Code hinterlegten Zieldienst** (`https://www.v-t.one`):

| Zweck | Auslöser |
|---|---|
| Lizenzaktivierung / -aktualisierung | Bediener klickt „Aktivieren“ bzw. „Aktualisieren“ auf der Einstellungsseite |
| Nutzungssignal — übermittelt Projektname und Hostnamen, höchstens einmal je Anfrage | Aufruf des Backend-Moduls |
| Sitzungssignal — übermittelt Hostnamen und Lizenzschlüssel zur Lizenzzuordnung, höchstens einmal je authentifizierter Backend-Sitzung | Aufruf des Backend-Moduls bei gültiger Lizenz |

Zusätzlich kann V-T.ONE serverseitig eine signierte Lizenzaktualisierung **an** die Installation senden (eingehend, server-zu-server).

Beide Signale werden ausschließlich serverseitig gesendet; der Lizenzschlüssel erscheint zu keinem Zeitpunkt in der Browseransicht, in Protokollen oder in clientseitigem Code.

Bei einer Server-zu-Server-Übertragung (Modus B) kommuniziert die Quellinstallation ausschließlich mit der vom Bediener angegebenen **eigenen** Zielinstallation — kein Drittdienst ist daran beteiligt.

Übermittlung der Nutzungssignale ist bestmöglich („best effort“): Ein Fehlschlag wird stillschweigend verworfen und beeinflusst weder Lizenzierung noch Darstellung.

## 20. Protokollierung und Schwärzung vertraulicher Daten

Die Verarbeitung der Lizenzkommunikation ist bewusst ohne Protokollierung ausgeführt: Es existiert keine Stelle, an der vertrauliche Lizenzdaten in ein Protokoll gelangen könnten. Auftrags-Protokolle (im Backend-Monitor sichtbar) enthalten ausschließlich Fortschritts- und Fehlermeldungen zur Migration selbst, niemals Lizenzschlüssel oder vertrauliche Inhalte der Lizenzkommunikation. Die automatisierte Testsuite prüft diese Zusage bei jedem Durchlauf.

## 21. Deployment

Standard-Contao-5-Deployment über Composer/Contao Manager:

```bash
composer require vtinnovations/migrator
vendor/bin/contao-console cache:clear
```

Nach einem durchgeführten Import (siehe Abschnitt 12) ist zusätzlich zu prüfen, ob `composer install --no-dev` erforderlich ist (nicht nötig, wenn der Export mit „Fürs Migrations-Paket beheben“ erzeugt wurde).

## 22. Cache-Leerung

Die Import-Pipeline leert `var/cache` automatisch als letzten Schritt der Nachbereitung, damit der nächste Aufruf den Dependency-Injection-Container neu aufbaut. Für einen manuellen Cache-Leerungslauf außerhalb einer Migration gilt der reguläre Contao-Befehl:

```bash
vendor/bin/contao-console cache:clear
```

## 23. Tests

Das Projekt bringt eine PHPUnit-Testsuite mit (`phpunit.xml.dist`), aufrufbar über den in `composer.json` definierten Skript-Alias:

```bash
composer test
```

Zusätzliche Entwicklungswerkzeuge (nicht Teil des ausgelieferten Pakets, siehe `composer.json` → `archive.exclude`):

```bash
composer release-guard                              # Build-Wächter vor einer Veröffentlichung
php tools/verify-licence-surface.php /pfad/zum/projekt  # Laufzeit-Abnahme der Lizenzoberfläche in einem echten Contao-Kernel
```

## 24. Fehlerbehebung

- **Backend nach einer versionsübergreifenden Wiederherstellung nicht erreichbar:** das eigenständige Wiederherstellungs-Panel unter `/_tcmig-recovery.php?key=<Betreiber-Token>` nutzen (Token auch im Reiter „Wiederherstellung“ des Backend-Moduls sichtbar).
- **Import pausiert und fordert eine Passphrase:** Das Paket wurde beim Export mit einer Passphrase signiert — dieselbe Passphrase im Bestätigungsformular eingeben.
- **Import pausiert wegen Kompatibilitätswarnung:** PHP-Downgrade oder abweichende Contao-Hauptversion zwischen Quelle und Ziel — bewusst mit „Trotzdem fortfahren“ bestätigen oder die Zielumgebung anpassen.
- **`composer install` schlägt auf dem Ziel fehl:** Die Vorab-Prüfung der `composer.json` beim Export weist meist bereits auf die Ursache hin (lokale Pfad-/VCS-Repositories, `dev`-Versionsbindungen); alternativ den Export mit „Fürs Migrations-Paket beheben“ wiederholen.
- **Frontend nach dem Import ungestylt:** Kann auftreten, wenn `assets:install` auf dem Zielsystem nicht automatisch laufen konnte oder das Uploadverzeichnis nicht im Web-Root verlinkt werden konnte — beide Fälle werden im Auftragsprotokoll mit der nötigen manuellen Abhilfe vermerkt.
- **Migration kommt ohne geöffnetes Backend-Fenster nicht voran:** Contaos Web-Cron feuert nur bei Frontend-Zugriffen — einen System-Cron auf `contao:cron` einrichten (siehe Abschnitt 7).

## 25. Tatsächliche bekannte Einschränkungen

- Contaos eigener Web-Cron reicht für unbeaufsichtigten Fortschritt in der Regel nicht aus; ein echter System-Cron wird empfohlen.
- Die Datenbank-Dump-/Restore-Logik ist auf MySQL/MariaDB ausgelegt; andere Datenbankplattformen sind nicht das Zielszenario der produktiven Nutzung.
- `contao:migrate` wird nach einem Import bewusst nicht automatisch ausgeführt — ein reiner Host-Umzug soll exakt die Quellversionen beibehalten, nicht gleichzeitig aktualisieren; der Bediener führt diesen Schritt selbst aus, wenn später eine Contao-Aktualisierung ansteht.
- Composer-Repositories vom Typ „lokaler Pfad“ oder VCS sowie `dev`-Versionsbindungen können `composer install` auf dem Zielsystem verhindern; die Vorab-Prüfung warnt, entscheidet aber nicht automatisch.
- Die direkte Server-zu-Server-Übertragung erfordert die Fähigkeit auf der sendenden *und* der empfangenden Installation; eine Pro-Quelle kann nicht in ein Free-Ziel schieben.
- Der Funktionsumfang ergibt sich aus der signierten Lizenz. Ändert V-T.ONE den Umfang eines Pakets nachträglich, behält eine bereits aktivierte Lizenz ihren bisherigen Umfang, bis der Bediener „Lizenz aktualisieren“ ausführt oder V-T.ONE eine Aktualisierung zustellt.

## 26. Lizenz- und Urheberrechtsinformationen

- **Paket:** `vtinnovations/migrator`
- **Lizenz (Quellcode):** LGPL-3.0-or-later (siehe `composer.json` → `license`)
- **Urheber:** V&T Innovations Team
- **Website:** https://www.v-t.one

Die Nutzung der im Bundle implementierten Migrationsfunktion erfordert zusätzlich eine aktivierte V-T.ONE-Lizenz (siehe Abschnitt 14) — unabhängig von der Quellcode-Lizenz des Pakets selbst.

*Hinweis zu dieser Dokumentation:* `composer.json` definiert kein Feld `homepage` auf oberster Ebene; als Website wird daher die im Autoreneintrag hinterlegte und im Quellcode durchgängig verwendete Adresse `https://www.v-t.one` geführt.

## 27. Weiterführende Links

- [English version of this document](README.en.md)
