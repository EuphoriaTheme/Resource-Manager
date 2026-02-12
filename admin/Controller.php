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
        $file->move($uploadsDir, $filename);

        return response()->json([
            'success' => true,
            'message' => 'Image uploaded successfully.',
            'file' => [
                'name' => $filename,
                'url' => asset(self::UPLOADS_RELATIVE_PATH . '/' . $filename),
            ],
        ]);
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
