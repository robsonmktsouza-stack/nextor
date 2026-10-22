<?php

namespace App\Services\Receipts;

use RuntimeException;

/**
 * Gerador síncrono de comprovante comercial térmico em PDF.
 * Não executa Chrome/Edge, não usa arquivos temporários nem serviços externos.
 * Cada via tem exatamente uma página com altura calculada pelo conteúdo.
 */
final class ThermalReceiptPdfWriter
{
    private const PT_PER_MM = 72 / 25.4;
    private array $operations = [];
    private float $y = 0;
    private float $width;
    private float $margin;
    private float $inner;
    private ?array $logo = null;

    public function __construct(int $paperWidth = 80)
    {
        $this->width = in_array($paperWidth, [58, 80], true) ? $paperWidth : 80;
        $this->margin = $this->width === 58 ? 3.5 : 4;
        $this->inner = $this->width - 2 * $this->margin;
    }

    public function render(array $data): string
    {
        $logoData = $data['logo'] ?? null;
        if (is_string($logoData) && strlen($logoData) <= 4 * 1024 * 1024) {
            $this->logo = $this->parseImage($logoData);
        }
        $pages = [];
        $copies = max(1, min(5, (int) ($data['copies'] ?? 1)));
        for ($copy = 1; $copy <= $copies; $copy++) {
            $this->operations = [];
            $this->y = $this->margin;
            $this->compose($data, $copy, $copies);
            $pages[] = ['height' => max(50, ceil($this->y + $this->margin + 1)), 'operations' => $this->operations];
        }
        return $this->pdf($pages);
    }

    private function compose(array $d, int $copy, int $copies): void
    {
        if ($this->logo) {
            $w = min($this->inner * 0.66, 46.0);
            $h = $w * $this->logo['height'] / $this->logo['width'];
            if ($h > 18) {
                $h = 18;
                $w = $h * $this->logo['width'] / $this->logo['height'];
            }
            $this->operations[] = ['kind' => 'image', 'x' => ($this->width - $w) / 2, 'y' => $this->y, 'w' => $w, 'h' => $h];
            $this->y += $h + 2;
        }
        $this->paragraph((string) ($d['company'] ?? ''), $this->width === 58 ? 9.5 : 11, true, 'center', 0.9);
        foreach (['document', 'address', 'contact', 'print_header'] as $key) {
            if (trim((string) ($d[$key] ?? '')) !== '') {
                $this->paragraph((string) $d[$key], $key === 'print_header' ? 7.4 : 7.3, false, 'center', 0.7);
            }
        }
        $this->y += 1.2;
        $this->paragraph('COMPROVANTE DE VENDA', 9.7, true, 'center', 0.7);
        $this->outlineTitle('SEM VALOR FISCAL');
        $this->y += 1;
        $this->paragraph('Venda nº ' . str_pad((string) ($d['sale_id'] ?? '0'), 9, '0', STR_PAD_LEFT), 7.9, false, 'center', 0.4);
        if (!empty($d['date'])) $this->paragraph((string) $d['date'], 7.5, false, 'center', 0.4);
        if (!empty($d['operator'])) $this->paragraph('Operador: ' . (string) $d['operator'], 7.5, false, 'center', 0.4);
        if ($copies > 1) $this->paragraph('Via ' . $copy . '/' . $copies, 7.2, false, 'center', 0.4);
        $this->y += 1;

        $this->rule();
        $this->items((array) ($d['items'] ?? []));
        $this->rule();
        $this->twoColumns('Subtotal', (string) ($d['subtotal'] ?? '0,00'), 8.5);
        if (!empty($d['discount'])) $this->twoColumns('Desconto', '- ' . (string) $d['discount'], 8.3);
        $this->twoColumns('Total', (string) ($d['total'] ?? '0,00'), 11, true);
        $this->rule();
        foreach ((array) ($d['payments'] ?? []) as $payment) {
            $this->twoColumns((string) ($payment['name'] ?? 'Outro'), (string) ($payment['amount'] ?? ''), 8.2);
        }
        if (!empty($d['cash_payment'])) {
            $this->twoColumns('Recebido em dinheiro', (string) ($d['cash_received'] ?? ''), 7.8);
            $this->twoColumns('Troco', (string) ($d['change'] ?? ''), 7.8);
        }
        $this->rule();
        $this->twoColumns('Cliente', (string) ($d['customer'] ?? 'Consumidor não identificado'), 7.8);
        $this->twoColumns('CPF/CNPJ na nota', (string) ($d['consumer_document'] ?? 'Não informado'), 7.7);
        if (!empty($d['consumer_name'])) $this->twoColumns('Consumidor', (string) $d['consumer_name'], 7.7);
        if (!empty($d['notes'])) {
            $this->rule();
            $this->paragraph((string) $d['notes'], 7.6, false, 'center');
        }
        $this->rule();
        if (!empty($d['print_footer'])) $this->paragraph((string) $d['print_footer'], 7.3, false, 'center', 0.7);
        $this->paragraph('Comprovante interno de venda. Não substitui documento fiscal.', 7.2, false, 'center', 0.6);
    }

