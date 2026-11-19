# Huawei Fusion Solar für IP-Symcon

Ab IP-Symcon 7.1. Zwei Zugriffsarten: bestehende Northbound-API-Anbindung oder experimenteller Website-Zugriff mit einer manuell übernommenen Browser-Sitzung. Der Website-Modus basiert auf einem erfolgreichen Test mit EMMA und zwei SUN2000: 18 Abrufe über etwa 85 Minuten, Abschluss nach 90 Minuten. Er ist keine offizielle API. Ab Version 1.4 ist eine optionale automatische EU5-Anmeldung verfügbar.

## Installation / Aktualisierung

Die gesamte Bibliothek aktualisieren, nicht nur `module.php`: Zusätzlich werden die Dateien im Ordner `libs` benötigt. Bibliotheks- und Modul-IDs bleiben unverändert. Bestehende Instanzen behalten zunächst den Modus „Northbound API“.

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

Batterievariablen und ältere `INV_…`-Einzelwechselrichtervariablen stammen aus dem API-Modus und werden im Website-Modus **nicht aktualisiert**. Die neuen Website-Details verwenden eigene `WEBINV_…`-Variablen. `WebDataValid` gilt ausschließlich für die vier oben genannten Leistungswerte. Für Automatisierungen zusätzlich das Alter von `LastWebSuccess` prüfen (z. B. höchstens zweimal das Abfrageintervall).

Bei fehlenden Werten, unbekannten Einheiten oder unklarer Flussrichtung bleiben die letzten Messwerte erhalten; `WebDataValid` wird false und der Timer stoppt. Es werden keine fehlenden Werte als Null ergänzt. Eine explizite Nullleistung bleibt zulässig. Andere Netzfluss-Layouts als das getestete müssen gegebenenfalls anhand einer anonymisierten Antwort ergänzt werden. Einspeisung wurde mit simulierten Antworten getestet, nicht an der Live-Anlage.

## Fehler / Sitzung erneuern

- Status 201: Sitzung fehlt oder wurde abgewiesen (auch Weiterleitung / HTTP 401 / 403). Bei aktivierter automatischer Anmeldung erfolgt bei Sitzungsablauf ein Anmeldeversuch. Scheitert dieser, Ursache im Verbindungsstatus prüfen. Alternativ im Browser anmelden und eine aktuelle Sitzung importieren.
- Status 202: Konfiguration oder Antwortformat prüfen. Der Abruf ist gestoppt. **Jetzt aktualisieren** versucht einmal erneut und startet bei Erfolg den Timer wieder.
- **Website-Sitzung entfernen / Abruf stoppen** löscht nur den lokalen Sitzungsspeicher. Es wird kein Huawei-Logout ausgelöst. Zum serverseitigen Abmelden den Browser verwenden.

CAPTCHA, Zwei-Faktor-Bestätigung, erzwungene Passwortänderung und Anlagensteuerung werden nicht automatisiert. Die Website kann eine absolute Sitzungsgrenze erzwingen oder ihre Schnittstelle ändern. Ein erfolgreicher Keep-alive ist kein Nachweis unbegrenzter Gültigkeit. Die Aktualisierungsverzögerung der Cloud-Daten ist unbekannt.

## Prüfung

`php tests/web_session.php` führt isolierte Tests ohne Netzwerk durch: Moduskompatibilität, Sitzungsimport, Token-/Cookie-Übernahme, Bezug/Einspeisung, fehlende Daten, Fehlerstopp, erneuter Versuch, Anlagenwechsel und lokale Sitzungslöschung. Die Konfiguration muss nach Installation zusätzlich in einer echten Symcon-Konsole geprüft werden.

## Wechselrichterdetails (ab Version 1.2)

In der Instanz **Website: Wechselrichterdetails abrufen** einschalten, übernehmen und **Jetzt aktualisieren** anklicken. Eine noch gültige Sitzung muss nicht erneut importiert werden. Die Option ist standardmäßig aus, damit ein Update keine zusätzlichen Anfragen startet.

Die Gerätekennungen werden aus dem Wechselrichterknoten im Energiefluss übernommen. Für die getestete Anlage sind dies `NE=151380680` und `NE=188552817`. Pro Zyklus entsteht eine weitere Anfrage je Wechselrichter; bei zwei Geräten insgesamt vier Anfragen (Keep-alive, Energiefluss, zwei Detailabfragen). Maximal acht Geräte werden unterstützt. Für diesen inoffiziellen Endpunkt ist kein verbindliches Huawei-Abfragelimit bekannt.

Variablen heißen `WEBINV_<Gerätenummer>_<Signal>` und liegen ab Version 1.3 im Ordner „Wechselrichter <Gerätenummer>“ mit kurzen Beschriftungen. Geliefert werden, soweit vorhanden:

- AC-Wirkleistung und Nennleistung in W; Blindleistung in kvar.
- Tages- und Gesamtertrag in kWh, Leistungsfaktor und Frequenz.
- Spannung und Strom jeder Phase, Innentemperatur und Isolationswiderstand.
- Status, Ausgabemodus, Anschalt-/Abschaltzeit sowie Leistungsanpassung und Schaltstatus.

