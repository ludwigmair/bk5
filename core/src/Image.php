<?php

declare(strict_types=1);

namespace Core;

/**
 * Responsive Bild-Varianten für den uploads-Pool. Beim Upload wird pro Bild
 * eine Reihe kleinerer JPEG/PNG/WebP-Kopien unter uploads/.optim/ erzeugt
 * (Original bleibt unangetastet), die Templates über den Twig-Helfer
 * `img_src()` als `src`/`srcset` ausliefern. Ein 6000px-Original muss so nicht
 * mehr in eine 180px-Karte geladen werden.
 *
 * Bewusst dependency-frei (nur GD + getimagesize), damit die reine
 * Pfad-/Größen-Logik in tests/run.php ohne Core\CMS getestet werden kann –
 * gleiches Prinzip wie RichText/Markdown.
 */
final class Image
{
    /** Breiten der generierten Varianten (aufsteigend). Es wird nie hochskaliert. */
    public const VARIANT_WIDTHS = [480, 960, 1600, 1920];

    /** Unterordner im uploads-Pool. Führender Punkt: listUploads() ignoriert ihn, keine Pool-Einträge. */
    public const VARIANT_DIR = '.optim';

    /** Dateiendungen, für die überhaupt Varianten erzeugt werden (SVG/GIF bleiben unverändert). */
    private const RASTER_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /**
     * Erzeugt aus einer Bilddatei die Varianten bis VARIANT_WIDTHS unter
     * uploads/.optim/. Liefert true, sobald mindestens eine Variante
     * geschrieben wurde. Ohne GD, bei SVG/GIF oder unlesbaren Dateien: false
     * (kein Fehler – der Aufrufer liefert dann einfach das Original aus).
     */
    public static function generateVariants(string $file): bool
    {
        if (!is_file($file) || !function_exists('imagecreatetruecolor')) {
            return false;
        }

        $info = @getimagesize($file);
        if ($info === false) {
            return false;
        }
        $srcW = (int) $info[0];
        $srcH = (int) $info[1];
        $mime = (string) ($info['mime'] ?? '');
        $create = match ($mime) {
            'image/jpeg' => 'imagecreatefromjpeg',
            'image/png' => 'imagecreatefrompng',
            'image/webp' => 'imagecreatefromwebp',
            default => null,
        };
        if ($create === null || $srcW < 1 || $srcH < 1) {
            return false;
        }

        $source = @$create($file);
        if ($source === false) {
            return false;
        }

        $dir = dirname($file) . '/' . self::VARIANT_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $base = pathinfo($file, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        $generated = false;
        foreach (self::VARIANT_WIDTHS as $targetW) {
            if ($targetW >= $srcW) {
                continue; // nicht vergrößern
            }
            $targetH = max(1, (int) round($srcH * $targetW / $srcW));
            $canvas = imagecreatetruecolor($targetW, $targetH);
            if ($canvas === false) {
                continue;
            }
            if ($mime === 'image/png' || $mime === 'image/webp') {
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);
            }
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetW, $targetH, $srcW, $srcH);

            $target = $dir . '/' . $base . '-' . $targetW . '.' . $ext;
            $ok = match ($mime) {
                'image/jpeg' => imagejpeg($canvas, $target, 80),
                'image/png' => imagepng($canvas, $target, 8),
                'image/webp' => imagewebp($canvas, $target, 80),
                default => false,
            };
            if ($ok) {
                $generated = true;
            } else {
                @unlink($target);
            }
        }