    private function outlineTitle(string $title): void
    {
        $h = 6.0;
        $this->operations[] = ['kind' => 'rect', 'x' => $this->margin, 'y' => $this->y, 'w' => $this->inner, 'h' => $h];
        $this->write($title, $this->width / 2, $this->y + 1, 8.2, true, 'center');
        $this->y += $h + 1;
    }

    private function rule(): void
    {
        $this->y += 1;
        $this->operations[] = ['kind' => 'rule', 'y' => $this->y, 'x1' => $this->margin, 'x2' => $this->width - $this->margin];
        $this->y += 2.0;
    }

    /**
     * Formato compacto e legível para papel térmico de 58 ou 80 mm:
     * nome do produto acima, quantidade x valor unitário e subtotal abaixo.
     */
    private function items(array $items): void
    {
        $nameSize = $this->width === 58 ? 8.3 : 9.2;
        $detailSize = $this->width === 58 ? 7.4 : 8.0;
        $valueSize = $this->width === 58 ? 8.0 : 8.8;
        $this->paragraph('ITENS DA VENDA', 8.8, true, 'left', .2);
        foreach ($items as $item) {
            $this->y += 1.5;
            $name = (string) ($item['description'] ?? '');
            foreach ($this->wrap($name, $this->inner - 1, $nameSize) as $line) {
                $this->write($line, $this->margin, $this->y, $nameSize, true);
                $this->y += $nameSize * .39 + .9;
            }
            $code = trim((string) ($item['code'] ?? '-'));
            if ($code !== '' && $code !== '-') {
                foreach ($this->wrap('Cod.: ' . $code, $this->inner - 1, $detailSize) as $line) {
                    $this->write($line, $this->margin, $this->y, $detailSize);
                    $this->y += $detailSize * .39 + .8;
                }
            }
            $quantity = (string) ($item['quantity'] ?? '1');
            $unit = (string) ($item['unit'] ?? '0,00');
            $amount = (string) ($item['amount'] ?? '0,00');
            $left = $this->wrap($quantity . ' x R$ ' . $unit, $this->inner * .60, $detailSize);
            $right = $this->wrap('R$ ' . $amount, $this->inner * .37, $valueSize);
            $step = max($detailSize, $valueSize) * .39 + .9;
            foreach ($left as $i => $line) {
                $this->write($line, $this->margin, $this->y + $i * $step, $detailSize);
            }
            foreach ($right as $i => $line) {
                $this->write($line, $this->width - $this->margin,
                    $this->y + $i * $step, $valueSize, true, 'right');
            }
            $this->y += max(count($left), count($right)) * $step + 1.2;
            if (!empty($item['discount'])) {
                $this->twoColumns('Desconto do item', '- R$ ' . (string) $item['discount'], $detailSize);
            }
            $this->operations[] = [
                'kind' => 'rule', 'y' => $this->y,
                'x1' => $this->margin, 'x2' => $this->width - $this->margin, 'gray' => .7,
            ];
            $this->y += 1.6;
        }
    }

