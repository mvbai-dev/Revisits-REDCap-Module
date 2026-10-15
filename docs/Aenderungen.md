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

## 6. Feldhistorie zeigte nach dem Umsortieren die alten Instanznummern

**Befund.** Logging-Seite und Feldhistorie lesen `redcap_log_event*`. Die Instanz
steht dort nicht in einer Spalte, sondern als Textpräfix von `data_values`:
`[instance = N],\n`, bei Instanz 1 gar kein Präfix (`Logging::logEvent()`). Die
Umnummerierung ändert nur `instance` in der Datentabelle.

**Auswirkung.** Nach einem Lauf ordnete die Historie alte Werte der falschen
Instanz zu. Keine Daten betroffen, aber irreführend.

**Korrektur.** Nach dem UPDATE werden die Präfixe der Dateneingabe-Einträge auf
die neue Nummer umgeschrieben (Nutzerentscheidung 2026-10-14, siehe README zur
Abwägung gegen ein unveränderliches Protokoll). Details:

- **Erst sammeln, dann schreiben.** Paarweises Umschreiben verkettet sich innerhalb
  einer Rotation: Ein Eintrag, der von 1 auf 2 gesetzt wurde, würde von `2 -> 3`
  erneut erfasst und von `3 -> 1` ein drittes Mal. So stand in einer ersten Fassung
  am Ende jeder Eintrag auf Instanz 1.
- **Nur Dateneingabe-Einträge:** `event` INSERT/UPDATE/DELETE sowie DOC_UPLOAD
  und DOC_DELETE, `object_type` `redcap_data*` — REDCap schreibt dort auch bei
  PID 17 `redcap_data`, obwohl die Daten in `redcap_data2` liegen. Die eigenen
  „Reorder"-Einträge sind ausgenommen; das (H)-Popup zeigt sie ohnehin nicht an.
- **Datei-Uploads:** `Form::getDataHistoryLog()` liest für Upload-Felder auch
  `DOC_UPLOAD`- und `DOC_DELETE`-Einträge, und beide tragen das Instanzpräfix
  (`DataEntry/file_upload.php`, `file_delete.php` übergeben `$_GET['instance']`).
  Eine Löschung wird als bloßer Feldname ohne `= Wert` geloggt. In einer ersten
  Fassung fehlten beide Ereignistypen; der Test unten schlug damit an fünf
  Upload-/Lösch-Einträgen fehl, die Datei-Zuordnung in den Daten selbst wanderte
  dagegen korrekt mit.
- **Wiederholbares Event:** alle Formulare teilen eine Instanzachse, jeder Eintrag
  des Events wandert mit. Ausnahme „Delete record" (ganzer Record).
- **Wiederholbares Instrument:** Ein Event kann mehrere unabhängig nummerierte
  wiederholbare Formulare enthalten (PID 12: `visits`, `medications`,
  `adverse_events`, `family_members`). Umgeschrieben wird nur, wenn die geloggten
  Felder zum umsortierten Formular gehören. Stammen sie aus mehreren Formularen,
  bleibt der Eintrag unverändert und wird als nicht zuordenbar gemeldet.
- **Record-ID-Feld** (das erste Feld des Projekts, nicht zwingend `record_id`) zählt
  bei dieser Prüfung nicht mit. REDCap legt pro Record, Event und Instanznummer eine
  Datenzeile mit der Record-ID an und loggt das als „Create record" ohne weiteren
  Inhalt (`Records::saveData()`, Classes/Records.php:7219–7244). Bei wiederholbaren
  Instrumenten teilen sich alle Formulare mit derselben Nummer diese Zeile: In
  Record 708 hat die Februar-Visite (Instanz 2) einen solchen Eintrag (251),
  Medikament B, ebenfalls Instanz 2, keinen eigenen (255). Die Zeile gehört zum
  ersten Formular, die Umnummerierung verschiebt sie nicht, und da nur vorhandene
  Nummern getauscht werden, behält jede Nummer ihre Zeile. Der Logeintrag bleibt
  deshalb bewusst stehen und passt weiter zu den Daten. Zuordnen ließe er sich
  über den unmittelbar folgenden Eintrag (gleicher Zeitstempel, Benutzer und
  Instanz, ID +1 — auf dem Testserver bei allen 64 solchen Zeilen der Fall), aber
  dann widerspräche das Log den Daten. Bei wiederholbaren Events wandert die
  Record-ID-Zeile mit der Instanz, ihr Eintrag ebenso.
