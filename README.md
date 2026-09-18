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
`datetime_seconds_*`). Reine Zeitfelder, Zahlen und Text stehen bewusst nicht zur
Auswahl — siehe [Sortierung](#sortierung).

Die Einstellung ist wiederholbar, es können also mehrere Datumsfelder angegeben
werden. Sie werden nacheinander abgearbeitet; enthält eine Wiederholungsgruppe
mehrere davon, gewinnt das zuletzt verarbeitete. Darauf sollte man sich nicht
verlassen — ein Feld pro Gruppe ist die saubere Konfiguration.

Sichtbar ist der Link nur für Nutzer mit **Design-Rechten** und für Superuser
(Standardverhalten von `redcap_module_link_check_display()`).

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

## Grenzen und Risiken

**Nicht rückgängig zu machen.** Das Log zeigt, was passiert ist, kann es aber
nicht zurücknehmen. Vor dem ersten Einsatz in einem produktiven Projekt: Backup.

**Instanznummern stehen auch in anderen Tabellen.** Das Modul nummeriert
ausschließlich in `redcap_data*` um. Auf einer REDCap-17-Instanz haben zwölf
weitere Tabellen eine `instance`-Spalte:

```
redcap_surveys_response          redcap_locking_data         redcap_esignatures
redcap_data_quality_status       redcap_edocs_data_mapping   redcap_alerts_sent
redcap_alerts_recurrence         redcap_surveys_pdf_archive  redcap_surveys_scheduler
redcap_outgoing_email_sms_log    redcap_surveys_scheduler_queue
redcap_project_migration_files
```

Verweise dort bleiben auf den alten Nummern stehen. **Das Modul darf deshalb
nicht in Projekten eingesetzt werden, in denen auf den betroffenen Instanzen
Surveys, Record-Locking, E-Signaturen oder Alerts hängen** — eine E-Signatur
würde danach einen anderen Inhalt bescheinigen.

**Instanzen ohne Datumswert** werden ans Ende sortiert und behalten
untereinander ihre Reihenfolge. Die Ausgabe meldet, wie viele das waren. Eine
halb ausgefüllte Visite bekommt dadurch eine neue Nummer, die fachlich nichts
bedeutet.

**Keine Transaktionsklammer.** Die Abfrage für das Log und das anschließende
UPDATE laufen einzeln, ebenso mehrere konfigurierte Datumsfelder nacheinander.
Bei einem Abbruch mittendrin kann ein Teilzustand entstehen.

## Entwicklung

- [`docs/Aenderungen.md`](docs/Aenderungen.md) — was gegenüber der Originalfassung
  geändert wurde, mit Begründung und Nachweis
- [`docs/Technik.md`](docs/Technik.md) — wie die Umsortierung funktioniert,
  REDCap-Interna (Sharding, Wiederholungsgruppen, Log-Tabellen), Testvorgehen

## Lizenz

Dem Original lag keine Lizenz bei. Vor einer Veröffentlichung oder Weitergabe
über die Universität Leipzig hinaus muss das mit Christof Meigen geklärt werden.
Dieses Repository ist deshalb privat.