Zu jedem Signal gehört eine `_Time`-Variable mit `latestTime` von Huawei (Unix-Sekunden). Diese Zeiten können unterschiedlich alt sein, besonders bei Nennleistung oder Konfiguration. Anschalt-/Abschaltzeit werden als Website-Text gespeichert, ohne eine Zeitzone zu erraten. Ein erfolgreicher Abruf garantiert keine frischen Cloud-Messwerte.

`WEBINV_<ID>_Valid` und `WebInvertersValid` zeigen an, ob alle 21 erforderlichen Signale im aktuellen Abruf auswertbar waren (Innentemperatur ist ab Version 1.4.1 optional), **nicht** ob sie aktuell sind. `_LastSuccess` ist die lokale Abrufzeit. `_Missing` listet fehlende oder unverständliche Signale. In diesem Fall bleibt der alte Einzelwert stehen, dessen `_Time` wird 0; andere gültige Einzelwerte werden aktualisiert. Modelle, die nicht alle Signale liefern, können dauerhaft „Details vollständig = false“ melden. Für Einzelwert-Automatisierungen `_Time`, `_LastSuccess` und den Verbindungsstatus prüfen.

Bei Fehlern einer Detailanfrage stoppt der gesamte Zyklus; die Gültigkeitsanzeigen werden false. Bei Deaktivierung oder entferntem Gerät bleiben alte Variablen erhalten, sind aber nicht mehr gültig. Es werden keine Batterie- oder PV-String-Werte erfunden. Die Antwort des ersten Wechselrichters wurde vom Anwender geliefert, die Anfrage für den zweiten mit simulierten Daten geprüft; der Live-Test beider Geräte in Symcon steht noch aus.

## Ordner pro Wechselrichter (ab Version 1.3)

Nach dem Bibliotheksupdate werden beim Anwenden der Instanzkonfiguration die bisherigen `WEBINV_…`-Variablen in eine Kategorie pro Wechselrichter verschoben. Für die getestete Anlage heißen diese „Wechselrichter 151380680“ und „Wechselrichter 188552817“. Messwerte, individuelle Messzeiten und Gerätestatus liegen jeweils zusammen. Die Anlagen-Gesamtwerte bleiben direkt unter der Instanz.

Vorhandene Objekt-IDs, Profile, Verknüpfungen und Archivzuordnungen bleiben erhalten. Die Variablen werden verschoben, nicht gelöscht oder neu erstellt. Eigene Variablennamen bleiben erhalten; lediglich der bisher automatisch erzeugte Vorsatz „WR <Nummer> – “ wird entfernt. Neue Geräte erhalten ihren Ordner beim ersten Detailabruf. Auch bei deaktiviertem Detailabruf werden vorhandene Variablen beim Übernehmen einsortiert.

Skripte mit festen Variablen-IDs funktionieren weiter. Eigene Skripte, die bisher `IPS_GetObjectIDByIdent('WEBINV_<ID>_<Signal>', InstanzID)` verwendet haben, müssen zuerst den Ordner `WEBINV_<ID>` unter der Instanz suchen und dann darin die Variable. Die Ident-Kennungen selbst bleiben unverändert. Benutzerobjekte und Anlagenvariablen werden nicht verschoben.

## Automatische Website-Anmeldung (ab Version 1.4)

Unterstützt wird der beobachtete EU5-Ablauf von `eu5.fusionsolar.huawei.com` zu
`uni004eu5.fusionsolar.huawei.com`, Region `region004`. Die Funktion ist standardmäßig
abgeschaltet. Bestehende manuell importierte Sitzungen funktionieren weiter.

1. Bibliothek in Module Control vollständig aktualisieren.
2. In der Instanz Website-Modus, bisherige Website-Domain und Anlagenkennung beibehalten.
3. **Website: automatisch anmelden** aktivieren und **Website-Benutzername / E-Mail**
   sowie **Website-Passwort** ausfüllen. Dies sind die normalen Website-Zugangsdaten,
   nicht das Northbound-Konto.
4. Einstellungen übernehmen und **Jetzt aktualisieren** anklicken. Eine vorhandene
   gültige Sitzung wird weiterverwendet. Ohne Sitzung oder bei deren Ablehnung erfolgt
   die Anmeldung. Zum ausdrücklichen Test der neuen Anmeldung zuvor
   **Website-Sitzung entfernen / Abruf stoppen** anklicken, danach **Jetzt aktualisieren**.
5. `ConnectionStatus`, `WebDataValid` und `LastWebSuccess` prüfen. Bei Erfolg läuft
   der bestehende Timer weiter. Cookie und roarand müssen nicht eingegeben werden.

