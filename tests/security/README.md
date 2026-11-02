# Sicherheitstests – Revisits

Nachweise aus dem Sicherheitsreview (siehe README, Abschnitt „Sicherheit“) als
wiederholbare Tests. Jeder Fall ist so gebaut, dass er mit der Version **vor** dem
jeweiligen Fix fehlschlägt (Gegentest).

**Nur gegen einen Testserver ausführen, nie gegen Produktion.** Die Tests ändern
vorübergehend Moduleinstellungen, Rechte und Testdaten und stellen alles danach
wieder her. Testadressen enden auf `@example.invalid`.

## Ausführen

```bash
~/scripts/redcap-sectests.sh Revisits          # Übersicht, Ergebnis in der Sitzung melden
```

oder direkt:

```bash
set -a; . ~/.config/redcap-sectests/env; set +a
./tests/security/run.sh
```

## Konfiguration (nicht im Repo)

`~/.config/redcap-sectests/env`, Rechte 600:

| Variable | Bedeutung |
|---|---|
| `RC_BASE` | Basis-URL des Testservers, z. B. `https://redcap.example/redcap` |
| `RC_VERSION` | REDCap-Versionsordner, z. B. `redcap_v17.2.1` |
| `RC_ROOT` | REDCap-Verzeichnis auf dem Server |
| `RC_DB` | Befehl für die Datenbank, z. B. `sudo -n mysql redcap` |
| `RT_PW_FILE` | Datei mit dem gemeinsamen Passwort der Testnutzer |
| `RV_PID` | Testprojekt mit Wiederholungsgruppen und konfiguriertem Datumsfeld |

## Voraussetzungen auf dem Testserver

- Modul im Testprojekt aktiviert, Datumsfeld konfiguriert.
- Testnutzer (tabellenbasierte Anmeldung, alle mit dem Passwort aus `RT_PW_FILE`):
  - `revtest_nodesign`: nur Leserechte, kein Design
  - `revtest_dag`: Design-Rechte, einer DAG zugeordnet
  - `revtest_design`: Design-Rechte, keine DAG
  - `RevTest_Upper`: wie `revtest_design`, Name mit Großbuchstaben (Fallstrick `getRights()`)

Neuer Sicherheitsbefund → neuer Testfall hier, der mit der alten Version fehlschlägt.
