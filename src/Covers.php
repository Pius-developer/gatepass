<?php
declare(strict_types=1);

/**
 * Event cover images. The browser already shrinks the photo before upload
 * (see app.js), but the server never trusts that: it checks the file really is
 * an image, then redraws it with GD at a fixed size. Redrawing throws away
 * anything hidden inside the file, so only clean pixels are ever saved.
 */
final class Covers
{
    public const WIDTH = 1600;
    public const HEIGHT = 800;   // 2:1
    private const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(private string $dir) {}

    /** @return string saved file name. Throws DomainException with a message for the user. */
    public function store(array $upload): string
    {
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) {
            throw new DomainException('The image didn\'t upload. Try again.');
        }
        return $this->storeFile($upload['tmp_name'], (int) $upload['size']);
    }

    /** Separate from store() so tests can use a normal file. */
    public function storeFile(string $path, int $bytes): string
    {
        if ($bytes > self::MAX_BYTES) {
            throw new DomainException('That image is over 8 MB. Pick a smaller one.');
        }
        $info = @getimagesize($path);
        $src = match ($info[2] ?? null) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };
        if (!$src) {
            throw new DomainException('Use a JPG, PNG or WebP image.');
        }
        if ($info[0] * $info[1] > 40_000_000) {
            throw new DomainException('That image is too large. Try one under 40 megapixels.');
        }

        // Fill a 2:1 frame, cropping the middle (like CSS object-fit: cover).
        [$w, $h] = [imagesx($src), imagesy($src)];
        $scale = max(self::WIDTH / $w, self::HEIGHT / $h);
        $cw = (int) round(self::WIDTH / $scale);
        $ch = (int) round(self::HEIGHT / $scale);
        $out = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagecopyresampled($out, $src, 0, 0, intdiv($w - $cw, 2), intdiv($h - $ch, 2), self::WIDTH, self::HEIGHT, $cw, $ch);

        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0755, true);
        }
        $name = bin2hex(random_bytes(12)) . '.jpg';
        imageinterlace($out, true);
        imagejpeg($out, $this->dir . '/' . $name, 84);
        imagedestroy($src);
        imagedestroy($out);
        return $name;
    }

    public function delete(?string $name): void
    {
        if ($name && preg_match('/^[a-f0-9]{24}\.jpg$/', $name)) {
            @unlink($this->dir . '/' . $name);
        }
    }
}
