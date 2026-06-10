<?php
declare(strict_types=1);

namespace App\Controllers;

use App\DomainError;
use App\Json;
use App\Services\S3Uploader;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Ramsey\Uuid\Uuid;

/**
 * POST /api/uploads (JWT) — accept ONE image (multipart field "file"), validate it
 * SERVER-SIDE (real magic-byte type + size, never trusting the client), store it in
 * the NAS object store under posts/<userId>/<uuid>.<ext>, and return its permanent
 * public URL. The image is referenced by <img src> directly from the public endpoint
 * (no proxy/presign). The gateway holds the S3 secret; the browser never sees it.
 */
final class MediaController
{
    private const MAX_BYTES = 5 * 1024 * 1024;   // 5 MB
    private const ALLOWED   = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(private S3Uploader $s3) {}

    public function upload(Request $req, Response $res): Response
    {
        $userId = (int) ($req->getAttribute('user_id') ?? 0);
        if ($userId <= 0) {
            throw new DomainError(401, 'UNAUTHORIZED', 'Bạn cần đăng nhập.');
        }
        if (!$this->s3->isConfigured()) {
            throw new DomainError(503, 'UPLOAD_UNAVAILABLE', 'Lưu trữ ảnh chưa sẵn sàng.');
        }

        $file = $req->getUploadedFiles()['file'] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            throw new DomainError(400, 'VALIDATION_FAILED', 'Không nhận được tệp ảnh.');
        }
        $declared = (int) $file->getSize();
        if ($declared > self::MAX_BYTES) {
            throw new DomainError(413, 'FILE_TOO_LARGE', 'Ảnh tối đa 5MB.');
        }

        $bytes = (string) $file->getStream();
        if ($bytes === '' || strlen($bytes) > self::MAX_BYTES) {
            throw new DomainError(413, 'FILE_TOO_LARGE', 'Ảnh không hợp lệ hoặc vượt quá 5MB.');
        }

        // Sniff the REAL type by magic bytes — do not trust the client-declared mime.
        $mime = $this->sniffImageMime($bytes);
        if ($mime === null) {
            throw new DomainError(415, 'UNSUPPORTED_TYPE', 'Chỉ hỗ trợ ảnh JPG, PNG, WEBP, GIF.');
        }

        // Defense-in-depth: a valid magic-byte PREFIX is not enough (a polyglot could
        // carry arbitrary trailing bytes). Require the payload to actually PARSE as an
        // image whose detected type matches the sniffed mime, with sane dimensions.
        $info = @getimagesizefromstring($bytes);
        $byType = [
            IMAGETYPE_JPEG => 'image/jpeg', IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_GIF  => 'image/gif',  IMAGETYPE_WEBP => 'image/webp',
        ];
        if ($info === false || !isset($byType[$info[2]]) || $byType[$info[2]] !== $mime) {
            throw new DomainError(415, 'UNSUPPORTED_TYPE', 'Tệp không phải ảnh hợp lệ.');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] > 10000 || $info[1] > 10000) {
            throw new DomainError(422, 'INVALID_IMAGE', 'Kích thước ảnh không hợp lệ.');
        }

        $key = 'posts/' . $userId . '/' . Uuid::uuid4()->toString() . '.' . self::ALLOWED[$mime];

        if (!$this->s3->put($key, $bytes, $mime)) {
            throw new DomainError(502, 'UPLOAD_FAILED', 'Tải ảnh lên lưu trữ không thành công.');
        }

        return Json::ok($res, [
            'url' => $this->s3->publicUrl($key),
            'key' => $key,
        ]);
    }

    /** Return the canonical image mime from the leading magic bytes, or null. */
    private function sniffImageMime(string $b): ?string
    {
        if (strncmp($b, "\xFF\xD8\xFF", 3) === 0)                         return 'image/jpeg';
        if (strncmp($b, "\x89PNG\r\n\x1a\n", 8) === 0)                    return 'image/png';
        if (strncmp($b, 'GIF87a', 6) === 0 || strncmp($b, 'GIF89a', 6) === 0) return 'image/gif';
        if (strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP')  return 'image/webp';
        return null;
    }
}
