# Technische Dokumentation

Für Wartung und Weiterentwicklung. Beschreibt, wie die Umsortierung arbeitet und
welche REDCap-Interna sie voraussetzt. Stand: REDCap 17.2.1.

---

## Dateien

| Datei | Zweck |
|---|---|
| `config.json` | Modulname, Projekt-Link, Projekteinstellung, `framework-version` |
| `Revisits.php` | leere Klasse, nur damit das Framework ein Modulobjekt hat — keine Hooks |
| `reorder.php` | die gesamte Logik: GET zeigt den Button, POST führt aus |

Das Modul hängt sich in **keinen** Hook ein. Es passiert nur etwas, wenn jemand
den Button drückt.

---

## Ablauf eines Laufs

Für jedes konfigurierte Datumsfeld:

1. **Feldtyp prüfen** — `element_validation_type` aus `redcap_metadata`. Beginnt
   der Wert nicht mit `date`, wird das Feld mit Meldung übersprungen.
2. **Datentabelle bestimmen** — `$module->getDataTable($pid)`.
3. **Datumslose Instanzen zählen** — für die Hinweiszeile.
4. **Künftige Nummerierung lesen** — alte und neue Instanznummer, gruppiert nach
   Record und Event. Muss vor dem UPDATE geschehen.
5. **UPDATE** — setzt `instance` auf die neue Nummer.
6. **Protokollieren** — Projekt-Log pro Record/Event, Modul-Log pro Lauf.

---

## Die Abfrage

Drei ineinander geschachtelte Ebenen, in `reorder.php` als drei PHP-Variablen,
damit Log-Abfrage und UPDATE garantiert dieselbe Nummerierung sehen.

### Ebene 1 — `$scope_sql`: welche Instanzen gehören dazu

Liefert alle existierenden Instanzen der Wiederholungsgruppe, zu der das
Datumsfeld gehört, mit dem Datumswert per `LEFT JOIN` daneben.

Die Gruppe ergibt sich aus `redcap_events_repeat`. Diese Tabelle hat pro
Wiederholungsdefinition eine Zeile:

- `form_name` **leer** → das ganze Event wiederholt sich
- `form_name` **gesetzt** → nur dieses Instrument wiederholt sich

Daraus die Bereichsbedingung:

```sql
(nullif(er.form_name,'') is not null                       -- repeating instrument
 and er.form_name = dfm.form_name                          -- Datumsfeld liegt in diesem Formular
 and md.form_name = er.form_name)                          -- nur Zeilen dieses Formulars
or
(nullif(er.form_name,'') is null                           -- repeating event
 and exists (select 1 from redcap_events_forms ef
             where ef.event_id = er.event_id
               and ef.form_name = dfm.form_name))          -- Datumsfeld liegt im Event
```

`dfm` ist die Metadatenzeile des Datumsfelds, `md` die des jeweiligen Datensatzes.

> **Falle:** `redcap_events_forms` ist bei **klassischen** Projekten leer — die
> Event-Formular-Zuordnung existiert nur bei longitudinalen Projekten. Ein Join
> darauf außerhalb des Event-Zweigs liefert eine leere Ergebnismenge. Genau das
> ist beim ersten Entwurf passiert.

Das `LEFT JOIN` auf das Datumsfeld ist der Kern der Korrektur aus Punkt 2: die
Instanz existiert in der Liste auch dann, wenn sie keinen Datumswert hat.

### Ebene 2 — `$numbered_sql`: die neue Nummer

```sql
nullif(row_number() over (
    partition by s.record, s.event_id
    order by (s.visitdate is null or s.visitdate = ''),   -- Leerwerte ans Ende
             s.visitdate,                                  -- chronologisch
             coalesce(s.instance, 1)                       -- stabil bei Gleichstand
), 1) as new_instance
```

`nullif(..., 1)` setzt die erste Instanz auf `NULL` — REDCaps Konvention, die
erste Instanz wird nicht als `1` gespeichert, sondern als `NULL`. Deshalb steht
im Vergleich überall `coalesce(instance, -1)`.

### Ebene 3 — das UPDATE

```sql
update <datentabelle> d
inner join (<$numbered_sql>) new_instances
        on (d.project_id = ? and d.record = new_instances.record
            and d.event_id = new_instances.event_id
            and coalesce(d.instance,-1) = coalesce(new_instances.instance,-1))
inner join redcap_metadata md
        on (md.project_id = d.project_id and md.field_name = d.field_name)
   set d.instance = new_instances.new_instance
 where coalesce(d.instance,-1) != coalesce(new_instances.new_instance,-1)
   and (new_instances.form_name is null or new_instances.form_name = md.form_name)
```

Der Join geht **nicht** über `field_name`, also wandern alle Felder einer Instanz
gemeinsam. Der Metadaten-Join am Ende begrenzt das bei wiederholbaren
Instrumenten auf die Felder des richtigen Formulars; bei wiederholbaren Events
ist `form_name` leer und die Bedingung greift nicht.

Die Tabelle wird als `d` aliasiert, weil der Name variabel ist.

---

## REDCap-Interna, auf die sich das Modul verlässt

### Sharding der Datentabellen

Projektdaten liegen in einer von mehreren gleich aufgebauten Tabellen
(`redcap_data`, `redcap_data2` … `redcap_data8` bei REDCap 17). Welche, steht in
`redcap_projects.data_table`. Die Zuordnung fällt einmalig bei der Projektanlage
über `Records::getSmallestDataTable()` — nach physischer Tabellengröße aus
`information_schema`, bei Gleichstand alphabetisch die erste.

