# Re-order visits — REDCap External Module

Sortiert die Instanzen wiederholbarer Events und wiederholbarer Instrumente nach
einem Datumsfeld um, damit Visiten chronologisch statt in Eingabereihenfolge
stehen.

Ursprünglich geschrieben von **Christof Meigen**
(christof.meigen@medizin.uni-leipzig.de, Universität Leipzig). Dieses Repository
enthält seine Originalfassung als ersten Commit; alle nachfolgenden Commits sind
Korrekturen und Ergänzungen. Was geändert wurde und warum, steht in
[`docs/Aenderungen.md`](docs/Aenderungen.md).

---

## Was das Modul tut

Ein Projekt-Link „Reorder Visits" zeigt einen Button. Ein Klick nummeriert für
**jeden** Record die Instanzen der betroffenen Wiederholungsgruppe neu durch,
sortiert nach dem konfigurierten Datumsfeld. Die Daten selbst werden nicht
verändert — nur die Spalte `instance` in der Datentabelle des Projekts.

Betroffen ist jeweils genau die Gruppe, zu der das Datumsfeld gehört:

- **Wiederholbares Instrument** → nur die Instanzen dieses Formulars
- **Wiederholbares Event** → alle Instanzen des Events, also alle Formulare darin

Andere Wiederholungsgruppen im selben Event bleiben unangetastet.

## Installation

```
/var/www/html/redcap/modules/revisits_v1.0.0/
```

Verzeichnisname muss `<prefix>_v<version>` folgen, Eigentümer `www-data:www-data`.
Danach im Control Center aktivieren, anschließend **pro Projekt** einschalten.

