# EÜR – Anlage EÜR für Dolibarr

Dolibarr-Modul, das die **Einnahmenüberschussrechnung nach § 4 Abs. 3 EStG** im Aufbau der amtlichen
**Anlage EÜR** erstellt – als Bildschirm-Report mit Zeilennummern, PDF und CSV. Grundlage sind die in
Dolibarr erfassten **Zahlungen** (Zuflussprinzip), nicht das Hauptbuch.

> **Hinweis:** Das Modul bereitet Zahlen aus Dolibarr auf. Es ersetzt keine Steuerberatung, übermittelt nichts
> an ELSTER und übernimmt keine Gewähr für die Richtigkeit. Die Verantwortung für die Steuererklärung liegt beim
> Steuerpflichtigen. Die Werte werden manuell in Mein ELSTER übertragen.

## Funktionen

- **Anlage EÜR 2025**: Betriebseinnahmen (Zeilen 12–23), Betriebsausgaben (24–75, inkl. zweispaltiger Zeilen
  62–67 „abziehbar / nicht abziehbar“), Gewinnermittlung (76–97), Entnahmen und Einlagen (106/107).
- **Nach Zahlungen**: Jede Zahlung wird anteilig auf die Zeilen der bezahlten Rechnung verteilt; Netto und
  Umsatzsteuer werden getrennt. Berücksichtigt Teilzahlungen, Skonto, Gutschriften (erstattet oder verrechnet),
  Anzahlungen, Überzahlungen, Lieferantenrechnungen, Spesen, USt-Zahlungen, Sozialabgaben, Gehälter, Darlehen
  (nur Zinsen/Versicherung gewinnwirksam), sonstige Zahlungen und Spenden.
- **Zuordnung über Buchhaltungskonten**: Standardzuordnung für **SKR03 und SKR04** (246 Konten), in Dolibarr
  erweiterbar.
- **Umsatzsteuer**: Regelbesteuerung und **Kleinunternehmer (§ 19 UStG)**, einstellbar pro Jahr; der Status
  richtet sich nach dem Rechnungsdatum. Nicht abziehbare Vorsteuer (§ 15 Abs. 1a UStG) wird brutto in der
  jeweiligen Ausgabenzeile erfasst.
- **10-Tage-Regel** (§ 11 Abs. 1 Satz 2 EStG) für USt-Vorauszahlungen, beim Erstellen abfragbar, mit
  Dauerfristverlängerung.
- **Beliebiger Berichtszeitraum** (Jahr, Quartal, abweichendes Wirtschaftsjahr).
- **Manuelle Jahreswerte** für Beträge ohne Zahlungsfluss: AfA, Restbuchwerte, private Kfz-Nutzung,
  Homeoffice-Pauschale, Korrekturen der Gewinnermittlung.
- **Prüfungen**, damit kein Geld unbemerkt fehlt (siehe unten). Schlägt eine Prüfung fehl, ist der Report als
  „vorläufig“ gekennzeichnet.
- Drill-down von jeder Zeile bis zum einzelnen Zahlungsbeleg.

## Voraussetzungen

- Dolibarr **24.0** oder neuer, PHP 7.4 oder neuer
- Module **Buchhaltung** (Accounting) und **Rechnungen** (werden bei Aktivierung mit eingeschaltet)
- Kontenrahmen SKR03 oder SKR04 (liefert Dolibarr mit)

## Installation

**Variante A – ZIP über die Oberfläche**

1. Release-ZIP `module_eur-x.y.zip` von der Releases-Seite herunterladen (Dateinamen nicht ändern).
2. In Dolibarr: *Start → Einstellungen → Module → Externes Modul bereitstellen* → ZIP hochladen.

**Variante B – Git**

```bash
cd /pfad/zu/dolibarr/htdocs/custom
git clone https://github.com/MichaelSalp/dolibarr-eur.git eur
```

Der Ordner muss `eur` heißen. Ein Symlink (Linux) bzw. Junction (Windows) von `htdocs/custom/eur` auf einen
anderen Ordner funktioniert ebenfalls.

**In beiden Fällen** müssen in `htdocs/conf/conf.php` beide Zeilen für eigene Module gesetzt sein
(oft nur auskommentiert):

```php
$dolibarr_main_url_root_alt='/custom';
$dolibarr_main_document_root_alt='/pfad/zu/dolibarr/htdocs/custom';
```

## Einrichtung

1. **Kontenrahmen laden**: *Buchhaltung → Einstellungen → Kontenplan* → SKR03 oder SKR04.
2. **Modul „EÜR“ aktivieren**: *Start → Einstellungen → Module*. Codes und Kontenzuordnung werden angelegt.
3. Wurde der Kontenrahmen erst nach der Aktivierung geladen: in den Moduleinstellungen
   **„Standardzuordnung laden“** (kann gefahrlos wiederholt werden).
4. **Belege kontieren**: Rechnungs- und Spesenzeilen brauchen ein Buchhaltungskonto
   (*Buchhaltung → Kontierung*, automatische Kontierung über hinterlegte Produkt-/Standardkonten).
   Nicht kontierte Beträge erscheinen unter „Nicht zugeordnet“.
5. **Einstellungen**: Kleinunternehmer-Jahre, Dauerfristverlängerung, Voreinstellung der 10-Tage-Regel.
6. **Rechte**: „EÜR anzeigen und exportieren“ und „Manuelle EÜR-Werte pflegen“ unter *Benutzer → Berechtigungen*.

