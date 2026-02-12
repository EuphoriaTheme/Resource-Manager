<?php

declare(strict_types=1);

namespace Pterodactyl\Http\Controllers\Admin\Extensions\resourcemanager;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\View\Factory as ViewFactory;
use Illuminate\View\View;
use Pterodactyl\BlueprintFramework\Libraries\ExtensionLibrary\Admin\BlueprintAdminLibrary as BlueprintAdminLibrary;
use Pterodactyl\Http\Controllers\Controller;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class resourcemanagerExtensionController extends Controller
{
    private const UPLOADS_RELATIVE_PATH = 'extensions/resourcemanager/uploads';
    private const MAX_UPLOAD_KB = 20480;

    /**
     * Extensions allowed for both listing and uploading.
     *
     * WARNING: Some formats such as SVG, TIFF, HEIF/HEIC and ICO can contain active or less-supported
     * content (scripts in SVG) or may not be handled consistently by all servers/browsers.
     * If you enable these, ensure server-side checks and sanitization (especially for SVG) and
     * restrict uploads to trusted admins.
     */
    private const ALLOWED_EXTENSIONS = ['svg', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'ico', 'tif', 'tiff', 'heif', 'heic'];

    public function __construct(
        private ViewFactory $view,
        private BlueprintAdminLibrary $blueprint,
    ) {
    }

    public function index(Request $request): View
    {
        $this->assertRootAdmin($request);

        return $this->view->make('admin.extensions.{identifier}.index', [
            'root' => '/admin/extensions/{identifier}',
            'blueprint' => $this->blueprint,
        ]);
    }

    public function showUploadsForm(Request $request): View
    {
        // Backwards-compatible alias for older route names/paths.
        return $this->index($request);
    }

    public function uploadImage(Request $request): JsonResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'image' => [
                'required',
                'file',
                'image',
                'mimes:' . implode(',', self::ALLOWED_EXTENSIONS),
                'max:' . self::MAX_UPLOAD_KB,
            ],
        ]);

        $file = $request->file('image');
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'No file uploaded.'], 400);
        }

        $uploadsDir = public_path(self::UPLOADS_RELATIVE_PATH);
        File::ensureDirectoryExists($uploadsDir, 0755, true);

        $ext = strtolower($file->extension() ?: '');
        if ($ext === '' || !in_array($ext, self::ALLOWED_EXTENSIONS, true)) {
            return response()->json(['success' => false, 'message' => 'File type not allowed.'], 422);
        }

        // Use a readable slug + random suffix to avoid collisions and avoid unsafe filenames.
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $base = Str::slug($base);
        if ($base === '') {
            $base = 'image';
        }

        $filename = sprintf('%s_%s.%s', $base, Str::random(8), $ext);

        // Sanitize uploads for all supported formats before writing.
        try {
            $sanitized = $this->sanitizeUploadedFile($file, $ext);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'File sanitization failed.'], 422);
        }

        $targetPath = $uploadsDir . DIRECTORY_SEPARATOR . $filename;
        File::put($targetPath, $sanitized);

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully.',
            'file' => [
                'name' => $filename,
                'url' => asset(self::UPLOADS_RELATIVE_PATH . '/' . $filename),
            ],
        ]);
    }

    /**
     * Sanitize an uploaded file's contents for supported image formats.
     *
     * Returns the sanitized binary blob to be written to disk.
     *
     * @throws \RuntimeException
     */
    private function sanitizeUploadedFile(\Illuminate\Http\UploadedFile $file, string $ext): string
    {
        $ext = strtolower($ext);

        if ($ext === 'svg') {
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                throw new \RuntimeException('Unable to read SVG.');
            }

            libxml_use_internal_errors(true);
            $dom = new \DOMDocument();

            // Disable network access when parsing.
            if (!@$dom->loadXML($contents, LIBXML_NONET)) {
                libxml_clear_errors();
                throw new \RuntimeException('Invalid SVG provided.');
            }

            // Remove dangerous elements entirely.
            $dangerTags = ['script', 'foreignObject', 'iframe', 'object', 'embed', 'link', 'meta', 'base'];
            foreach ($dangerTags as $tag) {
                $rem = [];
                foreach ($dom->getElementsByTagName($tag) as $n) {
                    $rem[] = $n;
                }
                foreach ($rem as $n) {
                    if ($n->parentNode) {
                        $n->parentNode->removeChild($n);
                    }
                }
            }

            // Sanitize attributes on all elements.
            $all = [];
            foreach ($dom->getElementsByTagName('*') as $node) {
                $all[] = $node;
            }

            foreach ($all as $node) {
                if (!$node->hasAttributes()) {
                    continue;
                }

                $attrs = [];
                foreach ($node->attributes as $a) {
                    $attrs[] = $a->name;
                }

                foreach ($attrs as $name) {
                    $lname = strtolower($name);
                    $value = $node->getAttribute($name);

                    if (preg_match('/^on/i', $name)) {
                        $node->removeAttribute($name);
                        continue;
                    }

                    if (in_array($lname, ['href', 'xlink:href', 'src'], true) && preg_match('/^\s*javascript:/i', $value)) {
                        $node->removeAttribute($name);
                        continue;
                    }

                    if ($lname === 'style' && $value !== '') {
                        $clean = preg_replace([
                            '/url\s*\(\s*[^)]+?\)/i',
                            '/javascript\s*:/i',
                            '/expression\s*\(/i',
                            '/behavior\s*\(/i',
                        ], '', $value);

                        $clean = trim($clean);
                        if ($clean === '') {
                            $node->removeAttribute($name);
                        } else {
                            $node->setAttribute($name, $clean);
                        }
                        continue;
                    }
                }
            }

            $root = $dom->documentElement;
            if ($root === null) {
                throw new \RuntimeException('Sanitized SVG has no root element.');
            }

            return $dom->saveXML($root);
        }

        // Try Imagick first for robust re-encoding and metadata stripping.
        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick();
                $im->readImage($file->getPathname());
                $im->stripImage();

                $formatMap = [
                    'jpg' => 'jpeg', 'jpeg' => 'jpeg', 'png' => 'png', 'gif' => 'gif',
                    'webp' => 'webp', 'bmp' => 'bmp', 'tif' => 'tiff', 'tiff' => 'tiff',
                    'avif' => 'avif', 'heif' => 'heic', 'heic' => 'heic', 'ico' => 'ico',
                ];

                $targetFormat = $formatMap[$ext] ?? strtolower($im->getImageFormat());
                $im->setImageFormat($targetFormat);
                $blob = $im->getImagesBlob();
                $im->clear();
                $im->destroy();

                if (!$blob) {
                    throw new \RuntimeException('Imagick produced empty output.');
                }

                return $blob;
            } catch (\Throwable $e) {
                // continue to GD fallback
            }
        }

        // GD fallback: re-encode which will drop metadata. Preserve alpha for PNG/GIF where possible.
        $data = @file_get_contents($file->getPathname());
        if ($data === false) {
            throw new \RuntimeException('Unable to read uploaded file.');
        }

        $img = @imagecreatefromstring($data);
        if ($img === false) {
            throw new \RuntimeException('Unsupported image format or corrupt file.');
        }

        ob_start();

        // Preserve PNG/GIF alpha where applicable.
        if (in_array($ext, ['png', 'gif', 'webp'], true)) {
            $w = imagesx($img);
            $h = imagesy($img);
            $canvas = imagecreatetruecolor($w, $h);
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
            imagefilledrectangle($canvas, 0, 0, $w, $h, $transparent);
            imagecopy($canvas, $img, 0, 0, 0, 0, $w, $h);
            imagedestroy($img);
            $img = $canvas;
        }

        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                imagejpeg($img, null, 90);
                break;
            case 'png':
                imagesavealpha($img, true);
                imagepng($img, null, 6);
                break;
            case 'gif':
                imagegif($img);
                break;
            case 'webp':
                if (!function_exists('imagewebp')) {
                    imagedestroy($img);
                    ob_end_clean();
                    throw new \RuntimeException('WebP not supported by GD on this system.');
                }
                imagewebp($img, null, 80);
                break;
            case 'bmp':
                if (!function_exists('imagebmp')) {
                    imagepng($img, null, 6);
                } else {
                    imagebmp($img);
                }
                break;
            default:
                imagedestroy($img);
                ob_end_clean();
                throw new \RuntimeException('Unsupported image format for sanitization.');
        }

        $out = ob_get_clean();
        imagedestroy($img);

        if ($out === false || $out === '') {
            throw new \RuntimeException('Failed to re-encode image with GD.');
        }

        return $out;
    }

    public function listImages(Request $request): JsonResponse
    {
        $this->assertRootAdmin($request);

        $uploadsDir = public_path(self::UPLOADS_RELATIVE_PATH);
        if (!File::exists($uploadsDir)) {
            return response()->json(['success' => true, 'files' => []]);
        }

        $files = collect(File::files($uploadsDir))
            ->filter(fn ($file) => in_array(strtolower($file->getExtension()), self::ALLOWED_EXTENSIONS, true))
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->map(fn ($file) => [
                'name' => $file->getFilename(),
                'url' => asset(self::UPLOADS_RELATIVE_PATH . '/' . $file->getFilename()),
                'size' => $file->getSize(),
                'last_modified' => $file->getMTime(),
            ])
            ->values()
            ->all();

        return response()->json(['success' => true, 'files' => $files]);
    }

    public function deleteImage(Request $request): JsonResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'filename' => ['required', 'string', 'max:255'],
        ]);

        $uploadsDir = public_path(self::UPLOADS_RELATIVE_PATH);
        $uploadsDirReal = realpath($uploadsDir);
        if ($uploadsDirReal === false) {
            return response()->json(['success' => false, 'message' => 'Uploads directory not found.'], 404);
        }

        $filename = basename((string) $request->input('filename'));
        $filePath = $uploadsDirReal . DIRECTORY_SEPARATOR . $filename;
        $fileReal = realpath($filePath);

        // Prevent path traversal / deleting outside uploads.
        if ($fileReal === false || strpos($fileReal, $uploadsDirReal) !== 0) {
            return response()->json(['success' => false, 'message' => 'File not found or invalid.'], 404);
        }

        File::delete($fileReal);

        return response()->json(['success' => true, 'message' => 'Image deleted successfully.']);
    }

    private function assertRootAdmin(Request $request): void
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException();
        }
    }
}
