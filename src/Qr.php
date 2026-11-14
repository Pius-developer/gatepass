<?php
declare(strict_types=1);

/**
 * A small QR code generator, written from the spec (ISO/IEC 18004) so the app
 * needs no packages and ticket codes are never sent to a third-party QR service.
 *
 * Supports what tickets need: byte mode, error correction level M, versions 1–10
 * (up to 213 characters). Output: a matrix of booleans, a PNG (GD) or an SVG.
 */
final class Qr
{
    // Per version (index 1..10) for level M: [ec codewords per block, [[blocks, data codewords per block], ...]]
    private const BLOCKS = [
        1  => [10, [[1, 16]]],
        2  => [16, [[1, 28]]],
        3  => [26, [[1, 44]]],
        4  => [18, [[2, 32]]],
        5  => [24, [[2, 43]]],
        6  => [16, [[4, 27]]],
        7  => [18, [[4, 31]]],
        8  => [22, [[2, 38], [2, 39]]],
        9  => [22, [[3, 36], [2, 37]]],
        10 => [26, [[4, 43], [1, 44]]],
    ];

    private const ALIGN = [
        1 => [], 2 => [6, 18], 3 => [6, 22], 4 => [6, 26], 5 => [6, 30],
        6 => [6, 34], 7 => [6, 22, 38], 8 => [6, 24, 42], 9 => [6, 26, 46], 10 => [6, 28, 50],
    ];

    /** @var array<int, array<int, bool>> */
    private array $m = [];
    /** @var array<int, array<int, bool>> true where a module is part of a fixed pattern */
    private array $fixed = [];
    private int $size = 0;

    /** @return array<int, array<int, bool>> rows of modules, true = dark */
    public static function matrix(string $text): array
    {
        return (new self())->build($text);
    }

