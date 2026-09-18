# Änderungen gegenüber der Originalfassung

Ausgangspunkt ist `revisits_redcap_module.zip` von Christof Meigen (Dateidatum
2025-08-04), unverändert als erster Commit dieses Repositories. Alle Befunde
wurden auf dem Testserver (REDCap 17.2.1, MariaDB 11.8.6) reproduziert, bevor sie
behoben wurden, und nach der Korrektur gegengeprüft.

Reihenfolge der Abschnitte = Reihenfolge der Commits.

---

## 1. Hart codierte Datentabelle `redcap_data`

**Befund.** Das UPDATE schrieb unbedingt auf `redcap_data`. REDCap verteilt
Projektdaten aber über mehrere gleich aufgebaute Tabellen (`redcap_data`,
`redcap_data2` … `redcap_data8` im Auslieferungszustand von REDCap 17); welche
ein Projekt nutzt, steht in `redcap_projects.data_table` und wird bei der
Projektanlage durch `Records::getSmallestDataTable()` festgelegt.

**Auswirkung.** Für jedes Projekt, das nicht in `redcap_data` liegt, traf das
UPDATE null Zeilen — ohne Fehlermeldung. Die Seite meldete `0`. Auf dem
Testserver betraf das alle Projekte ab PID 16.

Gefährlich war es nicht: `project_id` steht in der WHERE-Klausel, ein fremdes
Projekt konnte nicht getroffen werden.

**Korrektur.** Tabellenname zur Laufzeit über `\REDCap::getDataTable($pid)`.
Tabellenalias `d`, weil die alten Qualifier `redcap_data.spalte` mit variablem
Namen nicht funktionieren. Dabei außerdem `global $conn` und
`mysqli_real_escape_string()` durch parametrisierte Framework-Queries ersetzt.

**Nachweis.** Projekt 17 (liegt in `redcap_data2`), identische Ausgangsdaten:

```
Originalcode:  dat_wiederholung: 0 // Rows matched: 0  Changed: 0
Korrigiert:    Data table used for this project: redcap_data2
               dat_wiederholung: 15    -> korrekt sortiert
```

**Merksatz.** Rohes SQL auf `redcap_data*` braucht immer
`\REDCap::getDataTable($pid)`; für „alle Tabellen" `Records::getDataTables()`.
Dasselbe gilt für das Log (`\REDCap::getLogEventTable($pid)`). Wer über
`REDCap::getData()`/`saveData()` arbeitet, ist automatisch korrekt.

---

## 2. Instanzen ohne Datumswert

**Befund.** Die Instanzen wurden aus den Zeilen des Datumsfelds selbst
aufgezählt (`rd.field_name = '<datumsfeld>'`). Eine Instanz mit leerem Datum hat
dort keine Zeile, tauchte in der Window Function nicht auf, behielt ihre alte
Nummer — und kollidierte mit einer Instanz, die auf dieselbe Nummer verschoben
wurde.

**Auswirkung.** Datenverlust, und zwar still. Die `redcap_data*`-Tabellen haben
keinen Unique-Key (Auslieferungszustand, siehe `Resources/sql/install.sql`), die
Datenbank akzeptiert die Kollision also kommentarlos. Reproduziert mit einem
Record aus drei Instanzen, mittlere ohne Datum:

```
inst=2  other_visit_data   inst1 / September
inst=2  other_visit_data   inst2 / OHNE Datum     <- doppelte Zeile
inst=2  weight             70
inst=2  weight             71                     <- doppelte Zeile
inst=3  record_id          3                      <- verwaiste Zeile
```

**Korrektur.** Die Aufzählung ist vom Datumsfeld entkoppelt: erst alle
existierenden Instanzen der Wiederholungsgruppe, dann das Datum per `LEFT JOIN`
daneben. Die Nummerierung ist damit immer eine vollständige Permutation.
Datumslose Instanzen werden ans Ende sortiert und in der Ausgabe gemeldet.

Zwei Details, die das erzwang:

- **Bereichsprüfung.** Ohne das Datumsfeld als Filter würde die Aufzählung jede
  Wiederholungsgruppe des Projekts erfassen. Ein Join auf das Formular des
  Datumsfelds grenzt ein: bei einem wiederholbaren Instrument muss
  `er.form_name` dessen Formular sein, bei einem wiederholbaren Event muss das
  Formular laut `redcap_events_forms` im Event liegen. **`redcap_events_forms`
  ist bei klassischen Projekten leer** und darf deshalb nur im Event-Zweig
  abgefragt werden.