- In einer ersten Fassung war das Record-ID-Feld fest als `record_id` codiert und
  hätte in Projekten mit anderem Feldnamen (z. B. `study_id`) nicht gegriffen.
- Checkbox-Werte werden als `feld(code) = checked` geloggt; das Muster für
  Feldnamen berücksichtigt das.

**Nachweis.** Testskript mit frischen Records, Instanzen einzeln und absichtlich
unsortiert über `REDCap::saveData()` angelegt (also mit echten Logeinträgen), dazu
eine spätere Änderung an einer Instanz. Drei Runden: erste Umsortierung, Datumswerte
über den normalen Speicherweg geändert und erneut umsortiert, dritter Lauf ohne
Änderung. Die erwartete Zuordnung leitet das Skript aus eindeutigen Beschriftungen
ab, unabhängig vom Modul; geprüft werden Daten und jeder vorher vorhandene
Logeintrag.

```
PID 12 (wiederholbares Instrument), Record 702
  Runde 1  Zuordnung 1->3, 2->1, 3->2   4 umgeschrieben, 2 Record-ID-Einträge belassen
  Runde 2  Zuordnung 1->4, 4->1         5 umgeschrieben, 1 Record-ID-Eintrag belassen
  Runde 3  keine Änderung               0
  medications-Einträge mit gleichen Instanznummern: in allen Runden unverändert

PID 17 (wiederholbares Event), Record 704
  Runde 1  Zuordnung 1->3, 2->1, 3->2   12 umgeschrieben
  Runde 2  Zuordnung 1->4, 4->1         11 umgeschrieben
  Runde 3  keine Änderung               0
  Einträge aus Event 44 (nicht wiederholt): unverändert
```

Datei-Uploads, PID 12 mit Test-Upload-Feld `visit_document` im Formular „visits",
Uploads auf drei Instanzen, auf einer davon Upload, Löschung, erneuter Upload
(Log-Einträge mit denselben Aufrufen wie REDCaps Upload-/Löschseite):

```
ohne DOC_UPLOAD/DOC_DELETE, Record 706   6 Prüfungen fehlgeschlagen (alle Upload-Einträge)
mit,                         Record 707   Runde 1: 9 umgeschrieben, Runde 2: 8, Runde 3: 0
                                          alle Prüfungen bestanden
```