Wie viele Tabellen existieren, ist versionsabhängig: REDCap 14.0 legte 2–4 an,
14.5.1 dann 5–6, 16.0 dann 7–8; eine Neuinstallation legt alle acht direkt an.
`Records::getDataTables()` akzeptiert Suffixe 2–99, ein Admin kann also weitere
anlegen.

Nachträglich verschieben geht nur über Control Center → „Move project data"
(`ControlCenter/movedata.php`), und nur für Projekte, die aktuell in
`redcap_data` liegen.

**Konsequenz:** Tabellennamen nie fest verdrahten und nie zwischenspeichern.
`\REDCap::getDataTable($pid)` bei jedem Aufruf.

Dasselbe Muster gilt für das Log (`redcap_projects.log_event_table`,
`\REDCap::getLogEventTable($pid)`). `Logging::logEvent()` erledigt das selbst.

### Keine Unique-Keys

Die `redcap_data*`-Tabellen haben ausschließlich nicht-eindeutige Indizes
(`event_id_instance`, `proj_record_field`, `project_field`) — so im
Auslieferungszustand, siehe `Resources/sql/install.sql`. Doppelte Kombinationen
aus `(project_id, event_id, record, field_name, instance)` werden von der
Datenbank **nicht** abgewehrt. Eine unvollständige Permutation erzeugt deshalb
stille Duplikate statt eines Fehlers.

### Speicherformat von Datumswerten

Immer `YYYY-MM-DD[ HH:MM[:SS]]`, unabhängig vom Anzeigeformat des Feldtyps, mit
zweistelliger Normalisierung. Deshalb ist die Zeichenkettensortierung
chronologisch korrekt.

### Zugriffsschutz

`AbstractExternalModule::redcap_module_link_check_display()` zeigt Projekt-Links
per Default nur Nutzern mit Design-Rechten und Superusern. Das Modul überschreibt
den Hook nicht. Zusätzlich greift ab `framework-version` 8 der CSRF-Schutz.

---

## Testvorgehen

Alle Änderungen wurden auf dem Testserver in echten Projekten geprüft, nicht nur
als Trockenlauf. Bewährte Konstellation:

| Projekt | Zweck |
|---|---|
| 12 „Repeating Instruments" | wiederholbares **Instrument** `visits` mit `visit_date`, liegt in `redcap_data`; zusätzlich `medications` als unbeteiligte Gruppe für die Abgrenzung |
| 17 „Patientenportal Test" | wiederholbares **Event** (Follow-up), liegt in `redcap_data2` → prüft die Shard-Auflösung und das Log-Sharding (`redcap_log_event11`) |
| 15 „MyCap Example Project" | `wel_favoriteday` vom Typ `datetime_mdy` → prüft Datum+Uhrzeit |

Testfälle, die man nach jeder Änderung durchgehen sollte:

1. Instanzen unsortiert, alle mit Datum → korrekte Reihenfolge
2. Instanz ohne Datum in der Mitte → wandert ans Ende, keine Dubletten
3. Zwei Instanzen mit identischem Datum → alte Reihenfolge bleibt
4. Zweiter Lauf direkt danach → 0 geänderte Zeilen (Idempotenz)
5. Unbeteiligte Wiederholungsgruppe im selben Event → unverändert
6. Projekt in einem Shard ≠ `redcap_data` → Tabellenname in der Ausgabe stimmt
7. Nicht-Datumsfeld konfiguriert → übersprungen mit Meldung
8. Duplikatprüfung: `group by record, event_id, instance, field_name having count(*) > 1`

Die Prüfung auf Duplikate ist der wichtigste Nachweis — sie hätte den Fehler aus
Punkt 2 sofort gezeigt.

### Ausführen ohne Browser

Für Tests lässt sich die Seite per CLI einbinden: REDCap wie in `cron.php`
hochfahren, `ExternalModules::setProjectId($pid)` setzen, `$module` über
`getModuleInstance('revisits')` holen, `$_SERVER['REQUEST_METHOD'] = 'POST'`
setzen und `reorder.php` includen.

Zwei Einschränkungen:

- Der Weg umgeht `ExternalModules/index.php` und damit **die CSRF-Prüfung**. Wer
  daran etwas ändert, muss im Browser gegentesten.
- `$module->getCSRFToken()` liefert ohne Session einen leeren String
  (`System::getCsrfToken()` liest `$_SESSION`). Im CLI-Rendering ist das
  Hidden-Field deshalb leer — kein Defekt.

Solche Hilfsskripte müssen direkt in `/var/www/html/redcap/` liegen (relative
Includes) und danach **gelöscht werden** — sie lösen sonst unauthentifiziert eine
destruktive Aktion aus.

---

## Randnotiz

Beim Anlegen von Testdaten ist aufgefallen, dass `REDCap::saveData()` eine zweite
Basiszeile (`record_id`, `instance IS NULL`) anlegt, wenn ein **weiteres**
wiederholbares Instrument zu einem bestehenden Record gespeichert wird — statt
die vorhandene zu erkennen. Mangels Unique-Key bleiben beide stehen.
Kontrollversuch ohne Modullauf bestätigt, dass es nicht am Modul liegt.
Beobachtet im CLI-Kontext (NOAUTH/CRON, USERID SYSTEM); über Weboberfläche oder
API nicht nachgeprüft. Für das Modul folgenlos, weil `record_id` zum Formular
`demographics` gehört und vom Formularfilter des UPDATE nie erfasst wird.
