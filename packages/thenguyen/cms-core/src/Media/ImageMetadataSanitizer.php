<?php

declare(strict_types=1);

namespace TheNguyen\CMS\Media;

/**
 * Lossless removal of embedded metadata from uploaded raster images (CORE-P12-1).
 *
 * Pixels are never decoded or re-encoded: the container is rewritten keeping only the segments
 * needed to display the image. Removed: EXIF (incl. GPS, camera serials), XMP, IPTC, comments,
 * text chunks and any bytes after the end of the image (polyglot payloads). Kept: the colour
 * profile, and for JPEG the EXIF Orientation value, rewritten as a minimal one-tag EXIF block so
 * rotated photos still display upright.
 *
 * - JPEG: APP0 (JFIF), APP2 ICC_PROFILE, APP14 (Adobe) and all coding segments kept; other APPn
 *         and COM dropped; entropy data copied up to EOI.
 * - PNG:  chunk allowlist (critical + colour/animation); tEXt/zTXt/iTXt/eXIf/tIME dropped.
 * - WebP: EXIF and XMP chunks dropped and their VP8X flags cleared.
 * - GIF:  comment and non-animation application extensions dropped.
 * - AVIF: cannot be rewritten losslessly here; refused when it embeds EXIF or XMP items.
 *
 * {@see sanitize()} returns the cleaned bytes, or null when the file cannot be parsed — callers
 * must then reject the upload (fail closed).
 */
final class ImageMetadataSanitizer
{
    private const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";

    private const PNG_KEEP = [
        'IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'gAMA', 'cHRM', 'sRGB', 'iCCP', 'sBIT', 'pHYs', 'bKGD',
        'hIST', 'acTL', 'fcTL', 'fdAT', 'cICP',
    ];

    public function sanitize(string $bytes, string $mime): ?string
    {
        try {
            return match ($mime) {
                'image/jpeg' => $this->jpeg($bytes),
                'image/png' => $this->png($bytes),
                'image/webp' => $this->webp($bytes),
                'image/gif' => $this->gif($bytes),
                'image/avif' => $this->avif($bytes),
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------ JPEG

    private function jpeg(string $b): ?string
    {
        $len = strlen($b);
        if ($len < 4 || substr($b, 0, 2) !== "\xFF\xD8") {
            return null;
        }
        $out = "\xFF\xD8";
        $orientation = null;
        $exifAt = null;
        $pos = 2;

        while ($pos < $len) {
            if ($b[$pos] !== "\xFF") {
                return null;
            }
            while ($pos < $len && $b[$pos] === "\xFF") {
                $pos++;   // fill bytes
            }
            if ($pos >= $len) {
                return null;
            }
            $marker = ord($b[$pos]);
            $pos++;

            if ($marker === 0xD9) {
                return $this->withOrientation($out."\xFF\xD9", $exifAt, $orientation);
            }
            if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD7)) {
                $out .= "\xFF".chr($marker);

                continue;
            }
            if ($pos + 2 > $len) {
                return null;
            }
            $segLen = unpack('n', substr($b, $pos, 2))[1];
            if ($segLen < 2 || $pos + $segLen > $len) {
                return null;
            }
            $payload = substr($b, $pos + 2, $segLen - 2);
            $segment = "\xFF".chr($marker).substr($b, $pos, $segLen);
            $pos += $segLen;

            if ($marker === 0xE1 && str_starts_with($payload, "Exif\0\0")) {
                $orientation ??= $this->exifOrientation(substr($payload, 6));
                $exifAt ??= strlen($out);

                continue;
            }
            if ($marker === 0xFE || ($marker >= 0xE0 && $marker <= 0xEF && ! $this->keepJpegApp($marker, $payload))) {
                continue;
            }
            $out .= $segment;

            if ($marker === 0xDA) {
                // Entropy-coded data runs until the next marker that is not stuffing or RSTn.
                $start = $pos;
                while ($pos + 1 < $len) {
                    if ($b[$pos] === "\xFF") {
                        $next = ord($b[$pos + 1]);
                        if ($next !== 0x00 && $next !== 0xFF && ($next < 0xD0 || $next > 0xD7)) {
                            break;
                        }
                    }
                    $pos++;
                }
                if ($pos + 1 >= $len) {
                    return null;   // no EOI
                }
                $out .= substr($b, $start, $pos - $start);
            }
        }

        return null;
    }

    private function keepJpegApp(int $marker, string $payload): bool
    {
        return ($marker === 0xE0 && (str_starts_with($payload, "JFIF\0") || str_starts_with($payload, "JFXX\0")))
            || ($marker === 0xE2 && str_starts_with($payload, "ICC_PROFILE\0"))
            || ($marker === 0xEE && str_starts_with($payload, 'Adobe'));
    }

    /** Orientation (1–8) from a TIFF block, or null. */
    private function exifOrientation(string $tiff): ?int
    {
        if (strlen($tiff) < 8) {
            return null;
        }
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return null;
        }
        $u16 = static fn (int $at): int => unpack($order === 'II' ? 'v' : 'n', substr($tiff, $at, 2))[1];
        $u32 = static fn (int $at): int => unpack($order === 'II' ? 'V' : 'N', substr($tiff, $at, 4))[1];
        $ifd = $u32(4);
        if ($ifd < 8 || $ifd + 2 > strlen($tiff)) {
            return null;
        }
        $count = $u16($ifd);
        for ($i = 0; $i < $count && $ifd + 2 + ($i + 1) * 12 <= strlen($tiff); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($u16($entry) === 0x0112) {
                $value = $u16($entry + 8);

                return $value >= 1 && $value <= 8 ? $value : null;
            }
        }

        return null;
    }