Bei einem Kontowechsel zuerst die alte Sitzung entfernen. Zugangsdaten werden als
Symcon-Eigenschaften gespeichert, Sitzung und roarand als Attribute, ohne zusätzliche
Verschlüsselung im Modul. PasswordTextBox verdeckt lediglich die Anzeige.
Die Zugangsdaten werden ausschließlich an den festgelegten EU5-Anmeldedienst gesendet;
das Passwort wird zusätzlich mit dessen aktuellem RSA-Schlüssel verschlüsselt.

Maximal ein Anmeldeversuch pro Abruf; mindestens zehn Minuten Abstand zwischen
Anmeldeversuchen, auch nach Neustart oder manuellem Klick. Scheitert eine Anmeldung,
stoppt der Timer, auch nach erneutem Übernehmen der Einstellungen. Nach Korrektur
und Ablauf der Wartezeit **Jetzt aktualisieren** verwenden oder manuell eine Sitzung
importieren. CAPTCHA und Zwei-Faktor-Abfragen werden nicht umgangen.
**Website-Sitzung entfernen / Abruf stoppen** bleibt auch mit aktivierter Automatik
wirksam; **Jetzt aktualisieren** oder Sitzungsimport setzt den Abruf fort.
Gespeicherte Zugangsdaten werden durch das Entfernen der Sitzung nicht gelöscht.

Die Anmeldung folgt ausschließlich der geprüften Weiterleitung zur konfigurierten
Anlagenübersicht. Unbekannte Ziele oder Antwortformate stoppen den Ablauf.
Bei vorübergehenden Netzwerkfehlern erfolgt ab Version 1.4.2 ein automatischer Wiederholungsversuch. Sonstige Datenfehler stoppen den Abruf weiterhin.
Der rohe Antwortinhalt, Cookies, Tickets und Passwörter werden nicht protokolliert.

Offline geprüft: erfolgreicher Ablauf, Sitzungsablauf, Wiederanmeldung, Wartezeit,
CAPTCHA-Abbruch, fremde Weiterleitungen, Token-Bootstrap und dauerhafter manueller Stopp.
`php tests/web_login_crypto.php` prüft die RSA-OAEP-SHA384-Verschlüsselung unabhängig
mit OpenSSL einschließlich Unicode und mehrteiliger Passwörter. Tests benötigen
PHP-OpenSSL; der Kryptotest zusätzlich das OpenSSL-Kommandozeilenprogramm.
Der vollständige Live-Anmeldetest in Windows-IP-Symcon steht noch aus. Die Funktion
beruht auf der beobachteten Website und kann bei Huawei-Änderungen Anpassungen benötigen.

### Optionale Innentemperatur (ab Version 1.4.1)

Fehlt die Innentemperatur oder ist sie nicht auswertbar, bleibt die Detail-Gültigkeit bei ansonsten vollständigen Daten wahr. Unter „Fehlende Details“ steht „Innentemperatur (optional)“. Der vorhandene Temperaturwert (auch ein alter Wert von 0 °C) bleibt unverändert; `Temperature_Time` wird 0. Er ist dann kein aktueller Messwert. Die Änderungszeit der Wertvariable ist kein Nachweis einer aktuellen Messung. Liefert Huawei wieder eine gültige Temperatur, werden Wert und Messzeit aktualisiert und der Hinweis entfernt. Andere fehlende erforderliche Signale setzen die Detail-Gültigkeit weiterhin auf falsch.

## Wiederaufnahme nach Internetunterbrechung oder Neustart (ab Version 1.4.2)

Bei Transportfehlern (z. B. DNS, Verbindungsabbruch oder Timeout) und HTTP 408, 429
oder 5xx bleibt die Sitzung gespeichert. Status 203 zeigt den geplanten automatischen
Wiederholungsversuch an. Er erfolgt nach dem konfigurierten Intervall, mindestens
fünf Minuten. Bei einer unterbrochenen Anmeldung wird zusätzlich der Mindestabstand
von zehn Minuten eingehalten. Eine reine Anmeldepause wird automatisch abgewartet.
Bei Erfolg läuft der normale Abruf weiter; bei abgelaufener Sitzung greift die
aktivierte automatische Anmeldung. Ohne diese Option ist bei einer abgelaufenen
Sitzung weiterhin ein manueller Import erforderlich.

Nach einem Symcon-Neustart wird der Timer aus der gespeicherten Konfiguration und
dem Wiederholungszeitpunkt wiederhergestellt. Alte Messwerte bleiben bei einem
Fehler erhalten, ihre Gültigkeitsanzeigen werden jedoch falsch. Falsche Zugangsdaten,
CAPTCHA, unbekannte Antwortformate und ein bewusster manueller Stopp bleiben Gründe
für einen dauerhaften Stopp. Der Timer darf diese Sperren nicht aufheben.

Nach dem Update einmal **Jetzt aktualisieren** verwenden, falls die alte Version
bereits einen dauerhaften Stopp gespeichert hat. Solche alten Stopps lassen sich
nicht zuverlässig von anderen Fehlerursachen unterscheiden. Danach sind für die
oben genannten vorübergehenden Fehler keine manuellen Neustarts des Abrufs nötig.
