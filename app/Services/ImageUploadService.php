<?php
declare(strict_types=1);
namespace App\Services;

/**
 * Upload gambar yang aman: tipe diperiksa dari ISI file (finfo + getimagesize), bukan ekstensi/nama dari browser;
 * hanya JPG/PNG/WebP (tanpa SVG/GIF); nama file acak; ukuran dan dimensi dibatasi.
 */
final class ImageUploadService
{
    public const MAX_BYTES = 2 * 1024 * 1024;
    public const MAX_DIMENSION = 8000;
    private const MIME_EXT = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    private const IMAGETYPE_MIME = [IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png', IMAGETYPE_WEBP => 'image/webp'];

    private string $root;

    /** @param ?string $uploadRoot Folder fisik "uploads" (default: public/uploads). */
    public function __construct(?string $uploadRoot = null)
    {
        $this->root = rtrim($uploadRoot ?? dirname(__DIR__, 2) . '/public/uploads', '/\\');
    }

    /**
     * @param array{name?:string,tmp_name?:string,error?:int,size?:int} $file Entri dari $_FILES (satu file).
     * @return array{ok:bool,path:?string,error:?string} path relatif ("uploads/products/xxx.png")
     */
    public function store(array $file, string $subdir, bool $requireHttpUpload = true): array
    {
        $fail = static fn(string $m): array => ['ok' => false, 'path' => null, 'error' => $m];
        $name = is_string($file['name'] ?? null) ? basename($file['name']) : 'file';
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return $fail($name . ': ' . match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'file is too large.',
                UPLOAD_ERR_NO_FILE => 'no file was uploaded.',
                default => 'upload failed (code ' . $error . ').',
            });
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp) || ($requireHttpUpload && !is_uploaded_file($tmp))) {
            return $fail($name . ': invalid upload.');
        }
        $size = (int) filesize($tmp);
        if ($size <= 0 || $size > self::MAX_BYTES) {
            return $fail($name . ': file must be between 1 byte and ' . (self::MAX_BYTES / 1024 / 1024) . ' MB.');
        }

        $mime = null;
        if (function_exists('finfo_open') && ($f = finfo_open(FILEINFO_MIME_TYPE)) !== false) {
            $detected = finfo_file($f, $tmp);
            finfo_close($f);
            $mime = is_string($detected) ? $detected : null;
        }
        $info = @getimagesize($tmp);
        if ($info === false || !isset(self::IMAGETYPE_MIME[$info[2]])) {
            return $fail($name . ': only JPG, PNG, or WebP images are allowed.');
        }
        $imageMime = self::IMAGETYPE_MIME[$info[2]];
        if (($mime !== null && $mime !== $imageMime) || !isset(self::MIME_EXT[$imageMime])) {
            return $fail($name . ': only JPG, PNG, or WebP images are allowed.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > self::MAX_DIMENSION || $info[1] > self::MAX_DIMENSION) {
            return $fail($name . ': image dimensions must be at most ' . self::MAX_DIMENSION . ' px.');
        }
        // Pertahanan berlapis terhadap file polyglot (gambar valid yang menyisipkan kode PHP).
        $content = (string) file_get_contents($tmp);
        if (str_contains($content, '<?php') || str_contains($content, '<?=') || str_contains($content, '<script')) {
            return $fail($name . ': file contains disallowed content.');
        }

        $dir = $this->root . '/' . trim($subdir, '/\\');
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return $fail('Upload folder is not writable.');
        }
        $filename = bin2hex(random_bytes(16)) . '.' . self::MIME_EXT[$imageMime];
        $target = $dir . '/' . $filename;
        $moved = $requireHttpUpload ? @move_uploaded_file($tmp, $target) : @copy($tmp, $target);
        if (!$moved) {
            return $fail('Could not save the uploaded file.');
        }
        @chmod($target, 0644);
        return ['ok' => true, 'path' => 'uploads/' . trim($subdir, '/\\') . '/' . $filename, 'error' => null];
    }

    /** Hapus file hanya jika path relatif berada di dalam folder uploads (anti path traversal). */

    /** Hapus file hanya jika path relatif berada di dalam folder uploads. */
    public function delete(?string $relativePath): void
    {
        if (
            $relativePath === null
            || !str_starts_with($relativePath, 'uploads/')
            || str_contains($relativePath, '..')
        ) {
            return;
        }

        $full = $this->root . '/' . substr($relativePath, strlen('uploads/'));
        $real = realpath($full);
        $base = realpath($this->root);

        if ($real === false || $base === false) {
            error_log('[IMAGE DELETE] Path atau root tidak ditemukan. Path: ' . $full);
            return;
        }

        $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

        if (!str_starts_with($real, $prefix) || !is_file($real)) {
            error_log('[IMAGE DELETE] Path tidak valid atau bukan file: ' . $real);
            return;
        }

        if (!unlink($real)) {
            error_log('[IMAGE DELETE] unlink gagal: ' . $real);
            return;
        }

        error_log('[IMAGE DELETE] Berhasil: ' . $real);
    }


    /** Ubah struktur $_FILES['images'] (multiple) menjadi daftar entri per file; entri kosong dibuang. */
    public static function normalize(mixed $files): array
    {
        if (!is_array($files) || !isset($files['name'])) {
            return [];
        }
        if (!is_array($files['name'])) {
            return ($files['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$files];
        }
        $list = [];
        foreach (array_keys($files['name']) as $i) {
            if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $list[] = ['name' => $files['name'][$i] ?? '', 'tmp_name' => $files['tmp_name'][$i] ?? '', 'error' => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$i] ?? 0];
        }
        return $list;
    }
}