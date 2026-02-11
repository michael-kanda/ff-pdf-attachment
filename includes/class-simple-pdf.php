<?php
/**
 * SimplePDF - Minimaler PDF-Generator in reinem PHP.
 * Keine externen Abhängigkeiten. Unterstützt:
 * - Text mit Helvetica (eingebaut in jeden PDF-Reader)
 * - Tabellen mit Rahmen und Hintergrundfarben
 * - Seitenumbrüche
 * - UTF-8 → Latin1 Konvertierung
 *
 * @package FF_PDF_Attachment
 */

if (!defined('ABSPATH')) {
    exit;
}

class SimplePDF
{
    private $objects = [];
    private $objectCount = 0;
    private $pages = [];
    private $currentPage = '';
    private $currentPageObj = 0;
    private $pageWidth = 595.28;  // A4
    private $pageHeight = 841.89; // A4
    private $margin = 50;
    private $cursorY = 0;
    private $fontSize = 10;
    private $fontSizePt = 10;
    private $lineHeight = 14;
    private $catalogObj = 0;
    private $pagesObj = 0;
    private $fontObj = 0;
    private $fontBoldObj = 0;
    private $buffer = '';

    // Farben
    private $primaryColor = [0.145, 0.388, 0.921]; // #2563eb
    private $grayBg = [0.976, 0.98, 0.984];         // #f9fafb
    private $borderColor = [0.898, 0.906, 0.922];    // #e5e7eb
    private $textColor = [0.122, 0.161, 0.216];      // #1f2937
    private $mutedColor = [0.612, 0.639, 0.682];     // #9ca3af

    public function __construct()
    {
        $this->cursorY = $this->pageHeight - $this->margin;
    }

