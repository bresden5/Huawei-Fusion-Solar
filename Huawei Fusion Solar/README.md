# Huawei Fusion Solar für IP-Symcon

Ab IP-Symcon 7.1. Zwei Zugriffsarten: bestehende Northbound-API-Anbindung oder experimenteller Website-Zugriff mit einer manuell übernommenen Browser-Sitzung. Der Website-Modus basiert auf einem erfolgreichen Test mit EMMA und zwei SUN2000: 18 Abrufe über etwa 85 Minuten, Abschluss nach 90 Minuten. Er ist keine offizielle API und bietet keine automatische Neuanmeldung.

## Installation / Aktualisierung

Die gesamte Bibliothek aktualisieren, nicht nur `module.php`: Zusätzlich wird `libs/WebSession.php` benötigt. Bibliotheks- und Modul-IDs bleiben unverändert. Bestehende Instanzen behalten zunächst den Modus „Northbound API“.

Eine lokal bereitgestellte ZIP-Datei ist kein Module-Store-Update. Ihr Inhalt muss in die von Symcon verwendete Bibliothek übernommen werden; bei Installation über Module Control muss die neue Version im dort eingetragenen Repository vorhanden sein. Vor dem Austausch eine Sicherung der vorhandenen Bibliothek erstellen. Die bereitgestellte Version wurde noch nicht auf dem Windows-Symcon des Anwenders installiert.

## Website-Modus einrichten

1. Die bisherigen Testskripte und deren Timer stoppen.
2. In der Instanz **Website (Übergangslösung)** auswählen.
3. Website-Domain: `https://uni004eu5.fusionsolar.huawei.com` (ohne weiteren Pfad).
4. Website-Anlagenkennung: `NE=151380676` für die in diesem Projekt getestete Anlage. Bei anderen Anlagen deren Kennung eintragen.
5. Intervall: 300 Sekunden. Einstellungen übernehmen. Die Northbound-Felder werden in diesem Modus nicht verwendet.
6. Im normalen Browser bei FusionSolar anmelden. Aus einer aktuellen `energy-flow`-Anfrage den Cookie-Headerwert und `roarand` lokal übernehmen.
7. Im Aktionsbereich des Moduls die beiden verdeckten Eingabefelder ausfüllen, ohne die Headernamen `Cookie:` / `roarand:`. **Website-Sitzung importieren und starten** anklicken.
8. Die Eingaben werden geleert. Der erste Abruf erfolgt sofort, weitere frühestens alle fünf Minuten. Die FusionSolar-Tabs können geschlossen werden; im Browser abmelden kann die Sitzung ungültig machen.

Domain oder Anlagenkennung ändern verwirft die bisherige Sitzung. Erneuerte Cookies und der Keep-alive-Payload werden in einem Symcon-Attribut gespeichert und beim nächsten Aufruf wiederverwendet. Diese Daten sind nicht verschlüsselt und können in Symcon-Sicherungen enthalten sein. Sie dürfen nicht veröffentlicht oder in Diagnoseausgaben geteilt werden.

## Variablen im Website-Modus

| Ident | Bedeutung |
| --- | --- |
| `TotalPV` | Aggregierte PV-Leistung in W |
| `HouseConsumption` | Von der Website gelieferter Hausverbrauch in W |
| `GridImport` / `GridExport` | Netzbezug / Einspeisung in W, anhand der Energiefluss-Richtung |
| `WebDataValid` | Letzter Website-Abruf lieferte alle vier unterstützten Werte |
| `LastWebSuccess` | Zeitpunkt des letzten erfolgreichen Abrufs, **kein Messzeitpunkt** |
| `ConnectionStatus` | Verbindung, fehlende Sitzung oder Fehlerursache |

Batterievariablen und gegebenenfalls vorhandene Einzelwechselrichtervariablen stammen aus dem API-Modus und werden im Website-Modus **nicht aktualisiert**. Sie sind dort nicht zu verwenden. `WebDataValid` gilt ausschließlich für die vier oben genannten Leistungswerte. Für Automatisierungen zusätzlich das Alter von `LastWebSuccess` prüfen (z. B. höchstens zweimal das Abfrageintervall).

Bei fehlenden Werten, unbekannten Einheiten oder unklarer Flussrichtung bleiben die letzten Messwerte erhalten; `WebDataValid` wird false und der Timer stoppt. Es werden keine fehlenden Werte als Null ergänzt. Eine explizite Nullleistung bleibt zulässig. Andere Netzfluss-Layouts als das getestete müssen gegebenenfalls anhand einer anonymisierten Antwort ergänzt werden. Einspeisung wurde mit simulierten Antworten getestet, nicht an der Live-Anlage.

## Fehler / Sitzung erneuern

- Status 201: Sitzung fehlt oder wurde abgewiesen (auch Weiterleitung / HTTP 401 / 403). Erneut im Browser anmelden und eine aktuelle Sitzung importieren.
- Status 202: Konfiguration, Netzwerk oder Antwortformat prüfen. Der Abruf ist gestoppt. **Jetzt aktualisieren** versucht einmal erneut und startet bei Erfolg den Timer wieder.
- **Website-Sitzung entfernen / Abruf stoppen** löscht nur den lokalen Sitzungsspeicher. Es wird kein Huawei-Logout ausgelöst. Zum serverseitigen Abmelden den Browser verwenden.

Es gibt keine automatische Passwortanmeldung, CAPTCHA-Behandlung oder Anlagensteuerung. Die Website kann eine absolute Sitzungsgrenze erzwingen oder ihre Schnittstelle ändern. Ein erfolgreicher Keep-alive ist kein Nachweis unbegrenzter Gültigkeit. Die Aktualisierungsverzögerung der Cloud-Daten ist unbekannt.

## Prüfung

`php -n tests/web_session.php` führt isolierte Tests ohne Netzwerk durch: Moduskompatibilität, Sitzungsimport, Token-/Cookie-Übernahme, Bezug/Einspeisung, fehlende Daten, Fehlerstopp, erneuter Versuch, Anlagenwechsel und lokale Sitzungslöschung. Die Konfiguration muss nach Installation zusätzlich in einer echten Symcon-Konsole geprüft werden.