Die Zuordnung Konto → EÜR-Code lässt sich unter *Buchhaltung → Einstellungen → Benutzerdefinierte Gruppen*
(Bericht „EUR“) ändern.

> Das Modul setzt `ACCOUNTING_ENABLE_MULTI_REPORT=1`, damit ein Konto zugleich einem EÜR-Code und anderen
> benutzerdefinierten Gruppen zugeordnet sein kann. Bereits bestehende eigene Gruppen bleiben erhalten. Zuordnungen,
> die danach in der Oberfläche angelegt werden, zeigt der Core-Bericht „Benutzerdefinierte Gruppierung“ jedoch
> nicht an (er liest nur die alte Einzelzuordnung). Wer diesen Bericht nutzt, sollte das vor der Aktivierung prüfen.

## Nutzung

*Buchhaltung → EÜR (Anlage EÜR)*: Zeitraum wählen (Datumsfelder, Schnellwahl Jahr/Quartale), 10-Tage-Regel
ja/nein, dann

- **Anlage EÜR**: Zeilen mit Beträgen; Klick auf einen Code zeigt die einzelnen Zahlungen.
- **Prüfungen**: Ergebnis aller Prüfungen und Kassenabstimmung je Zahlungskanal.
- **Manuelle Werte**: Jahreswerte ohne Zahlungsfluss. Sie werden nur bei einem Zeitraum von genau zwölf Monaten
  eingerechnet.
- **PDF / CSV**: Export mit Zeitraum, Besteuerungsart, 10-Tage-Regel und Prüfungsergebnissen.

## Prüfungen

| Prüfung | Bedeutung |
|---|---|
| C0 | Für den aktiven Kontenrahmen existiert eine Kontenzuordnung |
| C1 | Summe der Zahlungen je Kanal = Summe aller Zuordnungen (auf den Cent) |
| C2 | Nicht zugeordnete Beträge (unkontierte Zeilen, Konten ohne EÜR-Code, Zahlungen ohne Rechnung) |
| C3 | Belege, deren Zeilen nicht zur Belegsumme passen |
| C4 | Bankbuchungen ohne Zahlungsbeleg (würden sonst fehlen) |
| C5 | Konten, die mehreren EÜR-Codes zugeordnet sind |
| C6 | Geringwertige Wirtschaftsgüter über 800 € (Hinweis) |
| C7 | Anlagenkauf ohne eingetragene AfA bzw. Teilzeitraum ohne Jahreswerte (Hinweis) |
| C8 | Belege in Fremdwährung (Hinweis) |
| C9 | Kleinunternehmer-Einstellung passt nicht zur Firmeneinstellung (Hinweis) |
| C10 | Belege mit Konten eines anderen Kontenrahmens als dem aktiven |

## Grenzen

- **Keine AfA-Berechnung** und keine Anlage AVEÜR: Abschreibungen und Restbuchwerte als manuelle Werte eintragen.
- Nicht abgedeckt: § 13b Reverse Charge (Zeile 16 nur über Konten), Sammelposten und § 7g (nur manuell),
  Einlage privater Wirtschaftsgüter, Anlagen SZ/LuF/AVSE, Land- und Forstwirtschaft (Zeilen 13/14/25/26).
- Keine ELSTER-Übermittlung (nur über die proprietäre ERiC-Bibliothek möglich).
- Formular derzeit nur für **2025**; andere Jahre verwenden das nächstgelegene Formular.
- Wird eine Gutschrift oder Anzahlung erst später mit einer Rechnung verrechnet, verteilt der Report frühere
  Zahlungen dieser Rechnung neu (Netto/USt verschieben sich, der Gewinn bleibt gleich). Das exportierte PDF/CSV
  als Nachweis der abgegebenen Erklärung aufbewahren.

## Neues Formularjahr

Zeilennummern und -texte ändern sich jährlich. Für ein neues Jahr `forms/2025.php` nach `forms/JJJJ.php`
kopieren und an den BMF-Vordruck anpassen. Die Kontenzuordnung bleibt unverändert, weil Konten auf stabile
Codes (z. B. `A_TELEKOM`) zeigen und nur die Formulardatei Codes auf Zeilen abbildet.

## Entwicklung und Tests

`test/seed.php` legt auf einer **frischen, leeren** Dolibarr-Datenbank eine deutsche Testfirma mit SKR03/SKR04
und einem Testdatensatz an (Rechnungen, Gutschriften, Anzahlungen, Skonto, Spesen, USt, Darlehen, …).
`test/check.php` prüft die Berechnung gegen von Hand gerechnete Werte (Regelbesteuerung, ohne 10-Tage-Regel,
Kleinunternehmer, Teilzeitraum, Kontenzuordnung).

```bash
php htdocs/custom/eur/test/seed.php     # nur auf einer frischen Installation
php htdocs/custom/eur/test/check.php    # Exit-Code 0 = alle Prüfungen bestanden
```

Liegen die Skripte außerhalb von `htdocs/custom/eur`, den Pfad per `DOLIBARR_HTDOCS=/pfad/zu/htdocs` angeben.
**`seed.php` niemals auf einer produktiven Instanz ausführen.** Der Ordner `test/` ist nicht im Release-ZIP.

**Release-ZIP bauen** (enthält nur die Moduldateien, gesteuert über `.gitattributes`):

```bash
git archive --format=zip --prefix=eur/ -o module_eur-1.1.zip v1.1
```

## Lizenz

GPL-3.0-or-later, siehe [LICENSE](LICENSE).
