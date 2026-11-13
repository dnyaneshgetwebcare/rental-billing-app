<?php

declare(strict_types=1);

namespace App\Attachment;

use App\Shared\ApiException;
use App\Shared\Settings;
use GdImage;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Stores uploads under uploads/{company}/{yyyy}/{mm}/. Phone photos are re-encoded as WebP
 * (orientation fixed, metadata stripped) in three sizes; PDFs are stored as-is.
 */
final class ImageProcessor
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const THUMB = 300;
    public const MEDIUM = 1200;
    public const FULL = 2000;

    private const IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];
    private const PDF_MIME = 'application/pdf';

    /**
     * @return array{original_name: string, path: string, thumb_path: ?string, medium_path: ?string, mime: string, size: int, width: ?int, height: ?int}
     */
    public function store(UploadedFileInterface $file, int $companyId): array
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            throw ApiException::validation(['file' => match ($file->getError()) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'File is too large.',
                UPLOAD_ERR_NO_FILE => 'Choose a file.',
                default => 'Upload failed. Please try again.',
            }]);
        }
        if (($file->getSize() ?? 0) > self::MAX_BYTES) {
            throw ApiException::validation(['file' => 'File must be 10 MB or smaller.']);
        }

        $tmp = tempnam(sys_get_temp_dir(), 'upl');
        $file->moveTo($tmp);
        try {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
            if (!in_array($mime, [...self::IMAGE_MIMES, self::PDF_MIME], true)) {
                throw ApiException::validation(['file' => 'Only JPG, PNG, WebP images or PDF files are allowed.']);
            }

            $relDir = sprintf('%d/%s', $companyId, date('Y/m'));
            $absDir = Settings::uploadDir() . '/' . $relDir;
            if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir)) {
                throw new \RuntimeException("Cannot create upload directory $absDir");
            }
            $base = bin2hex(random_bytes(12));
            $name = mb_substr(basename((string) $file->getClientFilename()) ?: 'file', 0, 255);

            if ($mime === self::PDF_MIME) {
                $rel = "$relDir/$base.pdf";
                if (!rename($tmp, Settings::uploadDir() . '/' . $rel) && !copy($tmp, Settings::uploadDir() . '/' . $rel)) {
                    throw new \RuntimeException('Cannot save upload');
                }
                return [
                    'original_name' => $name, 'path' => $rel, 'thumb_path' => null, 'medium_path' => null,
                    'mime' => $mime, 'size' => (int) filesize(Settings::uploadDir() . '/' . $rel), 'width' => null, 'height' => null,
                ];
            }

            $image = $this->load($tmp, $mime);
            $width = imagesx($image);
            $height = imagesy($image);
            $paths = [];
            foreach (['path' => [self::FULL, 82], 'medium_path' => [self::MEDIUM, 78], 'thumb_path' => [self::THUMB, 70]] as $key => [$max, $quality]) {
                $suffix = $key === 'path' ? '' : ($key === 'medium_path' ? '_m' : '_t');
                $rel = "$relDir/$base$suffix.webp";
                $resized = $this->fit($image, $max);
                if (!imagewebp($resized, Settings::uploadDir() . '/' . $rel, $quality)) {
                    throw new \RuntimeException('Cannot write WebP image');
                }
                if ($resized !== $image) {
                    imagedestroy($resized);
                }
                $paths[$key] = $rel;
            }
            imagedestroy($image);

            return [
                'original_name' => $name,
                'path' => $paths['path'],
                'thumb_path' => $paths['thumb_path'],
                'medium_path' => $paths['medium_path'],
                'mime' => 'image/webp',
                'size' => (int) filesize(Settings::uploadDir() . '/' . $paths['path']),
                'width' => min($width, 65535),
                'height' => min($height, 65535),
            ];
        } finally {
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /** @param list<?string> $relPaths */
    public function delete(array $relPaths): void
    {
        foreach ($relPaths as $rel) {
            if ($rel === null || $rel === '' || str_contains($rel, '..')) {
                continue;
            }
            $abs = Settings::uploadDir() . '/' . $rel;
            if (is_file($abs)) {
                @unlink($abs);
            }
        }
    }

    private function load(string $path, string $mime): GdImage
    {
        [$w, $h] = getimagesize($path) ?: [0, 0];
        if ($w * $h > 40_000_000) {
            throw ApiException::validation(['file' => 'Image resolution is too large.']);
        }
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
        };
        if (!$image instanceof GdImage) {
            throw ApiException::validation(['file' => 'The image could not be read.']);
        }
        if ($mime === 'image/png') {
            $image = $this->flattenAlpha($image);
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $image = $this->orient($image, (int) ($exif['Orientation'] ?? 1));
        }
        return $image;
    }

    private function orient(GdImage $image, int $orientation): GdImage
    {
        $rotated = match ($orientation) {
            3, 4 => imagerotate($image, 180, 0),
            5, 6 => imagerotate($image, -90, 0),
            7, 8 => imagerotate($image, 90, 0),
            default => $image,
        };
        if ($rotated === false) {
            return $image;
        }
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($rotated, IMG_FLIP_HORIZONTAL);
        }
        if ($rotated !== $image) {
            imagedestroy($image);
        }
        return $rotated;
    }

    private function flattenAlpha(GdImage $image): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
        imagedestroy($image);
        return $canvas;
    }

    private function fit(GdImage $image, int $max): GdImage
    {
        $w = imagesx($image);
        $h = imagesy($image);
        if ($w <= $max && $h <= $max) {
            return $image;
        }
        $ratio = min($max / $w, $max / $h);
        $nw = max(1, (int) round($w * $ratio));
        $nh = max(1, (int) round($h * $ratio));
        $out = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $nw, $nh, $w, $h);
        return $out;
    }
}
