# Benutzerhandbuch: Inhalte pflegen

Anleitung für alle, die die Website im Admin-Bereich mit Inhalten füllen – ohne
Programmierkenntnisse. Technische/Entwickler-Doku liegt separat:
[`ARCHITECTURE.md`](ARCHITECTURE.md) (Aufbau), [`COMPONENTS.md`](COMPONENTS.md)
(Bausteine im Detail), [`ADMIN-USERS.md`](ADMIN-USERS.md) (Zugänge/Rollen),
[`UPDATE-CORE.md`](UPDATE-CORE.md) (Core-Updates),
[`UPDATE-COMPONENTS.md`](UPDATE-COMPONENTS.md) (Components-Updates).

## Einloggen

Adresse: `https://ihre-domain.de/?admin=1` (oder `/admin/`). Mit Benutzername und
Passwort anmelden. Oben rechts steht, als wer man angemeldet ist, daneben "Abmelden".
"Seite" öffnet die Live-Website in einem neuen Tab.

## Drei Bereiche: Sections, Stammdaten und Admin

Die Seitenleiste ist in Gruppen sortiert:

- **Sections** – für jeden Account sichtbar: die Liste der einzelnen
  Inhalts-Abschnitte der Startseite (siehe unten). Hinzufügen/Sortieren/Löschen
  ist Admin-only, das Bearbeiten der Inhalte offen für jeden Account.
- **Stammdaten** – für jeden Account sichtbar: Seite, Betrieb/Kontaktdaten,
  SEO/Meta-Daten, Impressum, Datenschutz, Bildverwaltung.
- **Admin** – nur für Accounts mit Admin-Rolle: Navigation, Beschriftungen, Themes,
  Sicherung, Benutzer verwalten. Wer diese Rolle hat, verwaltet
  [`ADMIN-USERS.md`](ADMIN-USERS.md).
- **Projekte** (Admin-Rolle) – Projekt-Instanz erstellen, Projekt-Import (lokal,
  nur sichtbar wenn vorhanden).
- **System-Updates** (Admin-Rolle) – Update prüfen/Core aktualisieren und
  Components prüfen/aktualisieren, siehe [`UPDATE-CORE.md`](UPDATE-CORE.md) und
  [`UPDATE-COMPONENTS.md`](UPDATE-COMPONENTS.md).

Änderungen werden erst gespeichert, wenn unten auf **„Änderungen speichern"**
geklickt wird – "Verwerfen" lädt die Seite ohne Speichern neu.

## Mehrsprachige Inhalte

