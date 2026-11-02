# Arbeitsregeln für dieses REDCap-Modul

Diese Datei gilt für jede Claude-Code-Session in diesem Repo und für alle, die am Modul arbeiten.

## Einmalig nach dem Klonen

```bash
git config core.hooksPath .githooks
```

Damit laufen die Prüfungen in `.githooks/` bei jedem Commit (siehe „Keine Netzdetails“).

## Definition of Done

Eine Änderung ist erst fertig, wenn:

1. sie auf einem **Testserver** getestet wurde (nie gegen Produktiv- oder Patientendaten), mit Testfall und Ergebnis kurz notiert,
2. `php -l` für alle geänderten PHP-Dateien fehlerfrei ist,
3. das **Sicherheitsreview** (unten) für die Änderung durchlaufen und der README-Abschnitt **„Sicherheit“** aktualisiert ist,
4. der Diff klein und auf ein Thema beschränkt ist,
5. die CI auf GitHub (`.github/workflows/qc.yml`: PHP-Syntax, Netzdetails, gitleaks, PHPStan, Unit-Tests) grün ist,
6. die Tests grün sind und das Ergebnis **in der Sitzung gemeldet** ist: `~/scripts/redcap-sectests.sh <Modul>` (Unit-Tests ohne REDCap und, sofern vorhanden, Sicherheitstests gegen den Testserver, siehe `tests/security/README.md`). Jeder neue Sicherheitsbefund bekommt einen Testfall, der mit der alten Version fehlschlägt.

## PHPStan

Statische Analyse auf Stufe 5 (`phpstan.neon`). REDCap selbst ist nicht im Repo (Lizenz); `phpstan/redcap-stubs.php` deklariert nur Namen und Signaturen der genutzten REDCap- und Framework-Teile. Nutzt das Modul eine **neue** REDCap-Methode oder -Eigenschaft, dort ergänzen, sonst meldet PHPStan „undefined method“. Befunde nicht per `ignoreErrors` wegdrücken, sondern beheben oder im Stub korrekt beschreiben.

## Review-Nachweis (technisch geprüft)

Jeder Commit, der PHP-Code des Moduls ändert (nicht `tests/`, nicht `phpstan/`), braucht einen Nachweis, sonst lehnt der `commit-msg`-Hook ab und die CI („Review-Vermerk“) wird rot:

- eine Zeile `Sicherheitsreview: <Ergebnis>` in der Commit-Nachricht, z. B. `Sicherheitsreview: Rechte geprüft, redcap-sectests grün`, oder
- eine inhaltliche Änderung im README-Abschnitt „Sicherheit“ im selben Commit.

Braucht eine Änderung kein Review (Tippfehler, Kommentar), dann `Sicherheitsreview: nicht nötig (<Grund>)`. Der Vermerk belegt, dass das Review stattfand; er ersetzt es nicht.

## Sicherheitsreview (Pflicht bei neuen Modulen und jeder Funktionsänderung)

Jeden Punkt gegen den echten REDCap-Quellcode bzw. per Test prüfen, nicht aus dem Gedächtnis beantworten:

1. **Angriffsfläche:** AJAX-Endpunkte, `no-auth-ajax-actions`, Schreibzugriffe (SQL/`saveData`), Crons, API-Aufrufe. Für jeden: Wer kann ihn mit welcher Eingabe auslösen?
2. **Eingabe → Query/Logik:** nur parametrisierte Queries; keine Teilnehmereingaben in Logik oder SQL.
3. **XSS/Injection:** Einstellungen und Daten nur über `textContent`/Escaping ausgeben; CSS-Werte per Whitelist validieren; JSON mit `JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT` einbetten.
4. **Richtiger Datensatz?** Im Survey-Kontext `$fetched` nicht blind vertrauen (öffentlicher Link: vorläufig „1“); temporäre IDs (`isTemporaryRecordId`) beachten; `Records::recordExists` nutzen.
5. **Datensparsamkeit im Browser:** Was steht im Seitenquelltext oder in der AJAX-Antwort, das der Betrachter ohne Modul nicht sehen könnte? Feldnamen, Ausfüllstatus, abgeleitete Werte (Branching!) anderer Instrumente gehören auf den Server.
6. **Benutzerrechte:** Formularrechte, DAGs, Exportrechte prüfen und bei Unklarheit verweigern (fail closed). Fallstrick: `getRights()` liefert `null` für Benutzernamen mit Großbuchstaben, daher `UserRights::getPrivileges()` mit kleingeschriebenem Namen und `convertFormRightsToArray($r['data_entry'])` verwenden.
   **Jede Modulseite braucht eine eigene Rechteprüfung** ganz oben (für GET und POST). Die Standardprüfung des Frameworks (Design-Rechte) läuft nur, wenn `page=` exakt dem Link aus `config.json` entspricht; `page=./seite` umgeht sie (nachgewiesen in REDCap 17.2.1). Testen mit `./seite`, `.//seite`, `../<prefix>_vX/seite`.
7. **Logging/Audit:** Umgehen Schreibzugriffe das REDCap-Logging? Dann selbst loggen (`$this->log`, `enable-no-auth-logging`).
8. **Befunde testen**, nicht nur beheben: Leak-Check im Seitenquelltext (z. B. Playwright), Rechtestufen, Regression.

Ergebnis als Review vorlegen (was geprüft und sicher ist, was leckt, was empfohlen wird). Fixes erst nach Freigabe, außer bei offensichtlichen Bugs der aktuellen Aufgabe.

## Keine Netzdetails im Repo

Dieses Repo wird geteilt. In Code, Doku, Testdaten **und Commit-Messages** stehen nur Platzhalter, keine echten IP-Adressen, internen Hostnamen, Tailscale-/MagicDNS-Namen oder Gerätenamen:

- `<server-lan>`, `<server-tailscale>`, `<client-lan>`, `<client-tailscale>`
- `https://redcap.example/redcap/`

Die Hooks `pre-commit` und `commit-msg` blockieren Verstöße. Ein Umgehen mit `--no-verify` ist nur bei nachweislichen Fehlalarmen (z. B. Netzmasken) erlaubt.

## Keine Geheimnisse

Keine Tokens, Passwörter, API-Keys oder echten Patienten- bzw. Personendaten committen, auch nicht in Tests oder Beispielen.
