# Ortsnetz-Auslastung

Sendet die im Haus gemessene Netzspannung alle 5 Minuten an [ortsnetz-auslastung.de](https://www.ortsnetz-auslastung.de) und zeigt die Antwort des Dienstes als Variablen: Netzampel je Phase und gesamt, Empfehlung für den Batteriespeicher und Redispatch-Maßnahmen in der Umgebung.

Grundlage ist die öffentliche API des Projekts [datenerfassung-ortsnetz-auslastung](https://github.com/thomaslehmann1234/datenerfassung-ortsnetz-auslastung) (`POST /v1/measurements`, kein API-Schlüssel).

### Inhaltsverzeichnis

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Software-Installation](#3-software-installation)
4. [Einrichten der Instanzen in Symcon](#4-einrichten-der-instanzen-in-symcon)
5. [Statusvariablen und Darstellungen](#5-statusvariablen-und-darstellungen)
6. [Visualisierung](#6-visualisierung)
7. [PHP-Befehlsreferenz](#7-php-befehlsreferenz)
8. [Datenschutz](#8-datenschutz)

### 1. Funktionsumfang

* Liest die Spannungen L1–L3 aus beliebigen vorhandenen Symcon-Variablen (Shelly, Modbus-Zähler, SMA, Fronius, Smart-Meter-Gateway, …) und sendet sie im 5-Minuten-Raster. Einphasige Messungen sind möglich; Phasen ohne Variable gehen als „nicht gemessen“ hinaus.
* Bildet aus allen Aktualisierungen im Intervall selbst **Minimum, Maximum und Mittelwert** von Spannung und Frequenz und sendet sie mit (abschaltbar).
* Optional: Netzfrequenz, Ströme je Phase, Netzleistung am Hausanschluss (W oder kW, Vorzeichen umkehrbar), Ladezustand des Speichers, PV-Prognose für heute.
* Angaben zur Anlage (PV-Größe in kWp, nutzbare Speicherkapazität, Messgerät) gehen nur nach Einrichtung, Änderung und Neustart mit, wie es die API vorsieht.
* Prüft vor dem Senden: Eine gewählte Spannungsvariable mit unplausiblem Wert (nicht 0–500 V) oder ohne Aktualisierung seit 15 Minuten verhindert das Senden. So landen keine Werte eines ausgefallenen Zählers beim Dienst.
* Standort aus der Instanz oder, wenn leer, aus dem Standort des Symcon-Systems; gerundet auf etwa 1 km, 100 m (Vorgabe) oder 10 m.

### 2. Voraussetzungen

- Symcon 8.1 oder neuer mit Internetzugang.
- Mindestens eine Variable mit der Netzspannung einer Phase in Volt.

### 3. Software-Installation

* Über das Module Control folgende URL hinzufügen:
  `https://github.com/da8ter/OrtsnetzAuslastung`

### 4. Einrichten der Instanzen in Symcon

Unter „Instanz hinzufügen“ das Modul **Ortsnetz-Auslastung** wählen.

__Konfigurationsseite__:

| Name | Beschreibung |
|---|---|
| Messungen senden | Schaltet das Senden ein. Ab Werk aus, damit nichts hinausgeht, bevor die Variablen gewählt sind. |
| Standort | Leer: Standort des Symcon-Systems (Kern-Instanzen → Location Control). |
| Genauigkeit | Rundung der Koordinaten: etwa 1 km, 100 m oder 10 m. |
| Spannung L1–L3 | Variablen in Volt; mindestens eine. |
| Netzfrequenz | Optional, in Hz. |
| Minimum, Maximum, Mittelwert | Statistik über das Intervall aus allen Aktualisierungen von Spannung und Frequenz. |
| Strom L1–L3 | Optional, in A. Ein Vorzeichen des Zählers wird ignoriert (Effektivwert). |
| Netzleistung, Einheit, Vorzeichen | Gesamte Wirkleistung am Hausanschluss; der Dienst erwartet Bezug positiv und Einspeisung negativ. Keine Wechselrichterleistung, keine einzelne Phase. |
| Ladezustand Batterie | Optional, 0–100 %. |
| PV-Prognose für heute | Optional, in kWh. Geht nur mit eingetragener PV-Anlagengröße hinaus und höchstens 10 kWh je kWp. |
| PV-Anlage, Speicherkapazität, Messgerät | Angaben zur Anlage; 0 bzw. leer = nicht senden. |

Unten auf der Seite zeigt eine Zeile den Standort, der tatsächlich gesendet wird. **Zu sendende Daten anzeigen** zeigt die Nutzlast, ohne zu senden; **Jetzt senden** überträgt sofort (höchstens einmal pro Minute).

__Status__:

| Code | Bedeutung |
|---|---|
| 102 | Messungen werden gesendet |
| 104 | Senden ist ausgeschaltet |
| 200 | Senden fehlgeschlagen (Netz oder Server) |
| 201 | Kein Standort |
| 202 | Keine Spannungsvariable gewählt |
| 203 | Senden übersprungen: Messwerte unplausibel oder veraltet |
| 204 | Messung vom Dienst abgelehnt (422) – Werte prüfen, Details in „Letzte Meldung“ |
| 205 | Der Standort ist beim Dienst gesperrt (403) |

Ins Meldungsfenster schreibt das Modul nur beim Wechsel in einen Fehler, nicht alle 5 Minuten.

### 5. Statusvariablen und Darstellungen

| Ident | Name | Typ | Bedeutung |
|---|---|---|---|
| STATUS | Netzampel | Integer | Gesamtbewertung: Unbekannt (-1), Grün (0), Gelb (1), Rot (2) |
| STATUS_L1 … STATUS_L3 | Netzampel L1 … L3 | Integer | Bewertung je eingerichteter Phase |
| STORAGE | Speicherempfehlung | Integer | Unbekannt (-1), Keine Aktion (0), Speicher laden (1), Speicher entladen (2) |
| REDISPATCH | Redispatch in der Umgebung | Integer | Unbekannt (-1), Nein (0), Ja (1): mindestens drei Anlagen und zwei Maßnahmen im Umkreis von 50 km in der letzten Stunde |
| REDISPATCH_UNITS | Redispatch: betroffene Anlagen | Integer | Anzahl, -1 = unbekannt |
| REDISPATCH_MEASURES | Redispatch: Maßnahmen | Integer | Anzahl, -1 = unbekannt |
| REDISPATCH_UPDATED | Redispatch: Datenstand | Integer (Datum/Uhrzeit) | Stand der Redispatch-Daten des Dienstes |
| LAST_UPLOAD | Letzte Übertragung | Integer (Datum/Uhrzeit) | Zeit der letzten angenommenen Messung |
| LAST_MESSAGE | Letzte Meldung | String | Ergebnis des letzten Versuchs, bei Ablehnung mit der Begründung des Dienstes |

Die Speicherempfehlung leitet der Dienst aus der gesendeten Messung ab: „Speicher entladen“, wenn eine Phase unter ihrer unteren Warnschwelle liegt, „Speicher laden“, wenn eine über der oberen liegt. Damit lässt sich z. B. ein Batteriespeicher netzdienlich steuern.

### 6. Visualisierung

Die Variablen erscheinen mit ihren Darstellungen (Ampelfarben) in der Kachel-Visualisierung.

### 7. PHP-Befehlsreferenz

`string ONA_Upload(int $InstanzID);`
Sendet sofort eine Messung und liefert das Ergebnis als Text. Der Timer ruft diese Funktion alle 5 Minuten auf.

`string ONA_GetPayload(int $InstanzID);`
Liefert die Daten, die jetzt gesendet würden, als JSON – ohne zu senden.

### 8. Datenschutz

Gesendet werden Zeitstempel, die gerundeten Koordinaten, die Spannungen und nur die optionalen Werte, die auf der Konfigurationsseite gewählt sind. Keine Zählernummern, Gerätekennungen, Energiezähler oder IP-Adressen. Kennung der Integration: `symcon-<Version>`.