Alle Prüfungen bestanden, ebenso mit den Records 701 (PID 12) und 703 (PID 17)
in einer Vorfassung. Bei 703 fiel dabei auf, dass eine „Create record"-Zeile, die
in Runde 1 auf Instanz 1 rückte und damit ihr Präfix verlor, in Runde 2 nicht mehr
mitwanderte; daraufhin werden reine Record-ID-Einträge im Event-Fall immer
mitgezogen (außer „Delete record").

---

## 7. Instanznummern in anderen Tabellen, Transaktion

**Befund.** Neben `redcap_data*` und dem Log haben auf REDCap 17.2.1 dreizehn
Tabellen eine Spalte `instance` (`information_schema`). Zehn davon gehören zu den
Daten einer Instanz: Survey-Antworten, Survey-PDF-Archiv, Sperren, E-Signaturen,
Feldkommentare/Data Queries, Datei-Zuordnung, gesendete und wiederkehrende Alerts,
Einladungs-Warteschlange und Versandprotokoll. Die Umnummerierung ließ sie auf der
alten Nummer. Außerdem liefen UPDATE, Projekt-Log und Feldhistorie ohne
Transaktion; ein Abbruch mittendrin hinterließ einen Teilzustand.

**Auswirkung.** Nach einem Lauf gehörten Sperre, Signatur, Survey-Antwort oder
Alert-Versand einer anderen Visite; ein Alert konnte für eine Instanz als „schon
gesendet" gelten, deren Inhalt er nie gesehen hat. Die Datei-Zuordnung prüft REDCap
beim Absenden eines Formulars (`DataEntry::addEdocDataMapping()`).

**Korrektur.**

- **Eine Zuordnung für alles.** Die Alt→Neu-Paare werden einmal berechnet und in
  eine temporäre Tabelle `revisits_moves` geschrieben; Daten und alle
  Nachbartabellen werden daraus umnummeriert. (Das ist keine Korrektur am
  UPDATE-Verfahren selbst, siehe „Bewusst nicht geändert": Es geht darum, dass zehn
  Tabellen garantiert dieselbe Zuordnung bekommen.)
- **Zuordnung zum Formular je Tabelle:** Sperren und E-Signaturen über
  `form_name`; Feldkommentare über `repeat_instrument`, sonst über das Formular
  des Felds; Datei-Zuordnung über das Feld; Survey-Antworten über Teilnehmer →
  Survey; PDF-Archiv über den Survey; Alerts und Versandprotokoll über
  `instrument`; Einladungs-Warteschlange über Empfänger → Teilnehmer → Survey.
  Instanz 1 steht in diesen Tabellen als `1`, nicht als `NULL`.
- **Zweistufig über negative Zwischenwerte.** Die meisten Tabellen haben einen
  Unique-Key über Formular/Record/Instanz; ein Tausch 1↔2 in einem UPDATE
  scheitert sonst mit „Duplicate entry". Erst `instance = -neu`, dann Vorzeichen
  umdrehen. Für die Daten genauso, damit das Ergebnis nicht von der
  Verarbeitungsreihenfolge abhängt.
- **Transaktion pro Datumsfeld.** Alles Lesende (Zuordnung, geplante
  Feldhistorie) vor `start transaction`: REDCap kann reine SELECTs an eine
  Lese-Replik schicken, die eigene, noch nicht festgeschriebene Änderungen nicht
  sieht. Danach Daten, Nachbartabellen, Projekt-Log, Feldhistorie, `commit`; bei
  jedem Fehler `rollback`. Weder `Logging::logEvent()` noch die übrigen benutzten
  Funktionen committen selbst.

**Nachweis.** Eigenes Testprojekt (PID 35, „Revisits Test (Tabellen)",
longitudinal, Surveys): Event „Baseline" mit den wiederholbaren Instrumenten
`visits` (Datum, Text, Datei, Checkbox, als Survey) und `medications`, Event
„Follow-up" als wiederholbares Event mit zwei Formularen. Pro Test-Record 33
Zeilen in allen zehn Tabellen, eingefügt wie REDCap sie schreibt
(`Locking/single_form_action.php`, `DataQuality/data_resolution_popup.php`,
`DataEntry::addEdocDataMapping()`, `Alerts.php`, `Message.php`); Survey-Teilnehmer
und -Antworten über `REDCap::getSurveyLink()`. Alle drei visits-Instanzen gesperrt
und mit Alert-Versand, damit der Unique-Key beim Tausch greift; medications mit
denselben Nummern als Kontrolle. Erwartung pro Zeile beim Anlegen festgelegt,
Zuordnung aus den Daten abgeleitet.

```
Record 4   Runde 1  visits 1->3, 2->1, 3->2, followup ebenso   28 von 33 verschoben
           Runde 2  Datum geändert, erneute Rotation           28 von 33 verschoben
           Runde 3  keine Änderung                              0
           die 5 medications-Zeilen: in keiner Runde verschoben
           alle Prüfungen bestanden
```

Abbruch: Trigger auf `redcap_alerts_sent`, der für Record 6 beim Umnummerieren
einen Fehler auslöst — also nachdem Daten, Sperren, Signaturen, Kommentare,
Datei-Zuordnung, Survey-Antworten und PDF-Archiv schon umnummeriert waren:

```
mit Trigger    Daten 0 / Log 0 / Nachbartabellen 0 von 33 Zeilen verändert,
               Ausgabe "nothing was changed for this field"
ohne Trigger   derselbe Record vollständig umsortiert
```

Ein erster Versuch mit Record 5 war ungültig: Der Trigger wurde wegen fehlendem
`DELIMITER` gar nicht angelegt, der Lauf war ein normaler.

Die Regressionstests aus Abschnitt 6 (Feldhistorie, PID 17) bestehen weiterhin;
in PID 35 passen die Logeinträge von Record 4 zu den Instanzen, auch der
Checkbox-Eintrag.

---

## 8. Login-Aufforderung beim Menüpunkt „Reorder Visits"

**Befund.** Nutzer auf dem Testserver wurden beim Klick auf den Menüpunkt zum
Login aufgefordert. `redcap_log_view` zeigt bei jedem Vorkommen dasselbe Muster:
Seitenaufrufe von `<client-lan>` (Heimnetz-Lesezeichen `http://<server-lan>/`),
dann `LOGIN_SUCCESS` auf `ExternalModules/index.php` von `<client-tailscale>`
(Tailscale), gleicher Browser. Die Basis-URL ist `http://<server-tailscale>/redcap/`.
`ExternalModules::getPageUrl()` baut Modul-Links aus `APP_URL_EXTMOD`, also
absolut aus der Basis-URL; REDCaps eigene Links sind relativ.

**Auswirkung.** Auf jedem Server, der unter mehr als einer Adresse erreichbar ist,
verlässt der Menüpunkt die Adresse des Nutzers; das Session-Cookie gilt dort
nicht. Kein Datenproblem, aber auf einem Produktivserver mit internem Alias für
alle Anwender lästig.

**Korrektur.** `redcap_module_link_check_display()` überschrieben: erst die
Rechteprüfung des Frameworks, dann Schema und Host aus `$link['url']` entfernt.
Das Projektmenü übernimmt den zurückgegebenen Link unverändert in den `href`
(`manager/templates/hooks/every_page_top.php`, `getLinkIconHtml()`); die
Zugriffsprüfung beim Aufruf der Seite (`ExternalModules/index.php`) wertet nur
aus, ob überhaupt ein Link zurückkommt.

**Nachweis.**

```
vom Framework:  http://<server-tailscale>/redcap/redcap_v17.2.1/ExternalModules/?prefix=revisits&page=reorder
nach dem Hook:  /redcap/redcap_v17.2.1/ExternalModules/?prefix=revisits&page=reorder
Menü:           <a href="/redcap/redcap_v17.2.1/ExternalModules/?prefix=revisits&page=reorder&pid=35">
```

Betrifft nur den Menü-Link dieses Moduls; Links anderer Module und Links in
REDCap-E-Mails bleiben absolut.

---

## 9. Deadlock: Teilzustand trotz Transaktion

**Befund.** `ExternalModules::query()` fängt Deadlocks (1213) und Lock-Wait-Timeouts
(1205) ab, wartet 3 Sekunden und wiederholt **die einzelne Abfrage**. Bei einem
Deadlock hat InnoDB zu diesem Zeitpunkt die ganze Transaktion bereits
zurückgerollt; mit `start transaction` liefen die wiederholte Abfrage und alles
Folgende danach im Autocommit-Modus und wurden einzeln festgeschrieben.

**Auswirkung.** Reproduziert mit einem echten Deadlock: Eine zweite Verbindung
sperrt die Alert-Zeilen eines Records, das Modul sperrt dessen Sperr-Zeilen und
wartet auf die Alert-Zeilen, die zweite Verbindung greift nach den Sperr-Zeilen.
Damit MariaDB das Modul als Opfer wählt, schreibt die zweite Verbindung vorher
50 000 Zeilen in eine Hilfstabelle (InnoDB bricht die Transaktion mit weniger
Änderungen ab). Ergebnis für die visits von Record 7, Ausgabe „Erfolg":

```
Daten, Sperren             unverändert (zurückgerollt)
Feldhistorie               auf die neuen Nummern umgeschrieben
Einladungen, Versandlog    verschoben
Reorder-Eintrag            "1 -> 3, 2 -> 1, 3 -> 2" geschrieben
```

Ein bloßes Warten auf eine Sperre ist auf dem Testserver unkritisch
(`innodb_rollback_on_timeout = OFF`: nur die Abfrage wird zurückgenommen, die
Transaktion bleibt offen). Auf Servern mit `ON` gälte dasselbe wie beim Deadlock.

**Korrektur.** `autocommit = 0` statt `start transaction`: Dann wird auch nach
einem Abbruch nichts mehr automatisch festgeschrieben. Als erste Zeile der
Transaktion wird eine Markierung in eine temporäre InnoDB-Tabelle geschrieben
(Engine ausdrücklich angegeben, falls ein Server temporäre Tabellen anders
anlegt). Vor dem `commit` prüft das Modul per UPDATE, ob sie noch da ist — nicht
per SELECT, den REDCap an eine Lese-Replik schicken könnte. Fehlt sie, wird der
Rest zurückgerollt und gemeldet, dass der Lauf wiederholt werden muss.

**Nachweis.** Derselbe Deadlock mit Record 8:

```
Ausgabe   visit_date: The database aborted this run because the same records were
          being changed at the same time (deadlock). ... nothing was changed for
          this field
visits    Daten, Sperren, Einladungen (1/3), Versandlog (2), Feldhistorie:
          unverändert; kein Reorder-Eintrag für visit_date
followup  regulär umsortiert und protokolliert (eigene Transaktion)
```

Regression: Testskript PID 35 (Record 9) bestanden, Fehlerinjektion per Trigger
(Record 10) unverändert, Feldhistorie PID 17 (Record 708) und PID 12 (Record 710)
bestanden.

---

## 10. Zwei Datumsfelder derselben Wiederholungsgruppe

**Befund.** Die Einstellung ist seit Meigens Fassung wiederholbar (`"repeatable":
true`), gedacht für mehrere Wiederholungsgruppen. Nichts verhinderte aber zwei
Felder derselben Gruppe; dann sortierte das zweite um, was das erste sortiert
hatte („das letzte gewinnt"), und bei getrennten Transaktionen konnte nach einem
Fehler die Sortierung nach dem falschen Feld stehen bleiben. Außerdem bot die
Auswahl jedes Datumsfeld an, auch solche außerhalb jeder Wiederholungsgruppe, mit
denen ein Lauf nichts bewirkt.

**Korrektur** (Wunsch des Nutzers 2026-10-15: spätere Auswahlfelder sollen
Datumsfelder einer schon gewählten Gruppe nicht mehr anbieten):

- `dateFieldGroups()` ermittelt pro Datumsfeld seine Wiederholungsgruppen aus
  `Project::getRepeatingFormsEvents()` und `eventsForms`: wiederholbares Event
  oder wiederholbares Instrument in einem Event.
- `redcap_module_configuration_settings()`: nur Felder mit Gruppe, Gruppe im
  Optionstext. `"autocomplete": false`, weil sich im Suchfeld-Widget einzelne
  Optionen nicht zuverlässig sperren lassen.
- `redcap_every_page_top()` gibt auf der Modulverwaltung
  (`ExternalModules/manager/project.php`) ein Skript aus, das bei jedem Neuaufbau
  der Dialogzeilen (MutationObserver) und jeder Änderung die Optionen späterer
  Einträge sperrt. Die gerade gewählte Option wird nie gesperrt, nur rot markiert:
  jQuery liest eine gesperrte gewählte Option als leer, der Eintrag würde beim
  Speichern stillschweigend verschwinden statt abgelehnt zu werden.
- `validateSettings()` lehnt das Speichern ab; `reorder.php` überspringt einen
  späteren Eintrag derselben Gruppe und Felder ohne Gruppe.

**Nachweis.** PID 35 um zwei Testfelder ergänzt: `fu_extra_date` (Formular
`followup_extra`, gehört wie `fu_date` zum wiederholbaren Event „Follow-up") und
`birth_date` (`demographics`, keine Wiederholungsgruppe).

```
Dialog-Optionen   visit_date — Visits (repeating instrument, event Baseline)
                  fu_date — Follow-up (repeating event)
                  fu_extra_date — Follow-up (repeating event)
                  (birth_date nicht angeboten)
Speichern         visit_date + fu_date                 angenommen
                  visit_date + fu_date + fu_extra_date abgelehnt: "fu_extra_date"
                                                       belongs to the same repeating
                                                       group as "fu_date" ...
Lauf mit alter    fu_extra_date: Follow-up (repeating event) is already sorted by
Einstellung       'fu_date' - skipped
                  birth_date: not part of a repeating instrument or event - skipped
```

Das Dialog-Skript lief in einem Nachbau des Dialogs (jsdom, jQuery 3.7.1) mit
genau der Ausgabe des Moduls: Öffnen mit zwei gespeicherten Einträgen, Eintrag
hinzufügen, anderes Modul im selben Dialog, frühere Auswahl auf dieselbe Gruppe
geändert (rot, Wert bleibt für `val()` lesbar), korrigiert, ersten Eintrag
entfernt — alle Prüfungen bestanden. Im echten Browser noch nicht geklickt.

---

## Bewusst nicht geändert

**Materialisierung der neuen Nummerierung in einer temporären Tabelle.** Geprüft
und verworfen. Verdacht war, dass das UPDATE beim Lesen seiner eigenen Tabelle
(`update … join (select … from redcap_data …)`) halb aktualisierte Zeilen sieht.
`EXPLAIN` zeigt die Unterabfrage als `DERIVED`, angesprochen über `<derived2>` mit
automatisch angelegtem Index `key0`: MariaDB materialisiert sie vollständig, bevor
geschrieben wird (vermutlich zwingend, weil die Fensterfunktion ein Einschmelzen in
die äußere Abfrage verhindert). Je ein kontrollierter Lauf einer
Drei-Instanzen-Rotation mit `derived_merge=on` und `off` lieferte korrekte Werte,
ebenso alle Läufe des Testskripts aus Abschnitt 6. Eine zunächst als Datenverlust
gedeutete Beobachtung ging auf eine Eingabe über die Oberfläche (Account `admin`,
DataEntry, dann Reorder-Button) zwischen Datenanlage und Testlauf zurück, nicht auf
das Modul.

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

- Mehrere konfigurierte Datumsfelder laufen in getrennten Transaktionen: Scheitert
  das zweite, bleibt das erste umsortiert.
- Die Hinweiszeile zu datumslosen Instanzen erscheint auch bei einem Lauf ohne
  Änderungen — sie beschreibt den Zustand, nicht die Aktion.
- Bezüge auf feste Instanznummern in der Projektlogik (`[feld][1]`, Bedingungen,
  Reportfilter) zeigen nach dem Umsortieren auf andere Visiten; vor der Aktivierung
  klären (README).
- `redcap_ehr_resource_instances` (EHR-Anbindung) und
  `redcap_project_migration_files` werden nicht umnummeriert.
- Feldhistorie: Ein Textwert, der selbst einen Zeilenumbruch gefolgt von
  `feldname =` enthält, könnte als zusätzliches Feld gelesen werden. Nur relevant,
  wenn der Name zu einem existierenden Feld eines anderen Formulars passt — dann
  bleibt der Eintrag unverändert, er wird nicht falsch umgeschrieben.