    private function twoColumns(string $label, string $value, float $size = 7.0, bool $bold = false): void
    {
        $left = $this->wrap($label, $this->inner * .48, $size);
        $right = $this->wrap($value, $this->inner * .49, $size);
        $step = $size * .39 + .7;
        foreach ($left as $i => $line) $this->write($line, $this->margin, $this->y + $i * $step, $size, $bold);
        foreach ($right as $i => $line) $this->write($line, $this->width - $this->margin, $this->y + $i * $step, $size, $bold, 'right');
        $this->y += max(count($left), count($right)) * $step + .5;
    }

    private function paragraph(string $text, float $size = 6.7, bool $bold = false, string $align = 'left', float $gap = 1): void
    {
        $lineHeight = $size * .39 + .75;
        foreach (preg_split('/\R/u', $text) ?: [''] as $part) {
            foreach ($this->wrap(trim($part), $this->inner - 1, $size) as $line) {
                $x = match ($align) {
                    'center' => $this->width / 2,
                    'right' => $this->width - $this->margin,
                    default => $this->margin,
                };
                $this->write($line, $x, $this->y, $size, $bold, $align);
                $this->y += $lineHeight;
            }
        }
        $this->y += $gap;
    }

    private function write(string $text, float $x, float $y, float $size, bool $bold = false, string $align = 'left'): void
    {
        if ($text === '') return;
        $this->operations[] = ['kind' => 'text', 'x' => $x, 'y' => $y,
            'size' => $size, 'bold' => $bold, 'align' => $align, 'text' => $text];
    }

