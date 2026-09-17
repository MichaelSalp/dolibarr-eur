# ChangeLog

## 1.1

- Nicht abziehbare Vorsteuer (§ 15 Abs. 1a UStG) bei Geschenken über 50 €, nicht abziehbarem Arbeitszimmer und
  sonstigen nicht abziehbaren Betriebsausgaben wird brutto in der Ausgabenzeile erfasst statt in Zeile 57.
- Kleinunternehmer-Status je Rechnung nach Rechnungsdatum: Umsätze aus der Zeit vor dem Wechsel zu § 19 UStG
  bleiben in den Zeilen 15–17.
- Überzahlungen („EXCESS RECEIVED“) werden über alle Zeilen der Quellrechnung zurückgerechnet
  (vorher negative Zeile 16 bei Rechnungen mit 0-%-Zeilen).
- Beliebiger Berichtszeitraum (Datumsfelder, Schnellwahl Quartale); 10-Tage-Regel für Teilzeiträume.
- Neue Prüfungen C0 (Kontenzuordnung vorhanden) und C10 (Belege mit Konten eines anderen Kontenrahmens).
- Kontenzuordnung erweitert: Geldtransit/durchlaufende Posten (neutral), Aushilfslöhne, pauschale Lohnsteuer,
  Zinserträge, Verspätungszuschläge, weitere Anlagekonten.
- CSV-Export gegen Formel-Injection abgesichert; eigene CSRF-Token-Prüfung; Eingabeprüfung für Beträge,
  Jahre und Zeiträume.
- Modul funktioniert auch als Symlink/Junction in `htdocs/custom/eur`.

## 1.0

- Erste Version: Anlage EÜR 2025 nach Zahlungen, SKR03/SKR04-Zuordnung, Kleinunternehmer, 10-Tage-Regel,
  manuelle Werte, Prüfungen C1–C9, PDF- und CSV-Export.
