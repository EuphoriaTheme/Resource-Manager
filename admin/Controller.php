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
    /**
     * Blueprint's writable, publicly linked extension filesystem.
     *
     * Do not use Storage::disk('{fs}') here. Some Blueprint versions register
     * the extension disk incorrectly, causing Laravel to receive an array as
     * the disk name. Blueprint exposes this directory through {webroot/fs}.
     */
    private const FILESYSTEM_DIRECTORY = '{root}/storage/extensions/{identifier}';
    private const BUNDLED_ASSETS_DIRECTORY = '{root/public}/uploads';
    private const MAX_UPLOAD_KB = 20480;

    /**
     * Base extensions that work with GD (the fallback image processor).
     * These formats can be re-encoded without requiring Imagick.
     *
     * WARNING: SVG can contain active content (scripts). Server-side sanitization is applied,
     * but only upload trusted SVG files from trusted admins.
     */
    private const BASE_EXTENSIONS = ['svg', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];

    /**
     * Advanced extensions that require Imagick with codec support.
     * These formats cannot be re-encoded by GD and will fail if Imagick is unavailable.
     *
     * WARNING: TIFF, HEIF/HEIC and ICO are less-supported formats that may not be handled
     * consistently by all browsers. Restrict uploads to trusted admins.
     */
    private const IMAGICK_ONLY_EXTENSIONS = ['avif', 'ico', 'tif', 'tiff', 'heif', 'heic'];

    /**
     * Cached list of allowed extensions for the current request.
     * Avoids repeated expensive Imagick queryFormats() calls.
     */
    private ?array $cachedAllowedExtensions = null;

    public function __construct(
        private ViewFactory $view,
        private BlueprintAdminLibrary $blueprint,
    ) {
    }

    /**
     * Get all possible extensions (for listing existing files).
     * Returns all base and advanced formats regardless of current Imagick availability,
     * so previously-uploaded files remain visible even if Imagick is removed/disabled.
     *
     * @return array<string>
     */
    private function getAllExtensionsForListing(): array
    {
        return array_merge(self::BASE_EXTENSIONS, self::IMAGICK_ONLY_EXTENSIONS);
    }

    /**
     * Get the list of allowed extensions based on available image processing capabilities.
     * Used for upload validation and sanitization - only allows formats the server can process.
     *
     * @return array<string>
     */
    private function getAllowedExtensions(): array
    {
        // Return cached result if already computed in this request
        if ($this->cachedAllowedExtensions !== null) {
            return $this->cachedAllowedExtensions;
        }

        $allowed = self::BASE_EXTENSIONS;

        // Only allow advanced formats if Imagick is available and has the necessary codec support
        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick();
                $formats = array_map('strtolower', $im->queryFormats());
                $im->destroy();

                // Add advanced extensions if Imagick supports them
                foreach (self::IMAGICK_ONLY_EXTENSIONS as $ext) {
                    // Map file extensions to Imagick format names
                    $formatName = match($ext) {
                        'tif' => 'tiff',
                        default => $ext,
                    };
                    
                    if (in_array(strtolower($formatName), $formats, true)) {
                        $allowed[] = $ext;
                    }
                }
            } catch (\Throwable $e) {
                // If Imagick check fails, only use base extensions
            }
        }

        // Cache the result for this request
        $this->cachedAllowedExtensions = $allowed;

        return $allowed;
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
        $this->seedBundledAssets();

        $allowedExtensions = $this->getAllowedExtensions();

        $request->validate([
            'image' => [
                'required',
                'file',
                'image:allow_svg',
                'mimes:' . implode(',', $allowedExtensions),
                'max:' . self::MAX_UPLOAD_KB,
            ],
        ]);

        $file = $request->file('image');
        if (!$file) {
            return response()->json(['success' => false, 'message' => 'No file uploaded.'], 400);
        }

        $ext = strtolower($file->extension() ?: '');
        if ($ext === '' || !in_array($ext, $allowedExtensions, true)) {
            return response()->json(['success' => false, 'message' => 'File type not allowed. Your server only supports: ' . implode(', ', $allowedExtensions)], 422);
        }

        // Preserve a readable version of the original filename. Duplicate names receive
        // a numeric suffix (e.g. image.png, image-1.png) instead of a random token.
        $base = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
        $filename = $this->availableFilename($base, $ext);

        // Sanitize uploads for all supported formats before writing.
        try {
            $sanitized = $this->sanitizeUploadedFile($file, $ext);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => 'File sanitization failed.'], 422);
        }

        if (File::put($this->filesystemPath($filename), $sanitized) === false) {
            return response()->json(['success' => false, 'message' => 'Could not save the uploaded file.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully.',
            'file' => [
                'name' => $filename,
                'url' => $this->publicFileUrl($filename),
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

            $previousLibxmlUseInternalErrors = libxml_use_internal_errors(true);
            $dom = new \DOMDocument();

            try {
                // SVGs do not need DTDs; reject them to avoid entity declarations.
                if (preg_match('/<!DOCTYPE/i', $contents)) {
                    throw new \RuntimeException('SVG DTDs are not allowed.');
                }

                // Disable network access when parsing.
                if (!@$dom->loadXML($contents, LIBXML_NONET)) {
                    throw new \RuntimeException('Invalid SVG provided.');
                }

                // Remove active-content and external-reference elements entirely.
                // Compare case-insensitively because XML element names preserve input case.
                $dangerTags = [
                    'script', 'style', 'foreignobject', 'iframe', 'object', 'embed',
                    'link', 'meta', 'base',
                ];
                $all = [];
                foreach ($dom->getElementsByTagName('*') as $node) {
                    $all[] = $node;
                }

                foreach ($all as $node) {
                    if (in_array(strtolower($node->localName ?: $node->nodeName), $dangerTags, true)) {
                        if ($node->parentNode) {
                            $node->parentNode->removeChild($node);
                        }
                        continue;
                    }

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

                        if (in_array($lname, ['href', 'xlink:href', 'src'], true)) {
                            // Keep only references to content inside this SVG.
                            // External, data, and javascript URLs are not needed here.
                            if (!preg_match('/^\s*#[A-Za-z0-9_.:-]+\s*$/', $value)) {
                                $node->removeAttribute($name);
                            }
                            continue;
                        }

                        if ($lname === 'style') {
                            // Keep inline styling, but remove URL references and
                            // browser-specific executable CSS expressions.
                            $clean = preg_replace([
                                '/url\s*\(\s*[^)]+?\)/i',
                                '/@import\b[^;]*;?/i',
                                '/javascript\s*:/i',
                                '/expression\s*\(/i',
                                '/behavior\s*\(/i',
                                '/-moz-binding\s*:/i',
                            ], '', $value);

                            // CSS escapes can disguise blocked keywords, so do
                            // not retain styles that contain escape sequences.
                            if (str_contains($clean, '\\')) {
                                $clean = '';
                            } else {
                                $clean = trim($clean);
                            }
                            if ($clean === '') {
                                $node->removeAttribute($name);
                            } else {
                                $node->setAttribute($name, $clean);
                            }
                            continue;
                        }

                        // Animations are allowed for visual properties, but may
                        // not dynamically change links or other URL-bearing data.
                        if ($lname === 'attributename' && preg_match('/^(?:xlink:)?(?:href|style)$/i', trim($value))) {
                            $node->removeAttribute($name);
                            continue;
                        }

                        if (in_array($lname, ['from', 'to', 'values'], true) && preg_match('/(?:javascript\s*:|url\s*\()/i', $value)) {
                            $node->removeAttribute($name);
                            continue;
                        }
                    }
                }

                $root = $dom->documentElement;
                if ($root === null) {
                    throw new \RuntimeException('Sanitized SVG has no root element.');
                }

                return $dom->saveXML($root);
            } finally {
                libxml_clear_errors();
                libxml_use_internal_errors($previousLibxmlUseInternalErrors);
            }
        }

        // Try Imagick first for robust re-encoding and metadata stripping.
        if (class_exists(\Imagick::class)) {
            try {
                $im = new \Imagick();
                $im->readImage($file->getPathname());
                $im->stripImage();

                $formatMap = [
                    'jpg' => 'jpeg',
                    'jpeg' => 'jpeg',
                    'png' => 'png',
                    'gif' => 'gif',
                    'webp' => 'webp',
                    'bmp' => 'bmp',
                    'tif' => 'tiff',
                    'tiff' => 'tiff',
                    'avif' => 'avif',
                    'heif' => 'heif',
                    'heic' => 'heic',
                    'ico' => 'ico',
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
                    imagedestroy($img);
                    ob_end_clean();
                    throw new \RuntimeException('BMP not supported by GD on this system.');
                }
                imagebmp($img);
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
        $this->seedBundledAssets();

        // Use static allowlist for listing so previously-uploaded files remain visible
        // even if Imagick is removed/disabled after upload
        $allowedExtensions = $this->getAllExtensionsForListing();

        if (!File::isDirectory(self::FILESYSTEM_DIRECTORY)) {
            return response()->json(['success' => true, 'files' => []]);
        }

        $files = collect(File::files(self::FILESYSTEM_DIRECTORY))
            ->filter(fn(\SplFileInfo $file) => in_array(strtolower($file->getExtension()), $allowedExtensions, true))
            ->sortByDesc(fn(\SplFileInfo $file) => $file->getMTime())
            ->map(fn(\SplFileInfo $file) => [
                'name' => $file->getFilename(),
                'url' => $this->publicFileUrl($file->getFilename()),
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

        $filename = basename((string) $request->input('filename'));
        $absolutePath = $this->filesystemPath($filename);

        if ($filename === '' || !File::isFile($absolutePath)) {
            return response()->json(['success' => false, 'message' => 'File not found or invalid.'], 404);
        }

        if (!File::delete($absolutePath)) {
            return response()->json(['success' => false, 'message' => 'Could not delete the file.'], 500);
        }

        return response()->json(['success' => true, 'message' => 'Image deleted successfully.']);
    }

    public function renameImage(Request $request): JsonResponse
    {
        $this->assertRootAdmin($request);

        $request->validate([
            'filename' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $filename = basename((string) $request->input('filename'));
        $source = $this->filesystemPath($filename);
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (
            $filename === ''
            || !in_array($extension, $this->getAllExtensionsForListing(), true)
            || !File::isFile($source)
        ) {
            return response()->json(['success' => false, 'message' => 'File not found or invalid.'], 404);
        }

        $newFilename = $this->availableFilename((string) $request->input('name'), $extension, $filename);
        if ($newFilename === $filename) {
            return response()->json([
                'success' => true,
                'message' => 'Filename is unchanged.',
                'file' => ['name' => $filename, 'url' => $this->publicFileUrl($filename)],
            ]);
        }

        if (!File::move($source, $this->filesystemPath($newFilename))) {
            return response()->json(['success' => false, 'message' => 'Could not rename the file.'], 500);
        }

        return response()->json([
            'success' => true,
            'message' => 'Image renamed successfully.',
            'file' => ['name' => $newFilename, 'url' => $this->publicFileUrl($newFilename)],
        ]);
    }

    private function assertRootAdmin(Request $request): void
    {
        if (!$request->user() || !$request->user()->root_admin) {
            throw new AccessDeniedHttpException();
        }
    }

    private function publicFileUrl(string $path): string
    {
        return url('/fs/{identifier}/' . ltrim($path, '/'));
    }

    /**
     * Seed the writable extension filesystem after Blueprint has completed
     * installation and assigned ownership to the webserver user. This avoids
     * a custom install script, which runs before that ownership change.
     */
    private function seedBundledAssets(): void
    {
        if (!File::isDirectory(self::BUNDLED_ASSETS_DIRECTORY) || !File::isDirectory(self::FILESYSTEM_DIRECTORY)) {
            return;
        }

        foreach (File::files(self::BUNDLED_ASSETS_DIRECTORY) as $asset) {
            $destination = $this->filesystemPath($asset->getFilename());
            if (!File::exists($destination)) {
                File::copy($asset->getPathname(), $destination);
            }
        }
    }

    private function filesystemPath(string $path): string
    {
        return rtrim(self::FILESYSTEM_DIRECTORY, '/\\') . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }

    private function availableFilename(string $requestedName, string $extension, ?string $currentFilename = null): string
    {
        // Ignore a supplied extension because the existing/uploaded file type must stay unchanged.
        $base = Str::slug(pathinfo($requestedName, PATHINFO_FILENAME));
        if ($base === '') {
            $base = 'image';
        }

        $counter = 0;
        do {
            $suffix = $counter === 0 ? '' : '-' . $counter;
            $maxBaseLength = 255 - strlen($extension) - 1 - strlen($suffix);
            $filename = substr($base, 0, max(1, $maxBaseLength)) . $suffix . '.' . $extension;
            $counter++;
        } while ($filename !== $currentFilename && File::exists($this->filesystemPath($filename)));

        return $filename;
    }
}