    /**
     * Akzentfarbe setzen (Hex).
     */
    public function setPrimaryColor($hex)
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 6) {
            $this->primaryColor = [
                hexdec(substr($hex, 0, 2)) / 255,
                hexdec(substr($hex, 2, 2)) / 255,
                hexdec(substr($hex, 4, 2)) / 255,
            ];
        }
    }

    /**
     * Neue Seite beginnen.
     */
    public function addPage()
    {
        if (!empty($this->currentPage)) {
            $this->finalizePage();
        }

        $this->currentPage = '';
        $this->cursorY = $this->pageHeight - $this->margin;
    }

    /**
     * Prüfen ob genug Platz, sonst neue Seite.
     */
    private function ensureSpace($needed)
    {
        if ($this->cursorY - $needed < $this->margin + 30) {
            $this->addPage();
        }
    }

    /**
     * Header mit Titel und Metadaten.
     */
    public function addHeader($title, $meta = '')
    {
        // Titel
        $this->setFont('bold', 16);
        $this->setColor($this->primaryColor);
        $this->addText($title);
        $this->cursorY -= 2;

        // Meta-Zeile
        if (!empty($meta)) {
            $this->setFont('normal', 8);
            $this->setColor($this->mutedColor);
            $this->addText($meta);
        }

        // Trennlinie
        $this->cursorY -= 5;
        $lineY = $this->cursorY;
        $r = $this->primaryColor[0];
        $g = $this->primaryColor[1];
        $b = $this->primaryColor[2];
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f RG\n2 w\n%.2f %.2f m %.2f %.2f l S\n",
            $r, $g, $b,
            $this->margin, $lineY,
            $this->pageWidth - $this->margin, $lineY
        );
        $this->cursorY -= 15;

        // Zurücksetzen
        $this->setFont('normal', 10);
        $this->setColor($this->textColor);
    }

    /**
     * Tabellenzeile mit Label und Wert.
     */
    public function addTableRow($label, $value)
    {
        $contentWidth = $this->pageWidth - (2 * $this->margin);
        $labelWidth = $contentWidth * 0.33;
        $valueWidth = $contentWidth * 0.67;
        $cellPadding = 6;

        // Text umbrechen
        $this->setFont('bold', 9);
        $labelLines = $this->wrapText($label, $labelWidth - (2 * $cellPadding));

        $this->setFont('normal', 9);
        $valueLines = $this->wrapText($value, $valueWidth - (2 * $cellPadding));

        $numLines = max(count($labelLines), count($valueLines));
        $rowHeight = ($numLines * $this->lineHeight) + (2 * $cellPadding);

        // Seitenumbruch prüfen
        $this->ensureSpace($rowHeight);

        $x = $this->margin;
        $y = $this->cursorY;

        // Label-Hintergrund (grau)
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f rg\n%.2f %.2f %.2f %.2f re f\n",
            $this->grayBg[0], $this->grayBg[1], $this->grayBg[2],
            $x, $y - $rowHeight, $labelWidth, $rowHeight
        );

        // Untere Trennlinie
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f RG\n0.5 w\n%.2f %.2f m %.2f %.2f l S\n",
            $this->borderColor[0], $this->borderColor[1], $this->borderColor[2],
            $x, $y - $rowHeight,
            $x + $contentWidth, $y - $rowHeight
        );

        // Label-Text
        $this->setColor($this->textColor);
        $textY = $y - $cellPadding - $this->lineHeight + 3;
        foreach ($labelLines as $line) {
            $this->currentPage .= "BT\n";
            $this->currentPage .= "/F2 9 Tf\n";
            $this->currentPage .= sprintf("%.2f %.2f Td\n", $x + $cellPadding, $textY);
            $this->currentPage .= sprintf("%.3f %.3f %.3f rg\n", $this->textColor[0], $this->textColor[1], $this->textColor[2]);
            $this->currentPage .= "(" . $this->escape($line) . ") Tj\n";
            $this->currentPage .= "ET\n";
            $textY -= $this->lineHeight;
        }

        // Value-Text
        $textY = $y - $cellPadding - $this->lineHeight + 3;
        foreach ($valueLines as $line) {
            $this->currentPage .= "BT\n";
            $this->currentPage .= "/F1 9 Tf\n";
            $this->currentPage .= sprintf("%.2f %.2f Td\n", $x + $labelWidth + $cellPadding, $textY);
            $this->currentPage .= sprintf("%.3f %.3f %.3f rg\n", $this->textColor[0], $this->textColor[1], $this->textColor[2]);
            $this->currentPage .= "(" . $this->escape($line) . ") Tj\n";
            $this->currentPage .= "ET\n";
            $textY -= $this->lineHeight;
        }

        $this->cursorY -= $rowHeight;
    }

    /**
     * Footer-Zeile hinzufügen.
     */
    public function addFooter($text)
    {
        $this->cursorY -= 15;

        // Trennlinie
        $lineY = $this->cursorY;
        $this->currentPage .= sprintf(
            "%.3f %.3f %.3f RG\n0.5 w\n%.2f %.2f m %.2f %.2f l S\n",
            $this->borderColor[0], $this->borderColor[1], $this->borderColor[2],
            $this->margin, $lineY,
            $this->pageWidth - $this->margin, $lineY
        );

        $this->cursorY -= 12;
        $this->setFont('normal', 7);
        $this->setColor($this->mutedColor);

        // Zentriert
        $textWidth = strlen($text) * 3.2; // Approximation
        $x = ($this->pageWidth - $textWidth) / 2;

        $this->currentPage .= "BT\n";
        $this->currentPage .= "/F1 7 Tf\n";
        $this->currentPage .= sprintf("%.2f %.2f Td\n", $x, $this->cursorY);
        $this->currentPage .= sprintf("%.3f %.3f %.3f rg\n", $this->mutedColor[0], $this->mutedColor[1], $this->mutedColor[2]);
        $this->currentPage .= "(" . $this->escape($text) . ") Tj\n";
        $this->currentPage .= "ET\n";
    }

    /**
     * PDF als String ausgeben.
     */
    public function output()
    {
        $this->finalizePage();
        return $this->buildPdf();
    }

    // ── Private Hilfsmethoden ──────────────────────────────────────

    private function addText($text)
    {
        $fontTag = ($this->fontSize > 12) ? '/F2' : '/F1';
        if ($this->fontSizePt === 16) {
            $fontTag = '/F2';
        }

        $this->currentPage .= "BT\n";
        $this->currentPage .= "{$fontTag} {$this->fontSizePt} Tf\n";
        $this->currentPage .= sprintf("%.2f %.2f Td\n", $this->margin, $this->cursorY);
        $this->currentPage .= "(" . $this->escape($text) . ") Tj\n";
        $this->currentPage .= "ET\n";
        $this->cursorY -= $this->lineHeight;
    }

    private function setFont($style, $size)
    {
        $this->fontSizePt = $size;
        $this->fontSize = $size;
        $this->lineHeight = $size * 1.4;
    }

    private function setColor($rgb)
    {
        // Wird bei Text inline gesetzt
    }

    private function wrapText($text, $maxWidth)
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $paragraphs = explode("\n", $text);
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            if (empty(trim($paragraph))) {
                $lines[] = '';
                continue;
            }

            $words = explode(' ', $paragraph);
            $currentLine = '';
            $charWidth = $this->fontSizePt * 0.45; // Approximation für Helvetica

            foreach ($words as $word) {
                $testLine = empty($currentLine) ? $word : $currentLine . ' ' . $word;
                $testWidth = strlen($testLine) * $charWidth;

                if ($testWidth > $maxWidth && !empty($currentLine)) {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                } else {
                    $currentLine = $testLine;
                }
            }

            if (!empty($currentLine)) {
                $lines[] = $currentLine;
            }
        }

        return empty($lines) ? [''] : $lines;
    }

    private function escape($text)
    {
        // UTF-8 → Windows-1252 (WinAnsiEncoding) konvertieren
        // Zuerst spezielle Multi-Byte Unicode-Zeichen ersetzen
        $unicode_map = [
            "\xe2\x80\x93" => "\x96", // – (en dash)
            "\xe2\x80\x94" => "\x97", // — (em dash)
            "\xe2\x80\x98" => "\x91", // '
            "\xe2\x80\x99" => "\x92", // '
            "\xe2\x80\x9c" => "\x93", // "
            "\xe2\x80\x9d" => "\x94", // "
            "\xe2\x80\xa2" => "\x95", // •
            "\xe2\x80\xa6" => "\x85", // …
            "\xe2\x82\xac" => "\x80", // €
        ];
        $text = str_replace(array_keys($unicode_map), array_values($unicode_map), $text);

        // UTF-8 2-Byte Sequenzen → CP1252 konvertieren (ä, ö, ü, ß etc.)
        // UTF-8 pattern: 0xC2 0x80-0xBF → CP1252 0x80-0xBF
        // UTF-8 pattern: 0xC3 0x80-0xBF → Latin1 0xC0-0xFF
        $result = '';
        $len = strlen($text);
        $i = 0;

        while ($i < $len) {
            $byte = ord($text[$i]);

            if ($byte < 0x80) {
                // ASCII
                $result .= $text[$i];
                $i++;
            } elseif ($byte === 0xC2 && $i + 1 < $len) {
                // 0xC2 0x80-0xBF → 0x80-0xBF (Latin supplement)
                $next = ord($text[$i + 1]);
                if ($next >= 0x80 && $next <= 0xBF) {
                    $result .= chr($next);
                    $i += 2;
                } else {
                    $result .= '?';
                    $i++;
                }
            } elseif ($byte === 0xC3 && $i + 1 < $len) {
                // 0xC3 0x80-0xBF → 0xC0-0xFF (äöüß etc.)
                $next = ord($text[$i + 1]);
                if ($next >= 0x80 && $next <= 0xBF) {
                    $result .= chr(0x40 + $next); // 0xC3 0xA4 → 0xE4 (ä)
                    $i += 2;
                } else {
                    $result .= '?';
                    $i++;
                }
            } elseif ($byte >= 0xC0 && $byte < 0xE0) {
                // Andere 2-Byte UTF-8 → ?
                $result .= '?';
                $i += 2;
            } elseif ($byte >= 0xE0 && $byte < 0xF0) {
                // 3-Byte UTF-8 (wurde oben teilweise abgefangen) → ?
                $result .= '?';
                $i += 3;
            } elseif ($byte >= 0xF0) {
                // 4-Byte UTF-8 → ?
                $result .= '?';
                $i += 4;
            } else {
                // Bereits CP1252/Latin1 Byte
                $result .= $text[$i];
                $i++;
            }
        }

        // PDF-Escape: Backslash, Klammern
        $result = str_replace('\\', '\\\\', $result);
        $result = str_replace('(', '\\(', $result);
        $result = str_replace(')', '\\)', $result);

        return $result;
    }

    private function finalizePage()
    {
        if (!empty($this->currentPage)) {
            $this->pages[] = $this->currentPage;
            $this->currentPage = '';
        }
    }

    private function addObject($content)
    {
        $this->objectCount++;
        $this->objects[$this->objectCount] = $content;
        return $this->objectCount;
    }

    private function buildPdf()
    {
        $this->objects = [];
        $this->objectCount = 0;

        // 1. Font: Helvetica (Normal)
        $this->fontObj = $this->addObject(
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>"
        );

        // 2. Font: Helvetica-Bold
        $this->fontBoldObj = $this->addObject(
            "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>"
        );

        // 3. Seiten-Objekte erstellen
        $pageObjectIds = [];
        foreach ($this->pages as $pageContent) {
            // Stream
            $stream = $pageContent;
            $streamLength = strlen($stream);
            $streamObj = $this->addObject(
                "<< /Length {$streamLength} >>\nstream\n{$stream}\nendstream"
            );

            // Page
            $pageObj = $this->addObject("PAGE_PLACEHOLDER_{$streamObj}");
            $pageObjectIds[] = $pageObj;
        }

        // 4. Pages-Objekt
        $kidsList = implode(' ', array_map(function ($id) {
            return "{$id} 0 R";
        }, $pageObjectIds));
        $pageCount = count($pageObjectIds);

        $this->pagesObj = $this->addObject(
            "<< /Type /Pages /Kids [{$kidsList}] /Count {$pageCount} >>"
        );

        // Page-Objekte mit Parent aktualisieren
        foreach ($pageObjectIds as $idx => $pageObjId) {
            $streamObjId = $idx + 3; // fontObj=1, fontBoldObj=2, dann stream+page paare
            $streamObjId = ($idx * 2) + 3; // Stream objects start at 3, pairs of stream+page
            $actualStreamObj = $pageObjId - 1; // Stream is always right before its page

            $this->objects[$pageObjId] = sprintf(
                "<< /Type /Page /Parent %d 0 R /MediaBox [0 0 %.2f %.2f] /Contents %d 0 R /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> >>",
                $this->pagesObj,
                $this->pageWidth,
                $this->pageHeight,
                $actualStreamObj,
                $this->fontObj,
                $this->fontBoldObj
            );
        }

        // 5. Catalog
        $this->catalogObj = $this->addObject(
            "<< /Type /Catalog /Pages {$this->pagesObj} 0 R >>"
        );

        // PDF zusammenbauen
        $output = "%PDF-1.4\n%\xe2\xe3\xcf\xd3\n";
        $offsets = [];

        foreach ($this->objects as $id => $content) {
            $offsets[$id] = strlen($output);
            $output .= "{$id} 0 obj\n{$content}\nendobj\n";
        }

        // Cross-Reference Table
        $xrefOffset = strlen($output);
        $output .= "xref\n";
        $output .= "0 " . ($this->objectCount + 1) . "\n";
        $output .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $this->objectCount; $i++) {
            $output .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        // Trailer
        $output .= "trailer\n";
        $output .= "<< /Size " . ($this->objectCount + 1) . " /Root {$this->catalogObj} 0 R >>\n";
        $output .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $output;
    }
}