        return $generated;
    }

    /**
     * Erzeugt Varianten für alle Raster-Bilder im angegebenen uploads-Ordner
     * (nur oberste Ebene, keine Unterordner) – für den "Thumbnails nachziehen"-
     * Button in der Bildverwaltung, damit auch vor dieser Funktion hochgeladene
     * Bilder Varianten bekommen.
     *
     * @return array{checked:int, generated:int}
     */
    public static function generateAllVariants(string $uploadsDir): array
    {
        $checked = 0;
        $generated = 0;
        if (!is_dir($uploadsDir)) {
            return ['checked' => 0, 'generated' => 0];
        }
        foreach (scandir($uploadsDir) ?: [] as $entry) {
            if ($entry === '' || $entry[0] === '.') {
                continue;
            }
            $file = $uploadsDir . '/' . $entry;
            if (!is_file($file)) {
                continue;
            }
            $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
            if (!in_array($ext, self::RASTER_EXTENSIONS, true)) {
                continue;
            }
            $checked++;
            if (self::generateVariants($file)) {
                $generated++;
            }
        }

        return ['checked' => $checked, 'generated' => $generated];
    }

    /** Löscht die zu einer Bilddatei gehörenden Varianten (beim Bild-Löschen). */
    public static function removeVariants(string $file): void
    {
        $dir = dirname($file) . '/' . self::VARIANT_DIR;
        $base = pathinfo($file, PATHINFO_FILENAME);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        foreach (self::VARIANT_WIDTHS as $targetW) {
            @unlink($dir . '/' . $base . '-' . $targetW . '.' . $ext);
        }
    }

    /**
     * Auflösung eines Bildpfads für die Public-Seite:
     *  - externe http(s)-URLs (z. B. Unsplash) unverändert,
     *  - Bilder im uploads-Pool mit vorhandenen Varianten: srcset + Primär-src,
     *  - alles andere (Theme-Assets, Bilder vor dem Nachziehen): Originalpfad,
     *    aber mit echten Maßen für width/height (weniger Layout-Sprung).
     *
     * $maxWidth wählt nur die Primär-quelle (Fallback/No-JS) – `srcset` listet
     * trotzdem alle vorhandenen Breiten, der Browser entscheidet anhand `sizes`.
     *
     * @return array{src:string, srcset:string, width:int|null, height:int|null}
     */
    public static function responsive(string $src, int $maxWidth, string $root): array
    {
        $src = trim($src);
        if ($src === '') {
            return ['src' => '', 'srcset' => '', 'width' => null, 'height' => null];
        }
        if (preg_match('#^https?://#i', $src)) {
            return ['src' => $src, 'srcset' => '', 'width' => null, 'height' => null];
        }

        $rel = ltrim($src, '/');
        $file = $root . '/' . $rel;
        $url = '/' . $rel;

        $entries = [];
        if (str_starts_with($rel, 'uploads/') && is_file($file)) {
            $base = pathinfo($rel, PATHINFO_FILENAME);
            $ext = strtolower(pathinfo($rel, PATHINFO_EXTENSION));
            foreach (self::VARIANT_WIDTHS as $width) {
                $variantRel = 'uploads/' . self::VARIANT_DIR . '/' . $base . '-' . $width . '.' . $ext;
                if (is_file($root . '/' . $variantRel)) {
                    $entries[$width] = '/' . $variantRel;
                }
            }
        }

        if ($entries === []) {
            [$width, $height] = self::dimensions($file);

            return ['src' => $url, 'srcset' => '', 'width' => $width, 'height' => $height];
        }

        ksort($entries);
        $srcset = [];
        foreach ($entries as $width => $variantUrl) {
            $srcset[] = $variantUrl . ' ' . $width . 'w';
        }

        $primaryWidth = null;
        foreach (array_keys($entries) as $width) {
            if ($width <= $maxWidth) {
                $primaryWidth = $width;
            }
        }
        if ($primaryWidth === null) {
            $primaryWidth = (int) array_key_first($entries);
        }
        $primaryUrl = $entries[$primaryWidth];
        [$width, $height] = self::dimensions($root . '/' . ltrim($primaryUrl, '/'));

        return ['src' => $primaryUrl, 'srcset' => implode(', ', $srcset), 'width' => $width, 'height' => $height];
    }

    /** @return array{0:int|null, 1:int|null} */
    private static function dimensions(string $file): array
    {
        if (!is_file($file)) {
            return [null, null];
        }
        $info = @getimagesize($file);
        if ($info === false) {
            return [null, null];
        }

        return [(int) $info[0], (int) $info[1]];
    }
}
