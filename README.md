# meldetool
Wordpress plugin to let team managers create teams and let participants join teams. Creating start list.

# Debugging:
## Runbook
1. Symptom verifizieren:
* Anonym im privaten Fenster /anmeldung aufrufen
* Prüfen, ob Pods-Fehlertext angezeigt wird
2. Sofortmaßnahme:
* W3TC Page Cache leeren
* /anmeldung erneut anonym testen
3. Cache als Ursache absichern:
* W3TC kurz deaktivieren, Test wiederholen
* Wenn dann ok: Cache-Inhalt war stale
4. Session-Basis prüfen (Pods/Runtime):
* Pods Settings: Session-Schutz für anonyme Formulare aktiv
* Debug/Log prüfen:
  * session_save_path nicht leer
  * pods_can_use_sessions_env = 1
  * pods_session_id_empty = 0
5. Dauerhafte Prävention:
* /anmeldung in W3TC Page Cache Exclude aufnehmen
* Nach Plugin/PHP/Server-Änderungen immer Cache flushen
* Optional: kleiner anonymer Health-Check auf Fehlertext

## W3TC Exclude-Empfehlung
1. Page Cache Exclude:
* /anmeldung*
* Falls nötig zusätzlich:
  * /anmeldung/
  * /anmeldung
2. Bei multilingual/parametrisiert:
* auch Varianten mit Query berücksichtigen
Schnelle Entscheidungslogik

1. Fehler weg nach Cache-Flush:
* stale cache war wirksam
2. Fehler bleibt trotz deaktiviertem Cache:
* echte Runtime-/Session-Ursache
3. Fehler nur anonym:
* Sessions/Pods/Cache-Bypass priorisieren
