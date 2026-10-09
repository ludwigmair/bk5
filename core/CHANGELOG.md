# Changelog – Core

## 2.7.0 – 2026-10-09

Backups bleiben erhalten (data/backups statt cache/). Neues Panel „Anfragen“ mit Löschfrist, Spam-Bremse im Kontaktformular, richtige Zeitzone. Admin ohne Tailwind-CDN. Theme-Karten zeigen ihre Bereiche. Layout kommt zur Laufzeit aus dem Theme; Themes sind nur im Generator bearbeitbar.

## 2.6.0 – 2026-10-08

Mailversand: Core\Mail mit UTF-8-Headern und optionalem Absender (config mail.from), Admin-Button „Testmail senden“ unter Betrieb / Kontaktdaten.

## 2.5.1 – 2026-10-08

Neue Projekt-Instanzen starten mit „Von Suchmaschinen ausschließen“ (noindex). Instanz angleichen (sync) behält die noindex-Einstellung der Ziel-Instanz.

## 2.5.0 – 2026-10-08

Sicherheit: Session-Cookie gehärtet, Login-Sperre nach 5 Fehlversuchen, SVG-Uploads nur für Admins und gesäubert, Update-Pakete werden per Prüfsumme verifiziert. Stabilität: Speicher-Konflikte werden erkannt statt still zu überschreiben, Updates leeren den Twig-Cache selbst und räumen alte Backups auf.

## 2.4.2 – 2026-10-08

Projekt-Wechsel (Generator) behält die Admin-Logins statt sie aus dev-imports/<slug>/config.json zu übernehmen.

## 2.4.1 – 2026-10-08

Content speichern repariert: verschachteltes Toggle-Formular (Topbar etc.) schloss das Content-Formular vorzeitig, Änderungen an Sections/SEO/Rechtliches wurden verworfen.

## 2.4.0 – 2026-10-07

Core-Update-Release, automatisch erstellt mit dev/build-release.php.

## 2.3.9 – 2026-10-07

Core-Update-Release, automatisch erstellt mit dev/build-release.php.

## 2.3.8 – 2026-09-26

MANUAL.md nachgezogen: Bildgalerie = Kategorie-Karussell mit Lightbox (Beschreibung war noch auf das reine Bildraster aus 1.2.3–1.2.5 kalibriert). Kein Code-Change.

## 2.3.7 – 2026-09-26

Pool-Vorrang beim Regions-Rendering: die konfigurierte Pool-Variante rendert, sobald ihr Ordner existiert – Theme-Kopien sind nur noch Fallback für schlanke Exports. Export, Sync und Projekt-Paket nehmen den verwendeten Pool-Datensatz immer mit; Formularfelder (layoutSchemaFor) und Rendering kommen damit aus derselben Quelle.

## 2.3.6 – 2026-09-24

Core-Update-Release, automatisch erstellt mit dev/build-release.php.

## 2.3.5 – 2026-09-23

Theme-Wechsel setzt den Sprachsatz komplett durch: Beim Wechsel von einem mehrsprachigen Theme (z. B. de/en/fr/lb) auf ein rein deutsches werden die nicht mehr benötigten Sprachen automatisch ausgeblendet und tauchen nicht mehr als verfügbar im Admin auf (bisher blieben sie stehen). Mitgebrachte Theme-Sprachen werden beim Anwenden wieder freigegeben. Projekteigene Zusatzsprachen (languages_allowed) bleiben unberührt.

## 2.3.4 – 2026-09-23

Sprachen repariert: (1) Ausgeblendete Sprachen („Sprachen verwalten“) waren ohne gesetztes language_allowed wirkungslos und blieben als aktiv gelistet – jetzt korrekt überall ausgeblendet. (2) Wechselt man auf ein Theme mit eigenen Sprachen, werden diese automatisch wieder freigegeben (also aus dem Ausgeblendet-Bereich entfernt). Regressionstests für die Sprach-Auswahl ergänzt.

## 2.3.3 – 2026-09-23

Changelog „Was ist neu“ jetzt im Änderungen-Panel der Inhaltsseite (Body) statt in der Sidebar. Sprachen-Verwaltung: nicht benötigte Basis-Sprachen (außer „de“) lassen sich pro Projekt ausblenden und wieder einblenden (config.languages_disabled); Auswahl- und Theme-Listen, aktive Sprachen und Übersetzungserkennung bleiben konsistent, vorhandene Texte fallen auf Deutsch zurück.

## 2.3.2 – 2026-09-23

Admin-Übersetzungen-Panel komplettiert: Changelog „Was ist neu“ (CHANGELOG.md je Release im Update-Paket + Notes im Manifest), Übersetzungen-Export/Import pro Sprache. Sprachen-Verwaltung im Themes-Panel: eigene Sprachen anlegen/entfernen (config.languages_allowed), Basis-Satz bleibt fix.

## 2.3.1 – 2026-09-23

Update-Manifest-URLs bleiben bei Projekt-Import (dev_import) und Projekt-Paket-Import erhalten – keine ausgegrauten Update-Buttons mehr, wenn aus einer dev-Import-Config ohne `update`-Abschnitt auf einen echten Kundenstand gewechselt wird.

## 2.3.0 – 2026-09-23

- Admin „Übersetzungen“: Übersetzungen pro aktiver Sprache als flache Sprachdatei exportieren (Download) und wieder importieren (Upload, max. 5 MB) – Ablage bleibt ausschließlich `data/content.json` mit Inline-Sprach-Maps.
- Sprachen-Handling läuft jetzt aus `config.languages_allowed` statt fest im Core; der Basis-Satz (AVAILABLE_LANGUAGES) bleibt eingebaut.

## 2.2.0 – 2026-09-20

Instanz-Angleich (Sync auf project-instances), Bulk-Bild-Löschung, .optim-Router-Fix, KI-Texthilfe.

## 1.2.7 – 2026-09-18

CSS-Reihenfolge-Fix für statisches Tailwind (theme-agnostische `tailwind.css`).

## 1.2.6 – 2026-09-18

Optimierungs-Release (gemeinsam mit Components 1.1.9).

## 1.2.4 – 2026-09-17

Seed-Cleanup (`uploads.seed` nach dem Seed löschen); `config.seed.json`-Export bzw. Seeding-Stand nachgezogen.