Ist die Website mehrsprachig eingerichtet, erscheint oben rechts im Admin
ein kleiner Umschalter mit den Sprachkürzeln (z. B. „DE / EN / FR"). Ein
Klick darauf schaltet **alle** Textfelder der ganzen Seite gleichzeitig auf
diese Sprache um – nicht nur ein einzelnes Feld. Bei nur einer Sprache
erscheint der Umschalter gar nicht, dann gibt es auch auf der
Live-Website keinen Sprachwechsel zu sehen.

Ein paar Dinge, die dabei hilfreich sind zu wissen:

- Ein Feld, das für eine Sprache noch nicht ausgefüllt wurde, zeigt auf der
  Live-Website automatisch den deutschen Text statt einer Lücke – nichts
  geht kaputt, wenn eine Übersetzung noch fehlt.
- Wird eine Sprache zum ersten Mal bearbeitet, ist ihr Eingabefeld schon
  mit dem deutschen Text vorausgefüllt – als Startpunkt zum Anpassen statt
  bei null anzufangen.
- Welche Sprachen überhaupt zur Auswahl stehen, wird nicht hier, sondern
  unter Admin → Themes festgelegt (admin-only) – siehe dort.

## Seiteninhalte: Abschnitte (Sections)

Jeder Bereich der Startseite (Hero-Bild, News-Raster, Kontaktformular usw.) ist ein
eigener **Abschnitt**. Die Liste der Abschnitte steht oben links in der Seitenleiste,
mit der Überschrift "SECTIONS".

- **Bearbeiten**: auf den Namen klicken, das Formular öffnet sich rechts – das kann
  jeder Account.
- **Hinzufügen, Sortieren, Löschen**: nur mit Admin-Rolle sichtbar/möglich.
  - *Hinzufügen*: oben im Dashboard Typ auswählen, "Hinzufügen" klicken. Der neue
    Abschnitt erscheint am Ende der Liste, mit leeren Feldern.
  - *Sortieren*: per Drag am Griff (⋮⋮) ziehen, oder mit den Pfeilen ▲▼ neben dem
    Griff verschieben. Die Reihenfolge in der Liste entspricht der Reihenfolge auf
    der Seite.
  - *Löschen*: im geöffneten Abschnitt oben auf "… löschen" klicken – es erscheint
    ein Bestätigungsdialog (kein Klick geht versehentlich durch).
- **Bezeichnung** (oben im Abschnitt, optional): eigener Name für die Seitenleiste,
  z. B. "Atelier" statt "Content-Block" – hilfreich, sobald mehrere Abschnitte
  desselben Bausteins im Projekt sind und sonst nicht auseinanderzuhalten wären.
  Leer lassen zeigt weiterhin den Baustein-Namen. Dieselbe Bezeichnung erscheint
  auch im "– oder Section –"-Auswahlfeld neben jedem Link-Feld (z. B. in der
  Navigation) – ohne Bezeichnung stehen dort mehrere gleichartige Abschnitte nur
  mit demselben Baustein-Namen und sind nicht zu unterscheiden.
- **Anker** (oben im Abschnitt): die technische Sprungmarke für Links (z. B.
  `die-praxis`), wird beim Anlegen automatisch vergeben. Lässt sich hier
  umbenennen (nur Kleinbuchstaben, Ziffern, Bindestriche) – Navigationspunkte, die
  auf den alten Anker zeigten, werden dabei automatisch mit umgestellt.
- **Hintergrund** (oben rechts im Abschnitt): Weiß, Getönt oder Akzent erzwingt
  eine bestimmte Hintergrundfarbe für diesen Abschnitt; "Automatisch" wechselt
  stattdessen selbstständig zwischen Weiß/Getönt ab, je nachdem an welcher
  Position der Abschnitt auf der Seite steht.
- **Anker-Gruppe** (oben rechts im Abschnitt): mehrere **direkt aufeinander
  folgende** Abschnitte mit demselben Gruppennamen werden auf der Seite in einem
  gemeinsamen Block zusammengefasst, den man in der Navigation als ein
  Sprungziel verlinken kann (taucht im "– oder Section –"-Auswahlfeld unter
  "Gruppen" auf). Der Hintergrund des **ersten** Abschnitts der Gruppe gilt dann
  für den ganzen Block, der der weiteren Abschnitte wird ignoriert.

### Die verfügbaren Bausteine

Kurzbeschreibung, was jeder Typ ist und wofür er sich eignet – die vollständige
Feldliste steht in [`COMPONENTS.md`](COMPONENTS.md):

| Baustein | Wofür |
| --- | --- |
| **Hero-Slider** | Aufmacher ganz oben. Drei Layouts wählbar: vollflächiges Bild mit Text darüber, Bildkarussell oben mit Text darunter, oder Text/Bild nebeneinander. |
| **Aktuelles** | Schmaler Hinweis-Streifen direkt unter dem Hero, z. B. für eine aktuelle Ankündigung mit Anruf-Button. |
| **Content-Block** | Vielseitigster Baustein: 1–3 Spalten Text, Bild neben/unter Text, oder Bild als Hintergrund mit Text darüber. |
| **News-Raster** | Kacheln mit Datum/Titel/Teaser, Link pro Kachel entweder zu einem anderen Abschnitt oder als Popup mit eigenem, längerem Text. |
| **Google-Reviews** | Bewertungs-Karussell. Ohne Google-API-Zugang werden die hier eingetragenen "Fallback-Bewertungen" gezeigt. |
| **Bildgalerie** | Kategorie-Kacheln, die beim Klick einen Bilder-Slider öffnen. |
| **FAQ** | Aufklappbare Frage/Antwort-Liste. |
| **Kontaktformular** | Formular + automatisch aus Betrieb/Kontaktdaten befüllte Infokarte (Adresse, Telefon, E-Mail, Öffnungszeiten, Karte). |
| **Cards** | Karten-Raster, pro Karte wahlweise Bild (oben, volle Breite) oder Icon (oben, links/mittig/rechts ausrichtbar), darunter Titel/Untertitel/Text. |
| **Vorher-Nachher-Galerie** | Karten-Raster mit Vorher-/Nachher-Bildpaaren, z. B. für Restaurierungen. Karte antippen zum Vergrößern, im Vergrößert-Modus per Pfeil zwischen Vorher/Nachher wechseln. |

Jeder Baustein kann mehrere Einträge/Bilder/Fragen enthalten (siehe "Listen mit
mehreren Einträgen" unten) – wie viele und welche Reihenfolge, bestimmt man selbst.

## Textfelder formatieren

Textareas (Fließtext-Felder) haben oben eine kleine Werkzeugleiste:

| Symbol | Wirkung | Schreibweise |
| --- | --- | --- |
| ↶ ↷ | Rückgängig / Wiederholen | – |
| **B** | Fett | `**Text**` |
| *I* | Kursiv | `*Text*` |
| ¶ | Hinweiszeile (kleiner, gedämpft) | `%%Text%%` |
| **H** | Hervorhebung in Akzentfarbe | `::Text::` |
| ☰ | Häkchen-Liste | jede Zeile mit `- ` beginnen |
| 🔗 | Link | markieren, klicken, Ziel eingeben |

Man kann die Symbole klicken (wirkt auf die Markierung, oder fügt einen
Platzhaltertext ein) oder die Schreibweise direkt eintippen – beides funktioniert.
Eine Leerzeile trennt Absätze. Die `i`-Sprechblase daneben öffnet eine Auswahl, um
Betriebsdaten als Platzhalter einzufügen (siehe unten).

## Bilder einfügen

Bild-Felder zeigen ein kleines Vorschaubild plus drei Buttons:

- **Hochladen** – neue Bilddatei vom Rechner auswählen.
- **Auswählen…** – aus bereits hochgeladenen Bildern wählen (öffnet eine Übersicht).
- **✕ Entfernen** – Auswahl im Feld leeren (löscht die Datei nicht).

Unter **Bildverwaltung** liegt der gemeinsame Bild-Pool: alle hochgeladenen Bilder,
mit Hinweis, wo ein Bild gerade verwendet wird. Ein Bild lässt sich erst löschen,
wenn es in keinem Abschnitt mehr eingebunden ist. Dort auch: externe Bild-URLs
(z. B. Unsplash-Links) auf lokale Dateien umbiegen.

## Platzhalter: Betriebsdaten automatisch einfügen

In Textfeldern lässt sich `{business.telefon}`, `{business.name}` usw. eintippen
oder über die `{ }`-Auswahl neben dem Feld einfügen – wird auf der Live-Seite
automatisch durch den aktuellen Wert aus **Betrieb / Kontaktdaten** ersetzt. So
bleibt z. B. die Telefonnummer in mehreren Texten immer aktuell, ohne sie überall
einzeln pflegen zu müssen. Welche Platzhalter zur Verfügung stehen, richtet sich
danach, welche Felder unter Betrieb/Kontaktdaten ausgefüllt sind.

Für Mail-Links gibt es zusätzlich `{date}` (nur unter Beschriftungen →
E-Mail-Vorbelegung) – wird beim Klick durch das aktuelle Datum/Uhrzeit ersetzt.

## Listen mit mehreren Einträgen (Repeater)

Felder wie "Einträge", "Bilder" oder "Fragen" sind aufklappbare Listen:

- **⋮⋮**-Griff: Reihenfolge per Drag ändern.
- **▲ ▼**: Eintrag einen Platz nach oben/unten schieben.
- **Aktiv/Inaktiv**-Schalter: ein Eintrag lässt sich deaktivieren, ohne ihn zu
  löschen – erscheint dann nicht auf der Seite, bleibt aber im Admin erhalten.
- **✕**: Eintrag endgültig entfernen (kein Bestätigungsdialog bei einzelnen
  Repeater-Zeilen – anders als beim Löschen ganzer Abschnitte).
- **+ Eintrag hinzufügen**: neue, leere Zeile am Ende der Liste.

## Stammdaten im Detail

- **Seite**: Titel/Unterzeile/Logo-Text, Kontakt-Leiste oben (Ankündigungstext +
  Link-Beschriftung). Der „Aktiv"/„Inaktiv"-Schalter daneben schaltet die
  Leiste komplett ein oder aus, unabhängig vom Text.
- **Betrieb / Kontaktdaten**: Name, Inhaber, Adresse, Telefon, E-Mail, USt-IdNr.,
  Handelsregister, Öffnungszeiten, abweichender Kartensuchbegriff. Wird für
  Kontaktformular-Infokarte, Footer, JSON-LD (Suchmaschinen), Telefon-/Mail-Buttons
  und `{business.*}`-Platzhalter verwendet.
- **SEO / Meta-Daten**: Seitentitel/-beschreibung, OG-Titel/-Beschreibung (für
  Vorschaubilder bei WhatsApp/Facebook/Linkedin usw.), OG-Bild, Favicon,
  "Von Suchmaschinen ausschließen". Basis-URL steht nur informativ (kommt aus
  `config.json`). Zeichen-Zähler zeigen empfohlene Längen.
- **Impressum** / **Datenschutz**: zwei eigene Menüpunkte, je mit beliebig vielen
  Textblöcken (wie bei Galerie-Einträgen: „+ Eintrag hinzufügen“, per Ziehen
  sortierbar, einzeln aktivierbar/deaktivierbar/löschbar) – jeder Block mit
  derselben Formatierungs-Werkzeugleiste wie andere Textfelder. So lassen sich
  einzelne Abschnitte (z. B. „Haftungsausschluss“, „Cookies“) unabhängig
  voneinander bearbeiten, statt alles in einem einzigen Textfeld zu pflegen.

## Admin-Bereich (nur mit Admin-Rolle)

- **Navigation**: Menüpunkte der Kopfzeile – Label, Ziel (anderer Abschnitt, eine
  Anker-Gruppe, oder freie URL), Aktiv/Inaktiv, Reihenfolge.
- **Beschriftungen**: wiederkehrende Bedienelement-Texte (Sticky-Leiste,
  Cookie-Banner, Footer-Links, E-Mail-Vorbelegung). Leer lassen übernimmt die
  deutsche Standardbeschriftung.
- **Themes**: fertige Theme-Vorlagen (Marken-Farben, Fonts, Header-/Footer-/
  Topbar-/Sticky-Leiste-/Cookie-Banner-Auswahl) als Karten zum Anklicken.
  „Anwenden" wechselt das komplette Design sofort – keine Vorschau vorher, aber
  jederzeit auf ein anderes Theme zurückwechselbar. „✕ Löschen" entfernt ein
  Theme dauerhaft (geht nicht für das gerade aktive).
  Jede Karte hat einen „Bearbeiten"-Button, der das Formular „Theme bearbeiten
  oder neu erstellen" darunter mit den Werten dieses Themes befüllt – unter
  demselben Namen speichern ändert das bestehende Theme, ein neuer Name legt ein
  neues an. „+ Neues Theme (Felder leeren)" setzt das Formular auf leer zurück,
  um ganz neu zu beginnen: Name vergeben, Header/Footer/Topbar/Sticky-Leiste/
  Cookie-Banner einzeln aus dem vorhandenen Pool wählen, speichern. Im selben
  Formular werden auch die Sprachen für dieses Theme angehakt (Deutsch ist
  immer aktiv) – siehe „Mehrsprachige Inhalte" oben.
- **Sicherung**: frühere Bearbeitungsstände der Textfelder wiederherstellen (Bilder
  bleiben unangetastet) – jeder Speichervorgang legt automatisch einen Eintrag an.
- **Benutzer verwalten**: Accounts anlegen/Passwort ändern/entfernen, Admin-Rolle
  vergeben. Details: [`ADMIN-USERS.md`](ADMIN-USERS.md).

## Projekte (nur mit Admin-Rolle)

- **Projekt-Instanz erstellen**: exportiert den aktuellen Stand (Inhalte, Bilder,
  aktives Theme) als eigenständigen, deploybaren Ordner – der Weg von "im
  Generator fertig" zu "beim Kunden live". Nur beim ersten Export sinnvoll bzw.
  wenn bewusst der komplette Stand (inkl. aktueller Inhalte) übernommen werden
  soll – ein erneuter Export auf eine bereits laufende Instanz würde dort
  zwischenzeitlich geänderte Inhalte/Bilder überschreiben.
- **Projekt-Import (lokal)**: nur sichtbar, wenn lokale Test-/Kundenprojekte
  vorbereitet sind – Entwickler-Werkzeug, nicht für den täglichen Redaktionsbetrieb.

## System-Updates (nur mit Admin-Rolle)

- **Core**: "Update prüfen" / "Core aktualisieren" ersetzt den generischen
  CMS-Motor (nie Inhalte/Bilder) – siehe [`UPDATE-CORE.md`](UPDATE-CORE.md).
- **Components**: "Components prüfen" / "Components aktualisieren" macht
  dasselbe für den gemeinsamen Bausteine-Pool (Cards, FAQ, Kontaktformular, …) –
  siehe [`UPDATE-COMPONENTS.md`](UPDATE-COMPONENTS.md).
- Beide Bereiche zeigen oben im Admin-Kopf die aktuell installierte Version; ein
  roter "… verändert"-Hinweis daneben bedeutet: der lokale Stand weicht vom
  zuletzt gebauten Update-Paket ab (Entwickler-Hinweis, kein Fehler).

## Rechtliches: Impressum, Datenschutz, Cookie-Banner

Der Cookie-Banner (Text, Buttons, Link zur Datenschutzerklärung) ist über
**Beschriftungen** editierbar. Impressum und Datenschutzerklärung stehen unter
**Stammdaten → Impressum** bzw. **Stammdaten → Datenschutz**, je als beliebig
viele einzeln bearbeitbare Textblöcke (wie Galerie-Einträge: hinzufügen,
sortieren, aktivieren/deaktivieren, löschen), jeder mit derselben
Formatierungs-Werkzeugleiste wie andere Textfelder. Die Kontaktdaten
(Adresse, Telefon, E-Mail, USt-IdNr.) werden im Impressum automatisch aus
Betrieb/Kontaktdaten ergänzt – dort also nur den Text drumherum pflegen.

## Dieses Handbuch

Unten links im Dashboard verlinkt "Benutzerhandbuch" auf diese Seite
(`?admin=1&do=manual`) – für alle eingeloggten Accounts erreichbar, nicht nur für
Admins.
