# Ortsnetz-Auslastung für Symcon

![Ortsnetz-Auslastung — Kachel](https://raw.githubusercontent.com/da8ter/images/main/Ortsnetz-Auslastung%20Kachel.png)

Symcon-Modul für [ortsnetz-auslastung.de](https://www.ortsnetz-auslastung.de): sendet die gemessene Netzspannung alle 5 Minuten an den Dienst und zeigt Netzampel, Speicherladeempfehlung und Redispatch in der Umgebung als Variablen.

Folgende Module beinhaltet die Bibliothek:

- __Ortsnetz-Auslastung__ ([Dokumentation](OrtsnetzAuslastung))

	Liest Spannung (und optional Frequenz, Ströme, Netzleistung, Speicher, PV-Prognose) aus vorhandenen Symcon-Variablen und überträgt sie an die API des Projekts [datenerfassung-ortsnetz-auslastung](https://github.com/thomaslehmann1234/datenerfassung-ortsnetz-auslastung).