- **Stabiler Tiebreak.** `coalesce(instance, 1)` als letztes Sortierkriterium.
  Ohne das hing die Reihenfolge bei gleichem Datum von der Speicherreihenfolge
  ab und konnte sich zwischen zwei Läufen unterscheiden.

**Nachweis.**

```
record  inst  datum        label                  Zeilen
3       1     2026-06-06   H / Juni               5
3       2     2026-09-09   F / September          5
3       3     (leer)       G / OHNE Datum         4     <- vollständig, keine Dubletten
4       1     2026-01-01   K / Januar
4       2     2026-02-02   I / 02.02. (erste)           <- gleiches Datum,
4       3     2026-02-02   J / 02.02. (zweite)             alte Reihenfolge erhalten
```

Abgrenzung geprüft: drei `medications`-Instanzen im selben Event, ohne
konfiguriertes Datumsfeld, blieben unverändert. Zweiter Lauf: 0 geänderte Zeilen.

---

## 3. Fehlende `framework-version` → kein CSRF-Schutz

**Befund.** Ohne `framework-version` in der `config.json` fällt das Framework auf
Version 1 zurück (`ExternalModules::getFrameworkVersionForConfig()`).
`checkCSRFToken()` steigt unterhalb von `CSRF_MIN_FRAMEWORK_VERSION = 8` sofort
aus.

**Auswirkung.** Ein eingeloggter Nutzer mit Design-Rechten, der eine präparierte
fremde Seite öffnet, hätte per untergeschobenem POST die Umsortierung ausgelöst —
eine Datenänderung, die damals weder protokolliert noch umkehrbar war.

**Korrektur.** `"framework-version": 17` (höchste von REDCap 17.2.1 unterstützte
Version, verlangt ≥ 17.0.1). Mitgeschleppte Breaking Changes:

| Version | Änderung | Auswirkung hier |
|---|---|---|
| v3 | Link-Icons sind Font-Awesome-Klassen | `"report"` → `"fa-solid fa-arrow-down-1-9"` |
| v4 | `db_affected_rows()` funktioniert nicht mit Parametern | UPDATE über ein `Query`-Objekt, `$query->affected_rows` |
| v5 | Framework-Methoden direkt am Modulobjekt | `$module->getDataTable()` statt `method_exists`-Fallback |
| v12 | `permissions`-Sektion muss weg | nicht vorhanden |
| v16/17 | Twig | nicht genutzt |

Das CSRF-Feld wird explizit gesetzt, obwohl `base.js` auf Seiten mit REDCap-Header
ohnehin eines an jedes Formular hängt (`appendCsrfTokenToForm()`, aufgerufen in
`System.php`; `redcap_connect.php` bildet `redcap_csrf_token` auf den Namen ab,
den das Framework prüft).

**Nachweis.** POST ohne Token gegen `ExternalModules::checkCSRFToken()`:

```
framework-version  1: wird DURCHGELASSEN
framework-version 17: ABGEWIESEN
```

**Rückfallebene.** Framework 17 hat noch kein LTS-Release. Sollte die Zielinstanz
auf einen LTS-Build wechseln, ist `"framework-version": 16` (ab 14.6.4 Standard /
15.0.9 LTS) der Ersatz — reine Konfigurationsänderung, das Modul nutzt keine
versionsspezifischen Funktionen.

---

## 4. Beschränkung auf Datumsfelder

**Befund.** Das `field-list`-Dropdown bot jedes Feld des Projekts an, und
sortiert wird als Zeichenkette.

**Auswirkung.** Mit einem Zahlenfeld (`dosage`, float) nachgestellt:

```
vorher                     nachher
inst 1  Zolpidem    9      inst 1  Aspirin    10
inst 2  Aspirin    10      inst 2  Ibuprofen   2
inst 3  Metformin   9      inst 3  Zolpidem    9
inst 4  Ibuprofen   2      inst 4  Metformin   9
```

`10` vor `2` vor `9`. Und „gleicher Wert" heißt byte-gleicher String: `09`, `9`
und `9.0` speichert REDCap unverändert und sortiert sie als drei verschiedene
Werte (`09 < 9 < 9.0`).

**Korrektur.** `"validation": "date"` in der `config.json`. Das Framework macht
daraus `element_validation_type LIKE 'date_%'`; weil `_` in SQL ein Platzhalter
für ein einzelnes Zeichen ist, erfasst der Filter `date_*`, `datetime_*` und
`datetime_seconds_*` und schließt reine Zeitfelder aus. Zusätzlich eine Prüfung
zur Laufzeit, weil ein vorher gespeichertes Setting nie erneut validiert wird.
Übersprungen wird pro Feld mit Meldung, die übrigen laufen weiter.

