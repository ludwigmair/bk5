# Changelog – Components

## 1.2.8 – 2026-10-09

Kontaktformular: Spam-Bremse (5 Anfragen pro Stunde und IP), Anfragen über Core\Inquiries mit Löschfrist und Admin-Panel.

## 1.2.7 – 2026-10-08

Kontaktformular: gewähltes Anliegen kommt in der Mail an, Umlaute korrekt, Versandstatus pro Anfrage, Anfragen bleiben in data/inquiries.json erhalten (lagen in cache/ und gingen bei jedem Deploy verloren).

## 1.2.6 – 2026-09-26

image-gallery: Lightbox-Galerie wiederhergestellt – Kategorie-Karussell (Cover + Name), Klick öffnet die Lightbox mit den Bildern der Kategorie und Thumbnails zum Umschalten. needs_swiper wieder im Schema, leere/ inaktive Bild-Einträge werden gefiltert, Kategorien ohne Bilder zeigen ihr Cover. Ersetzt das seit 1.2.3 reine Bildraster.

## 1.2.5 – 2026-09-26

CHANGELOG-H1 normalisiert: der Titel steht jetzt oben, das Admin-Panel („Was ist neu“) zeigt den neuesten Eintrag zuerst. Keine weiteren Inhaltsänderungen seit 1.2.4.

## 1.2.4 – 2026-09-24

content-block: Layout-Fix für die Person-Card – md:grid-cols-[1fr_320px] lag unmittelbar am Twig-Tag, der Tailwind-Scanner übersprang die Klasse und das statische CSS fehlte (Card lief vollbreit unter den Text). Klasse jetzt in {% set %} als String-Literal; tailwind.css aller Themes neu gebaut.

## 1.2.3 – 2026-09-23

Changelog-Integration: CHANGELOG.md im Components-Pool („Was ist neu“/„Änderungen“ im Admin).

## 1.2.2 – 2026-09-23

Components-Update aus dem Generator-Checkout (Pool-Stand, automatisch mit `dev/build-release.php` erzeugt).

## 1.2.1 – 2026-09-21

Components-Update aus dem Generator-Checkout (Pool-Stand).

## 1.2.0 – 2026-09-20

Instanz-Angleich (Sync), .optim-Router-Fix, KI-Texthilfe – gemeinsam mit Core 2.2.0.

## 1.1.9 – 2026-09-18

Optimierungs-Release (gemeinsam mit Core 1.2.6).