## 1.2.4 – 2026-09-24

content-block: Layout-Fix für die Person-Card – md:grid-cols-[1fr_320px] lag unmittelbar am Twig-Tag, der Tailwind-Scanner übersprang die Klasse und das statische CSS fehlte (Card lief vollbreit unter den Text). Klasse jetzt in {% set %} als String-Literal; tailwind.css aller Themes neu gebaut.

## 1.2.3 – 2026-09-23

Changelog-Integration: CHANGELOG.md im Components-Pool („Was ist neu“/„Änderungen“ im Admin).

# Changelog – Components

## 1.2.2 – 2026-09-23

Components-Update aus dem Generator-Checkout (Pool-Stand, automatisch mit `dev/build-release.php` erzeugt).

## 1.2.1 – 2026-09-21

Components-Update aus dem Generator-Checkout (Pool-Stand).

## 1.2.0 – 2026-09-20

Instanz-Angleich (Sync), .optim-Router-Fix, KI-Texthilfe – gemeinsam mit Core 2.2.0.

## 1.1.9 – 2026-09-18

Optimierungs-Release (gemeinsam mit Core 1.2.6).