    private function wrap(string $text, float $maxWidth, float $size): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
        if ($text === '') return [''];
        $lines = [];
        $current = '';
        foreach (explode(' ', $text) as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            if ($this->textWidth($candidate, $size) <= $maxWidth) {
                $current = $candidate;
                continue;
            }
            if ($current !== '') {
                $lines[] = $current;
                $current = '';
            }
            $fragment = '';
            foreach (preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
                if ($fragment !== '' && $this->textWidth($fragment . $char, $size) > $maxWidth) {
                    $lines[] = $fragment;
                    $fragment = '';
                }
                $fragment .= $char;
            }
            $current = $fragment;
        }
        if ($current !== '') $lines[] = $current;
        return $lines ?: [''];
    }

    private function textWidth(string $value, float $size): float
    {
        $encoded = $this->encode($value);
        $units = 0;
        $chars = strlen($encoded);
        for ($i = 0; $i < $chars; $i++) {
            $c = $encoded[$i];
            $units += match (true) {
                $c === ' ' => .30,
                str_contains('MW@%', $c) => .89,
                str_contains('ilI.,:;!|', $c) => .30,
                ctype_upper($c) => .64,
                ctype_digit($c) => .57,
                default => .55,
            };
        }
        return $units * $size / self::PT_PER_MM;
    }

    private function encode(string $value): string
    {
        $text = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $value);
        return $text === false ? '' : preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', '', $text);
    }

    private function pdf(array $pages): string
    {
        $objects = [];
        $add = function (string $content) use (&$objects): int {
            $objects[] = $content;
            return count($objects);
        };
        $catalog = $add('');
        $pagesRoot = $add('');
        $regular = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>');
        $bold = $add('<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>');
        $imageObj = null;
        if ($this->logo) {
            $alphaObj = null;
            if (isset($this->logo['alpha'])) {
                $alpha = $this->logo['alpha'];
                $alphaObj = $add('<< /Type /XObject /Subtype /Image /Width ' . $this->logo['width'] .
                    ' /Height ' . $this->logo['height'] . ' /ColorSpace /DeviceGray /BitsPerComponent 8 /Filter /FlateDecode /Length ' . strlen($alpha) . " >>\nstream\n" . $alpha . "\nendstream");
            }
            $pixels = $this->logo['data'];
            $imageObj = $add('<< /Type /XObject /Subtype /Image /Width ' . $this->logo['width'] .
                ' /Height ' . $this->logo['height'] . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /' .
                $this->logo['filter'] . ($alphaObj ? ' /SMask ' . $alphaObj . ' 0 R' : '') .
                ' /Length ' . strlen($pixels) . " >>\nstream\n" . $pixels . "\nendstream");
        }
        $children = [];
        foreach ($pages as $page) {
            $height = $page['height'];
            $commands = [];
            foreach ($page['operations'] as $op) {
                switch ($op['kind']) {
                    case 'text':
                        $text = $this->encode($op['text']);
                        if ($text === '') break;
                        $x = $op['x'];
                        if ($op['align'] === 'right') $x -= $this->textWidth($op['text'], $op['size']);
                        if ($op['align'] === 'center') $x -= $this->textWidth($op['text'], $op['size']) / 2;
                        $y = ($height - $op['y']) * self::PT_PER_MM - $op['size'];
                        $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
                        $commands[] = sprintf('BT /F%d %.2F Tf 1 0 0 1 %.2F %.2F Tm (%s) Tj ET',
                            $op['bold'] ? 2 : 1, $op['size'], $x * self::PT_PER_MM, $y, $escaped);
                        break;
                    case 'rule':
                        $gray = $op['gray'] ?? 0;
                        $y = ($height - $op['y']) * self::PT_PER_MM;
                        $commands[] = sprintf('%.2F G %.2F %.2F m %.2F %.2F l S 0 G', $gray,
                            $op['x1'] * self::PT_PER_MM, $y, $op['x2'] * self::PT_PER_MM, $y);
                        break;
                    case 'rect':
                        $commands[] = sprintf('%.2F %.2F %.2F %.2F re S',
                            $op['x'] * self::PT_PER_MM, ($height - $op['y'] - $op['h']) * self::PT_PER_MM,
                            $op['w'] * self::PT_PER_MM, $op['h'] * self::PT_PER_MM);
                        break;
                    case 'image':
                        if ($imageObj) $commands[] = sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /Logo Do Q',
                            $op['w'] * self::PT_PER_MM, $op['h'] * self::PT_PER_MM,
                            $op['x'] * self::PT_PER_MM, ($height - $op['y'] - $op['h']) * self::PT_PER_MM);
                        break;
                }
            }
            $stream = implode("\n", $commands) . "\n";
            $content = $add('<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . 'endstream');
            $resources = '<< /Font << /F1 ' . $regular . ' 0 R /F2 ' . $bold . ' 0 R >>';
            if ($imageObj) $resources .= ' /XObject << /Logo ' . $imageObj . ' 0 R >>';
            $resources .= ' >>';
            $pageId = $add('<< /Type /Page /Parent ' . $pagesRoot . ' 0 R /MediaBox [0 0 ' .
                sprintf('%.2F %.2F', $this->width * self::PT_PER_MM, $height * self::PT_PER_MM) .
                '] /Resources ' . $resources . ' /Contents ' . $content . ' 0 R >>');
            $children[] = $pageId . ' 0 R';
        }
        $objects[$catalog - 1] = '<< /Type /Catalog /Pages ' . $pagesRoot . ' 0 R >>';
        $objects[$pagesRoot - 1] = '<< /Type /Pages /Kids [' . implode(' ', $children) . '] /Count ' . count($pages) . ' >>';

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1) . " 0 obj\n" . $object . "\nendobj\n";
        }
        $start = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
        $pdf .= 'trailer << /Size ' . (count($objects) + 1) . ' /Root ' . $catalog . " 0 R >>\n";
        $pdf .= "startxref\n" . $start . "\n%%EOF\n";
        return $pdf;
    }

    private function parseImage(string $bytes): ?array
    {
        $details = @getimagesizefromstring($bytes);
        if (!$details || $details[0] <= 0 || $details[1] <= 0 || $details[0] * $details[1] > 2_000_000) return null;
        [$width, $height] = $details;
        if (($details['mime'] ?? '') === 'image/jpeg' && ($details['channels'] ?? 3) === 3) {
            return ['width' => $width, 'height' => $height, 'filter' => 'DCTDecode', 'data' => $bytes];
        }
        // Descompressão de PNG grande em PHP puro bloqueia o worker do Laragon.
        // O service converte logo grande em JPEG reduzido quando GD está disponível.
        if ($width * $height > 250000) return null;
        if (($details['mime'] ?? '') !== 'image/png' || substr($bytes, 0, 8) !== "\x89PNG\r\n\x1a\n") return null;
        $cursor = 8;
        $compression = '';
        $palette = '';
        $transparency = '';
        $type = null;
        $depth = null;
        $interlace = null;
        while ($cursor + 12 <= strlen($bytes)) {
            $length = unpack('N', substr($bytes, $cursor, 4))[1];
            $chunkType = substr($bytes, $cursor + 4, 4);
            if ($length > 4 * 1024 * 1024 || $cursor + 12 + $length > strlen($bytes)) return null;
            $part = substr($bytes, $cursor + 8, $length);
            $cursor += 12 + $length;
            if ($chunkType === 'IHDR') {
                if ($length < 13) return null;
                $depth = ord($part[8]);
                $type = ord($part[9]);
                $interlace = ord($part[12]);
            } elseif ($chunkType === 'PLTE') $palette = $part;
            elseif ($chunkType === 'tRNS') $transparency = $part;
            elseif ($chunkType === 'IDAT') $compression .= $part;
            elseif ($chunkType === 'IEND') break;
        }
        if ($depth !== 8 || $interlace !== 0 || !in_array($type, [0, 2, 3, 4, 6], true)) return null;
        $channels = match ($type) { 0, 3 => 1, 2 => 3, 4 => 2, 6 => 4 };
        $raw = @gzuncompress($compression);
        $pitch = $width * $channels;
        if ($raw === false || strlen($raw) < ($pitch + 1) * $height) return null;
        $rgb = '';
        $alpha = '';
        $needsAlpha = false;
        $above = array_fill(0, $pitch, 0);
        $cursor = 0;
        for ($y = 0; $y < $height; $y++) {
            $filter = ord($raw[$cursor++]);
            if ($filter > 4) return null;
            $current = [];
            for ($i = 0; $i < $pitch; $i++) {
                $original = ord($raw[$cursor++]);
                $left = $i >= $channels ? $current[$i - $channels] : 0;
                $up = $above[$i];
                $upperLeft = $i >= $channels ? $above[$i - $channels] : 0;
                $predictor = match ($filter) {
                    0 => 0, 1 => $left, 2 => $up, 3 => intdiv($left + $up, 2),
                    4 => $this->paeth($left, $up, $upperLeft),
                };
                $current[$i] = ($original + $predictor) & 255;
            }
            for ($x = 0; $x < $width; $x++) {
                $k = $x * $channels;
                if ($type === 6 || $type === 2) {
                    $r = $current[$k]; $g = $current[$k + 1]; $b = $current[$k + 2];
                    $a = $type === 6 ? $current[$k + 3] : 255;
                } elseif ($type === 4 || $type === 0) {
                    $r = $g = $b = $current[$k];
                    $a = $type === 4 ? $current[$k + 1] : 255;
                } else {
                    $index = $current[$k];
                    if (($index * 3 + 2) >= strlen($palette)) return null;
                    $r = ord($palette[$index * 3]); $g = ord($palette[$index * 3 + 1]); $b = ord($palette[$index * 3 + 2]);
                    $a = $index < strlen($transparency) ? ord($transparency[$index]) : 255;
                }
                $rgb .= chr($r) . chr($g) . chr($b);
                $alpha .= chr($a);
                if ($a !== 255) $needsAlpha = true;
            }
            $above = $current;
        }
        $result = ['width' => $width, 'height' => $height, 'filter' => 'FlateDecode', 'data' => gzcompress($rgb, 6)];
        if ($needsAlpha) $result['alpha'] = gzcompress($alpha, 6);
        return $result;
    }

    private function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $da = abs($p - $a); $db = abs($p - $b); $dc = abs($p - $c);
        return ($da <= $db && $da <= $dc) ? $a : (($db <= $dc) ? $b : $c);
    }
}