    private function withOrientation(string $jpeg, ?int $at, ?int $orientation): string
    {
        if ($at === null || $orientation === null || $orientation === 1) {
            return $jpeg;
        }
        // Big-endian TIFF, IFD0 with one SHORT entry (Orientation), no next IFD.
        $tiff = "MM\x00\x2A".pack('N', 8).pack('n', 1).pack('nnN', 0x0112, 3, 1).pack('n', $orientation)."\x00\x00".pack('N', 0);
        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;

        return substr($jpeg, 0, $at).$segment.substr($jpeg, $at);
    }

    // ------------------------------------------------------------------ PNG

    private function png(string $b): ?string
    {
        if (! str_starts_with($b, self::PNG_SIGNATURE)) {
            return null;
        }
        $out = self::PNG_SIGNATURE;
        $pos = 8;
        $len = strlen($b);
        while ($pos + 12 <= $len) {
            $dataLen = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            if ($dataLen > $len - $pos - 12 || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
                return null;
            }
            $chunk = substr($b, $pos, $dataLen + 12);
            $pos += $dataLen + 12;
            if (in_array($type, self::PNG_KEEP, true)) {
                $out .= $chunk;
            } elseif (ctype_upper($type[0])) {
                return null;   // unknown critical chunk: cannot be dropped safely
            }
            if ($type === 'IEND') {
                return $out;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ WebP

    private function webp(string $b): ?string
    {
        if (strlen($b) < 12 || substr($b, 0, 4) !== 'RIFF' || substr($b, 8, 4) !== 'WEBP') {
            return null;
        }
        $riffEnd = 8 + unpack('V', substr($b, 4, 4))[1];
        if ($riffEnd > strlen($b) || $riffEnd < 12) {
            return null;
        }
        $body = '';
        $pos = 12;
        while ($pos + 8 <= $riffEnd) {
            $fourcc = substr($b, $pos, 4);
            $size = unpack('V', substr($b, $pos + 4, 4))[1];
            $padded = $size + ($size & 1);
            if ($pos + 8 + $size > $riffEnd) {
                return null;
            }
            $chunk = substr($b, $pos, 8 + $padded);
            $pos += 8 + $padded;
            if ($fourcc === 'EXIF' || $fourcc === 'XMP ') {
                continue;
            }
            if ($fourcc === 'VP8X' && $size >= 10) {
                // Clear the EXIF (0x08) and XMP (0x04) flags.
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }
            $body .= $chunk;
        }
        if ($body === '') {
            return null;
        }

        return 'RIFF'.pack('V', strlen($body) + 4).'WEBP'.$body;
    }

    // ------------------------------------------------------------------ GIF

    private function gif(string $b): ?string
    {
        $len = strlen($b);
        if ($len < 13 || (substr($b, 0, 6) !== 'GIF87a' && substr($b, 0, 6) !== 'GIF89a')) {
            return null;
        }
        $flags = ord($b[10]);
        $pos = 13 + (($flags & 0x80) ? 3 * (2 << ($flags & 0x07)) : 0);
        if ($pos > $len) {
            return null;
        }
        $out = substr($b, 0, $pos);

        while ($pos < $len) {
            $intro = $b[$pos];
            if ($intro === "\x3B") {
                return $out."\x3B";
            }
            if ($intro === "\x21") {
                if ($pos + 2 > $len) {
                    return null;
                }
                $label = ord($b[$pos + 1]);
                $end = $this->gifSubBlocksEnd($b, $pos + 2);
                if ($end === null) {
                    return null;
                }
                $block = substr($b, $pos, $end - $pos);
                $pos = $end;
                $keep = match ($label) {
                    0xFE => false,                                                       // comment
                    0xFF => in_array(substr($block, 3, 11), ['NETSCAPE2.0', 'ANIMEXTS1.0'], true),
                    default => true,                                                     // GCE, plain text
                };
                if ($keep) {
                    $out .= $block;
                }

                continue;
            }
            if ($intro === "\x2C") {
                if ($pos + 10 > $len) {
                    return null;
                }
                $local = ord($b[$pos + 9]);
                $dataAt = $pos + 10 + (($local & 0x80) ? 3 * (2 << ($local & 0x07)) : 0) + 1;   // + LZW min code size
                $end = $dataAt <= $len ? $this->gifSubBlocksEnd($b, $dataAt) : null;
                if ($end === null) {
                    return null;
                }
                $out .= substr($b, $pos, $end - $pos);
                $pos = $end;

                continue;
            }

            return null;
        }

        return null;
    }

    private function gifSubBlocksEnd(string $b, int $pos): ?int
    {
        $len = strlen($b);
        while ($pos < $len) {
            $size = ord($b[$pos]);
            $pos += 1 + $size;
            if ($size === 0) {
                return $pos <= $len ? $pos : null;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ AVIF

    /** AVIF is kept byte-for-byte when it carries no EXIF/XMP item; otherwise refused. */
    private function avif(string $b): ?string
    {
        if (strlen($b) < 12 || substr($b, 4, 4) !== 'ftyp') {
            return null;
        }
        $pos = 0;
        $len = strlen($b);
        while ($pos + 8 <= $len) {
            $size = unpack('N', substr($b, $pos, 4))[1];
            $type = substr($b, $pos + 4, 4);
            if ($size === 1 && $pos + 16 <= $len) {
                $size = (int) unpack('J', substr($b, $pos + 8, 8))[1];
            } elseif ($size === 0) {
                $size = $len - $pos;
            }
            if ($size < 8 || $pos + $size > $len) {
                return null;
            }
            if ($type === 'meta') {
                $meta = substr($b, $pos, $size);
                if (str_contains($meta, 'Exif') || str_contains($meta, 'application/rdf+xml')) {
                    return null;
                }
            }
            $pos += $size;
        }

        return $pos === $len ? $b : null;
    }
}