    public static function png(string $text, int $scale = 8, int $quiet = 4): string
    {
        $mx = self::matrix($text);
        $n = count($mx);
        $px = ($n + 2 * $quiet) * $scale;
        $img = imagecreate($px, $px);
        $white = imagecolorallocate($img, 255, 255, 255);
        $black = imagecolorallocate($img, 17, 17, 17);
        imagefill($img, 0, 0, $white);
        foreach ($mx as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $x0 = ($x + $quiet) * $scale;
                    $y0 = ($y + $quiet) * $scale;
                    imagefilledrectangle($img, $x0, $y0, $x0 + $scale - 1, $y0 + $scale - 1, $black);
                }
            }
        }
        ob_start();
        imagepng($img);
        imagedestroy($img);
        return (string) ob_get_clean();
    }

    public static function svg(string $text, int $quiet = 4): string
    {
        $mx = self::matrix($text);
        $n = count($mx) + 2 * $quiet;
        $d = '';
        foreach ($mx as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $d .= 'M' . ($x + $quiet) . ' ' . ($y + $quiet) . 'h1v1h-1z';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $n . ' ' . $n . '" shape-rendering="crispEdges">'
            . '<rect width="100%" height="100%" fill="#fff"/><path fill="#111" d="' . $d . '"/></svg>';
    }

    // ---- Building ----------------------------------------------------------------

    private function build(string $text): array
    {
        $version = $this->pickVersion(strlen($text));
        $this->size = 17 + 4 * $version;
        $this->m = array_fill(0, $this->size, array_fill(0, $this->size, false));
        $this->fixed = $this->m;

        $this->placeFixedPatterns($version);
        $codewords = $this->interleave($this->dataCodewords($text, $version), $version);
        $this->placeData($codewords);

        // Try all 8 masks and keep the one the spec's penalty rules like best.
        $best = null;
        $bestScore = PHP_INT_MAX;
        $base = $this->m;
        for ($mask = 0; $mask < 8; $mask++) {
            $this->m = $base;
            $this->applyMask($mask);
            $this->placeFormat($mask);
            $score = $this->penalty();
            if ($score < $bestScore) {
                $bestScore = $score;
                $best = $this->m;
            }
        }
        return $best;
    }

    private function pickVersion(int $bytes): int
    {
        foreach (self::BLOCKS as $v => [, $groups]) {
            $capacity = 0;
            foreach ($groups as [$count, $data]) {
                $capacity += $count * $data;
            }
            $headerBits = 4 + ($v < 10 ? 8 : 16);
            if ($headerBits + 8 * $bytes <= 8 * $capacity) {
                return $v;
            }
        }
        throw new InvalidArgumentException('Text is too long for a QR code here.');
    }

    /** @return int[] data codewords, padded to the version's capacity */
    private function dataCodewords(string $text, int $version): array
    {
        $capacity = 0;
        foreach (self::BLOCKS[$version][1] as [$count, $data]) {
            $capacity += $count * $data;
        }

        $bits = '0100';                                                     // byte mode
        $bits .= str_pad(decbin(strlen($text)), $version < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($text) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $bits .= str_repeat('0', min(4, 8 * $capacity - strlen($bits)));   // terminator
        $bits .= str_repeat('0', (8 - strlen($bits) % 8) % 8);             // byte align

        $out = array_map('bindec', str_split($bits, 8));
        for ($pad = 0; count($out) < $capacity; $pad++) {
            $out[] = $pad % 2 ? 0x11 : 0xEC;
        }
        return $out;
    }

    /** Split into blocks, add Reed–Solomon error correction, interleave. @return int[] */
    private function interleave(array $data, int $version): array
    {
        [$ecLen, $groups] = self::BLOCKS[$version];
        $blocks = [];
        $offset = 0;
        foreach ($groups as [$count, $len]) {
            for ($i = 0; $i < $count; $i++) {
                $chunk = array_slice($data, $offset, $len);
                $offset += $len;
                $blocks[] = [$chunk, self::reedSolomon($chunk, $ecLen)];
            }
        }

        $out = [];
        $maxData = max(array_map(fn ($b) => count($b[0]), $blocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($blocks as [$d]) {
                if (isset($d[$i])) $out[] = $d[$i];
            }
        }
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($blocks as [, $ec]) {
                $out[] = $ec[$i];
            }
        }
        return $out;
    }

    // ---- Reed–Solomon over GF(256) ---------------------------------------------

    private static array $exp = [];
    private static array $log = [];

    private static function gf(): void
    {
        if (self::$exp) return;
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$exp[$i] = $x;
            self::$log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) $x ^= 0x11D;
        }
        for ($i = 255; $i < 512; $i++) {
            self::$exp[$i] = self::$exp[$i - 255];
        }
    }

    private static function mul(int $a, int $b): int
    {
        return ($a === 0 || $b === 0) ? 0 : self::$exp[self::$log[$a] + self::$log[$b]];
    }

    /** @return int[] $n error-correction codewords for $data */
    public static function reedSolomon(array $data, int $n): array
    {
        self::gf();
        // Generator polynomial: (x - a^0)(x - a^1)...(x - a^(n-1))
        $gen = [1];
        for ($i = 0; $i < $n; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            foreach ($gen as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::mul($coef, self::$exp[$i]);
            }
            $gen = $next;
        }
        // Polynomial division; the remainder is the EC codewords.
        $rem = array_fill(0, $n, 0);
        foreach ($data as $byte) {
            $factor = $byte ^ $rem[0];
            array_shift($rem);
            $rem[] = 0;
            for ($j = 0; $j < $n; $j++) {
                $rem[$j] ^= self::mul($gen[$j + 1], $factor);
            }
        }
        return $rem;
    }

    // ---- Placing modules -------------------------------------------------------

    private function set(int $x, int $y, bool $dark, bool $fixed = true): void
    {
        $this->m[$y][$x] = $dark;
        if ($fixed) $this->fixed[$y][$x] = true;
    }

    private function placeFixedPatterns(int $version): void
    {
        $n = $this->size;
        // Finder patterns with their white separators
        foreach ([[0, 0], [$n - 7, 0], [0, $n - 7]] as [$fx, $fy]) {
            for ($dy = -1; $dy <= 7; $dy++) {
                for ($dx = -1; $dx <= 7; $dx++) {
                    $x = $fx + $dx; $y = $fy + $dy;
                    if ($x < 0 || $y < 0 || $x >= $n || $y >= $n) continue;
                    $ring = max(abs($dx - 3), abs($dy - 3));
                    $this->set($x, $y, $ring !== 2 && $ring !== 4);
                }
            }
        }
        // Timing patterns
        for ($i = 8; $i < $n - 8; $i++) {
            $this->set($i, 6, $i % 2 === 0);
            $this->set(6, $i, $i % 2 === 0);
        }
        // Alignment patterns (skip the three that would sit on finders)
        $pos = self::ALIGN[$version];
        $last = count($pos) - 1;
        foreach ($pos as $i => $ay) {
            foreach ($pos as $j => $ax) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $last) || ($i === $last && $j === 0)) continue;
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->set($ax + $dx, $ay + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        // Dark module, and reserve the format areas (filled per mask later)
        $this->set(8, $n - 8, true);
        for ($i = 0; $i < 9; $i++) {
            if (!$this->fixed[8][$i]) $this->set($i, 8, false);
            if (!$this->fixed[$i][8]) $this->set(8, $i, false);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->set($n - 1 - $i, 8, false);
            if (!$this->fixed[$n - 1 - $i][8]) $this->set(8, $n - 1 - $i, false);
        }
        // Version information (versions 7+)
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
            }
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 18; $i++) {
                $dark = (($bits >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->set($a, $b, $dark);
                $this->set($b, $a, $dark);
            }
        }
    }

    private function placeData(array $codewords): void
    {
        $bits = '';
        foreach ($codewords as $c) {
            $bits .= str_pad(decbin($c), 8, '0', STR_PAD_LEFT);
        }
        $n = $this->size;
        $i = 0;
        $len = strlen($bits);
        // Zig-zag up and down in two-column strips from the right, skipping the timing column.
        for ($right = $n - 1; $right >= 1; $right -= 2) {
            if ($right === 6) $right = 5;
            for ($vert = 0; $vert < $n; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $n - 1 - $vert : $vert;
                    if ($this->fixed[$y][$x]) continue;
                    $this->m[$y][$x] = $i < $len && $bits[$i] === '1';
                    $i++;
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->fixed[$y][$x]) continue;
                $flip = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($y, 2) + intdiv($x, 3)) % 2 === 0,
                    5 => ($x * $y) % 2 + ($x * $y) % 3 === 0,
                    6 => (($x * $y) % 2 + ($x * $y) % 3) % 2 === 0,
                    7 => (($x + $y) % 2 + ($x * $y) % 3) % 2 === 0,
                };
                if ($flip) $this->m[$y][$x] = !$this->m[$y][$x];
            }
        }
    }

    private function placeFormat(int $mask): void
    {
        $data = (0b00 << 3) | $mask;            // level M = 00
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;
        $n = $this->size;
        for ($i = 0; $i < 15; $i++) {
            $dark = (($bits >> $i) & 1) === 1;
            // Copy 1, around the top-left finder
            if ($i < 6)       $this->m[$i][8] = $dark;
            elseif ($i < 8)   $this->m[$i + 1][8] = $dark;
            else              $this->m[8][14 - $i + ($i === 8 ? 1 : 0)] = $dark;
            // Copy 2, split between the other two finders
            if ($i < 8)       $this->m[8][$n - 1 - $i] = $dark;
            else              $this->m[$n - 15 + $i][8] = $dark;
        }
        $this->m[$n - 8][8] = true;   // the dark module
    }

    // ---- Mask scoring (ISO 18004, section 7.8.3) -----------------------------------

    private function penalty(): int
    {
        $n = $this->size;
        $m = $this->m;
        $score = 0;

        // Rule 1: runs of 5+ same-colour modules in a row/column
        for ($y = 0; $y < $n; $y++) {
            foreach ([true, false] as $rows) {
                $run = 1;
                for ($x = 1; $x < $n; $x++) {
                    $a = $rows ? $m[$y][$x] : $m[$x][$y];
                    $b = $rows ? $m[$y][$x - 1] : $m[$x - 1][$y];
                    if ($a === $b) {
                        $run++;
                    } else {
                        if ($run >= 5) $score += $run - 2;
                        $run = 1;
                    }
                }
                if ($run >= 5) $score += $run - 2;
            }
        }
        // Rule 2: 2x2 blocks of one colour
        for ($y = 0; $y < $n - 1; $y++) {
            for ($x = 0; $x < $n - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $score += 3;
                }
            }
        }
        // Rule 3: finder-like patterns 1:1:3:1:1 with 4 light modules on a side
        $p1 = [1, 0, 1, 1, 1, 0, 1, 0, 0, 0, 0];
        $p2 = [0, 0, 0, 0, 1, 0, 1, 1, 1, 0, 1];
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x <= $n - 11; $x++) {
                foreach ([true, false] as $rows) {
                    $ok1 = $ok2 = true;
                    for ($k = 0; $k < 11; $k++) {
                        $v = (int) ($rows ? $m[$y][$x + $k] : $m[$x + $k][$y]);
                        if ($v !== $p1[$k]) $ok1 = false;
                        if ($v !== $p2[$k]) $ok2 = false;
                    }
                    if ($ok1) $score += 40;
                    if ($ok2) $score += 40;
                }
            }
        }
        // Rule 4: balance of dark and light
        $dark = 0;
        foreach ($m as $row) {
            foreach ($row as $v) $dark += (int) $v;
        }
        $score += intdiv(abs($dark * 20 - $n * $n * 10), $n * $n) * 10;

        return $score;
    }
}