**Nachweis.**

```
dosage (float)                 -> "not a date or datetime field (validation type 'float') - skipped"
aesttim (time)                 -> "not a date or datetime field (validation type 'time') - skipped"
gibtsnicht                     -> "field does not exist in this project - skipped"
visit_date + dosage            -> visit_date verarbeitet, dosage übersprungen
wel_favoriteday (datetime_mdy) -> akzeptiert
```

**Warum Zeichenkettensortierung bei Datumsfeldern trotzdem stimmt.** REDCap
speichert intern immer `YYYY-MM-DD[ HH:MM[:SS]]`, unabhängig vom Anzeigeformat,
und normalisiert einstellige Stunden. Geprüft, weil die Validierungs-Regex sie
zulässt (`(\d|[0-1]\d|[2][0-3]):[0-5]\d`): `9:15` wird als `09:15` gespeichert,
sowohl bei `datetime_*` als auch bei `time`.

---

## 5. Kein Logging

**Befund.** Die Umsortierung hinterließ keine Spur; die Startseite des Moduls
wies selbst darauf hin.

**Korrektur.** Zwei Ebenen, siehe README. Umsetzungsdetails:

- Die alte Nummerierung muss **vor** dem UPDATE gelesen werden. Damit Log und
  tatsächliche Änderung nicht auseinanderlaufen, liegt die Nummerierungslogik in
  einer Variablen (`$numbered_sql`), die beide Abfragen wörtlich verwenden.
- `Logging::logEvent()` ermittelt die Log-Tabelle des Projekts selbst — das
  Sharding der Log-Tabellen (`redcap_log_event` … `redcap_log_event15`) erledigt
  REDCap. Bestätigt: Einträge für Projekt 17 landen in `redcap_log_event11`.
- Ereignistyp `UPDATE` (einziger passender Wert des Enums), `object_type` = die
  tatsächlich benutzte Datentabelle, `pk` = Record, `event_id` = Event. Die
  Spalte `description` ist `varchar(100)`, Details gehören nach `data_values`.

**Dabei gefundener Fehler.** Der erste Durchlauf schrieb `rows_affected 0` ins
Modul-Log, obwohl 20 Zeilen geändert wurden. `Query::affected_rows` ruft bei
jedem Zugriff `db_affected_rows()` auf und liefert damit die Zeilenzahl der
**zuletzt** ausgeführten Abfrage — zwischen UPDATE und `$module->log()` liegen
aber die Log-INSERTs. Der Wert wird jetzt direkt nach `execute()` festgehalten.

**Nachweis.**

```
Projekt-Log (redcap_log_event, pid 12)
  event=UPDATE  object=redcap_data  record=1  event_id=39
  Beschreibung: Reorder repeating instances
  Werte:        Repeating instances reordered by 'visit_date': | 1 -> 3, 2 -> 1, 3 -> 2

Modul-Log
  "Reorder repeating instances"
    data_table redcap_data | field visit_date | groups_changed 2
    rows_affected 20 | instances_without_date 1
```

Zweiter Lauf hintereinander: `0 record/event group(s) written to the project log`.

---

## Bewusst nicht geändert

**Umstellung von SQL auf `REDCap::saveData()`.** Geprüft und verworfen. `instance`
ist in REDCap eine Adresse, kein Wert — die API kann „verschiebe Instanz 3 auf 1"
nicht ausdrücken, man müsste sämtliche Nutzdaten neu schreiben. Dabei:
`$skipFileUploadFields` steht in `Records::saveData()` per Default auf `true`,
Datei-Uploads blieben also auf den alten Nummern liegen; `$removeLockedFields`
steht auf `false`, gesperrte Instrumente würden überschrieben; ein Abbruch
mittendrin hinterließe eine halb angewandte Permutation, während das SQL-UPDATE
pro Feld atomar ist. Die Tabellen aus der Liste in der README würde die API
ebenso wenig mitziehen.

**Numerische Sortierung für Zahlenfelder.** Das würde den Zweck des Moduls von
„Visiten chronologisch" auf „beliebig sortieren" ausweiten. Stattdessen die
Beschränkung auf Datumsfelder.

## Offen

- Keine Transaktionsklammer um Log-Abfrage und UPDATE bzw. um mehrere
  konfigurierte Datumsfelder.
- Die Hinweiszeile zu datumslosen Instanzen erscheint auch bei einem Lauf ohne
  Änderungen — sie beschreibt den Zustand, nicht die Aktion.
- Die zwölf Tabellen mit `instance`-Spalte werden nicht mitgezogen; aktuell nur
  als Einsatzgrenze dokumentiert.