Nicht systemweit („Enable on all projects by default") aktivieren — das Modul ist
ein Admin-Werkzeug mit destruktiver Wirkung und sollte nur dort auftauchen, wo es
gebraucht wird.

Voraussetzung: REDCap ≥ 17.0.1 (wegen `framework-version: 17`) und eine Datenbank
mit Window Functions (MySQL ≥ 8.0, MariaDB ≥ 10.2).

## Konfiguration

Eine einzige Projekteinstellung: **Date field the reordering should be based on**.

Auswählbar sind nur Datums- und Datum/Zeit-Felder (`date_*`, `datetime_*`,
`datetime_seconds_*`), und davon nur solche, die zu einem wiederholbaren
Instrument oder Event gehören. Reine Zeitfelder, Zahlen und Text stehen bewusst
nicht zur Auswahl — siehe [Sortierung](#sortierung). Hinter jedem Feld steht seine
Wiederholungsgruppe, z. B. „Visits (repeating instrument, event Baseline)".

Die Einstellung ist wiederholbar: ein Datumsfeld **pro Wiederholungsgruppe**, etwa
eines für die Visiten und eines für ein wiederholbares Follow-up-Event. Ein Klick
sortiert dann alle Gruppen. Zwei Felder derselben Gruppe sind nicht möglich:

- Im Dialog werden in späteren Einträgen die Felder einer schon gewählten Gruppe
  ausgegraut. Wird ein früherer Eintrag nachträglich so geändert, dass ein späterer
  kollidiert, wird der spätere rot markiert.
- Beim Speichern lehnt das Modul eine solche Kombination mit Erklärung ab
  (`validateSettings()`).
- Eine vorher gespeicherte Doppelbelegung wird beim Lauf erkannt: Der spätere
  Eintrag wird mit Hinweis übersprungen.

Ausführen dürfen die Umsortierung nur **Superuser** und Nutzer mit
**Design-Rechten**, die **keiner Data Access Group** zugeordnet sind. Nur diese sehen
den Link, und die Seite prüft das bei jedem Aufruf selbst (siehe
[Sicherheit](#sicherheit)).

## Sortierung

Sortiert wird nach drei Kriterien, in dieser Reihenfolge:

1. Instanzen **mit** Datumswert vor Instanzen **ohne**
2. der Datumswert selbst, aufsteigend
3. bei Gleichstand die bisherige Instanznummer, aufsteigend

Kriterium 3 sorgt dafür, dass zwei Visiten am selben Tag ihre bisherige
Reihenfolge behalten und ein zweiter Lauf nichts mehr ändert (Idempotenz).

Der Vergleich ist eine reine Zeichenkettensortierung auf der `value`-Spalte. Das
funktioniert für Datumsfelder, weil REDCap sie intern **immer** als
`YYYY-MM-DD[ HH:MM[:SS]]` speichert, unabhängig vom Anzeigeformat (`date_dmy`,
`datetime_mdy` usw.) und normalisiert auf zwei Stellen (`9:15` → `09:15`).

Für andere Feldtypen wäre dieselbe Sortierung unsinnig — bei einem Zahlenfeld
käme `10` vor `2` vor `9`. Deshalb die Beschränkung auf Datumsfelder, doppelt
abgesichert über den Filter in der `config.json` und eine Prüfung zur Laufzeit.

## Logging

Jede Umsortierung wird zweifach protokolliert:

**Projekt-Log** (`redcap_log_event*`) — ein Eintrag pro Record und Event,
Ereignistyp `UPDATE`, Beschreibung „Reorder repeating instances", mit der
vollständigen Zuordnung in den Datenwerten:

```
Repeating instances reordered by 'visit_date':
1 -> 3, 2 -> 1, 3 -> 2
```

Sichtbar auf der Logging-Seite des Projekts und in der Historie des Records.

**Modul-Log** — ein Eintrag pro Lauf und Feld mit Feldname, Datentabelle, Anzahl
geänderter Zeilen und Gruppen sowie der Anzahl datumsloser Instanzen. Wird auch
geschrieben, wenn sich nichts geändert hat, damit das Auslösen des Werkzeugs an
sich nachvollziehbar bleibt.

**Bestehende Feldhistorie** — REDCap speichert die Instanz eines Logeintrags als
Textpräfix `[instance = N],` (Instanz 1 ohne Präfix). Nach der Umnummerierung
werden diese Präfixe in den bisherigen Dateneingabe-Einträgen der verschobenen
Instanzen auf die neue Nummer umgeschrieben, damit Logging-Seite und Feldhistorie
(das (H) neben einem Wert) zu den Daten passen. Dazu gehören neben Werteänderungen
auch Datei-Uploads und -Löschungen, die das (H) bei Upload-Feldern ebenfalls
anzeigt. Die eigenen „Reorder"-Einträge behalten bewusst die alte
Zuordnung, sie dokumentieren den Vorgang. Die Ausgabeseite meldet, wie viele
Einträge umgeschrieben wurden.

Das ist eine Entscheidung gegen ein unveränderliches Protokoll: Die Historie zeigt
danach die aktuelle Nummerierung, nicht die zum Zeitpunkt der Eingabe gültige.
Was vorher wo stand, bleibt über die „Reorder"-Einträge rekonstruierbar.

Bewusst unverändert bleiben:

- bei **wiederholbaren Instrumenten** die „Create record"-Einträge, die nur das
  Record-ID-Feld (das erste Feld des Projekts) enthalten. REDCap legt pro
  Instanznummer eine Datenzeile mit der Record-ID an und protokolliert genau das.
  Diese Zeile teilen sich alle wiederholbaren Formulare mit derselben Nummer; sie
  gehört zum ersten Formular und wird von der Umnummerierung nicht verschoben. Ihr
  Logeintrag passt deshalb weiterhin zu den Daten. Das (H) zeigt ihn ohnehin nicht
  an. Bei einem **wiederholbaren Event** wandert die Record-ID-Zeile dagegen mit
  der Instanz und ihr Eintrag mit ihr.
- **„Delete record"** (Löschen des ganzen Records, nicht instanzbezogen).

Nicht zuordenbar und deshalb ebenfalls unverändert sind Einträge, deren Felder aus
mehreren Formularen stammen; die Ausgabeseite meldet ihre Anzahl.

**Menü-Link relativ.** REDCap baut Links von External Modules absolut aus der
Basis-URL (`redcap_base_url`), eigene Links dagegen relativ. Ist ein Server unter
mehreren Adressen erreichbar, würde der Menüpunkt den Nutzer auf die Adresse der
Basis-URL umleiten, wo sein Session-Cookie nicht gilt, und REDCap verlangt einen
neuen Login. Das Modul gibt seinen Link deshalb ohne Schema und Host zurück
(`redcap_module_link_check_display()` in `Revisits.php`).

## Grenzen und Risiken

**Nicht rückgängig zu machen.** Das Log zeigt, was passiert ist, kann es aber
nicht zurücknehmen. Vor dem ersten Einsatz in einem produktiven Projekt: Backup.

**Instanznummern in anderen Tabellen** werden mit derselben Zuordnung
umnummeriert wie die Daten, damit alles, was an einer Visite hängt, mit ihr
wandert:

| Tabelle | Inhalt |
|---|---|
| `redcap_surveys_response` | Survey-Antworten, Rückkehr-Codes |
| `redcap_surveys_pdf_archive` | archivierte Survey-/eConsent-PDFs |
| `redcap_locking_data` | Formularsperren |
| `redcap_esignatures` | E-Signaturen |
| `redcap_data_quality_status` | Feldkommentare, Data Queries, Regelergebnisse |
| `redcap_edocs_data_mapping` | Zuordnung hochgeladener Dateien |
| `redcap_alerts_sent`, `redcap_alerts_recurrence` | gesendete und wiederkehrende Alerts |
| `redcap_surveys_scheduler_queue` | geplante und gesendete Survey-Einladungen |
| `redcap_outgoing_email_sms_log` | Versandprotokoll |

Bei wiederholbaren Instrumenten nur die Zeilen des umsortierten Formulars, bei
wiederholbaren Events alle Formulare des Events. Sperren und E-Signaturen wandern
mit, weil sich die Werte selbst nicht ändern — eine Signatur bescheinigt danach
weiterhin dieselben Werte, nur unter der neuen Nummer (Nutzerentscheidung
2026-10-14). Nicht berücksichtigt: `redcap_project_migration_files`
(Migrationshilfe) und `redcap_ehr_resource_instances` (nur bei EHR-Anbindung).
`redcap_surveys_scheduler` hat eine Spalte `instance`, die aber eine Einstellung
ist, keine Instanznummer.

**Alles oder nichts, pro Datumsfeld.** Daten, Nachbartabellen, Projekt-Log und
Feldhistorie werden in einer Datenbank-Transaktion umnummeriert. Bricht ein Lauf
ab, bleibt für dieses Datumsfeld alles beim Alten, die Ausgabe meldet „nothing was
changed for this field". Mehrere konfigurierte Datumsfelder laufen in getrennten
Transaktionen nacheinander. Bricht die Datenbank die Transaktion von sich aus ab,
etwa bei einem Deadlock durch gleichzeitiges Speichern desselben Records, erkennt
das Modul das vor dem Festschreiben und meldet, dass der Lauf wiederholt werden
muss.

**Feste Instanznummern in der Projektlogik.** Bezüge wie `[visit_date][1]` in
berechneten Feldern, Branching, Piping, Alert- oder Einladungsbedingungen sowie
Reports mit Instanzfilter zeigen nach dem Umsortieren auf eine andere Visite. Das
kann das Modul nicht reparieren. `[first-instance]`/`[last-instance]` sind
unkritisch, sie meinen danach die chronologisch erste bzw. letzte. Dasselbe gilt
für bereits exportierte Daten und Auswertungen außerhalb REDCaps.

**Vor der Aktivierung in einem Projekt klären:**

- Gibt es Bezüge auf feste Instanznummern (siehe oben)?
- Wurden schon Daten mit Instanznummern exportiert, auf die sich jemand stützt?
- Backup vorhanden? Die Umsortierung lässt sich nicht automatisch rückgängig
  machen.

Das Modul ist eine Speziallösung für Projekte, in denen Visiten nicht in
zeitlicher Reihenfolge erfasst wurden — nicht systemweit aktivieren.

**Instanzen ohne Datumswert** werden ans Ende sortiert und behalten
untereinander ihre Reihenfolge. Die Ausgabe meldet, wie viele das waren. Eine
halb ausgefüllte Visite bekommt dadurch eine neue Nummer, die fachlich nichts
bedeutet.

## Sicherheit

Sicherheitsreview vom 2026-11-01 (REDCap 17.2.1). Alle Punkte wurden am
Framework-Quellcode und mit echten HTTP-Aufrufen auf dem Testserver geprüft.
Einzelheiten stehen in [`docs/Aenderungen.md`](docs/Aenderungen.md), Abschnitt 11.

**Angriffsfläche.** Es gibt nur die Projektseite `reorder` (GET zeigt den Button,
POST führt aus), dazu den Konfigurationsdialog. Das Modul hat keine Seiten ohne
Login, keine AJAX-Endpunkte, keinen Cron und keine API.

**Wer darf ausführen?** Superuser sowie Nutzer mit Design-Rechten ohne
DAG-Zuordnung und mit nicht abgelaufenem Projektzugang. Die Prüfung steht in
`Revisits::userMayReorder()` und gilt für den Menülink und für jeden Seitenaufruf.
Kann sie die Rechte nicht ermitteln, verweigert sie.

- *Warum eine eigene Prüfung:* Die Seitenprüfung des Frameworks greift nur, wenn
  der Parameter `page` exakt dem Link aus `config.json` entspricht. Mit
  `page=./reorder` erreichte ein Nutzer mit reinen Leserechten die Seite und konnte
  die Umsortierung auslösen (vor der Korrektur reproduziert).
- *Warum keine DAG-Nutzer:* Ein Lauf umfasst immer alle Records des Projekts, auch
  die anderer DAGs.
- Die Rechte werden über `UserRights::getPrivileges()` mit kleingeschriebenem
  Benutzernamen gelesen. `getRights()` liefert für Namen mit Großbuchstaben `null`.

**Eingaben und SQL.** Alle Werte gehen als Parameter in die Queries. Die
Tabellennamen kommen von REDCap (`getDataTable()`, `getLogEventTable()`) oder aus
einer festen Liste im Code. Instanznummern werden als Ganzzahlen verarbeitet.
CSRF-Schutz übernimmt das Framework (`framework-version: 17`).

**Ausgabe.** Alles, was die Seite anzeigt, geht durch `htmlspecialchars`, auch
Feldnamen aus der Einstellung, denn REDCap prüft gespeicherte Einstellungswerte
nicht gegen die Auswahlliste. Werte im `<script>` des Konfigurationsdialogs werden
mit `JSON_HEX_TAG|AMP|APOS|QUOT` eingebettet.

**Daten im Browser.** Die Seite zeigt nur Zahlen (umgestellte Felder,
Log-Einträge) und Feldnamen. Das Dialog-Skript enthält Feld- und Gruppennamen und
wird nur auf der Modulverwaltungsseite ausgeliefert, die selbst Design-Rechte
verlangt.

**Logging.** Jeder Lauf wird im Projekt-Log festgehalten (ein Eintrag pro Record
und Event, mit der alten und neuen Nummerierung, unter dem ausführenden
Benutzer). Zusätzlich gibt es einen Eintrag pro Datumsfeld im Modul-Log.

**Restrisiken**

- **Das Audit-Log wird verändert.** Die Feldhistorie (`data_values` in
  `redcap_log_event*`) wird auf die neuen Instanznummern umgeschrieben (siehe
  [Logging](#logging)). Der ursprüngliche Zustand lässt sich aus den
  „Reorder"-Einträgen rekonstruieren, steht aber nicht mehr wörtlich im Log. In
  Studien unter GCP oder 21 CFR Part 11 vor der Aktivierung mit der
  Studienleitung klären.
- **Feste Instanzbezüge** zeigen danach auf andere Visiten (siehe
  [Grenzen und Risiken](#grenzen-und-risiken)).
- **Fehlermeldungen der Datenbank** werden berechtigten Nutzern angezeigt (maskiert)
  und können Tabellen- oder Spaltennamen enthalten.
- **Nicht rückgängig zu machen.** Wer Design-Rechte hat, kann die Umsortierung
  auslösen. Design-Rechte deshalb nur gezielt vergeben.

**Testfälle** (Testprojekt mit je einem Nutzer pro Rechtestufe, Seitenaufruf mit
`reorder`, `reorder.php`, `./reorder`, `.//reorder`,
`../revisits_v1.0.0/reorder`, Menülink und POST):

| Nutzer | Ergebnis |
|---|---|
| nur Leserechte, kein Design | überall verweigert, kein Link |
| Design, aber DAG-zugeordnet | überall verweigert, kein Link |
| Design, keine DAG | Zugriff, Link, Lauf ausgeführt |
| Design, Benutzername mit Großbuchstaben | Zugriff, Link, Lauf ausgeführt |
| Einstellung mit `<img src=x onerror=…>` | nur maskiert ausgegeben (GET und POST) |

## Entwicklung

- [`docs/Aenderungen.md`](docs/Aenderungen.md) — was gegenüber der Originalfassung
  geändert wurde, mit Begründung und Nachweis
- [`docs/Technik.md`](docs/Technik.md) — wie die Umsortierung funktioniert,
  REDCap-Interna (Sharding, Wiederholungsgruppen, Log-Tabellen), Testvorgehen

## Lizenz

MIT-Lizenz, siehe [`LICENSE`](LICENSE). Originalfassung: Christof Meigen,
Universität Leipzig; nachfolgende Änderungen: Marcel von Borzestowski.
