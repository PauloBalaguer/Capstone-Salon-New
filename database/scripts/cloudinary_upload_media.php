<?php

/**
 * Uploads the site's images, icons and videos (frontend/shared/image, icon and
 * video) to Cloudinary. Run it once after creating the Cloudinary account, and
 * again whenever a file in those folders is added or replaced.
 *
 * Needs CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET in
 * the private .env (or as environment variables). Then, from the project folder:
 *   C:\xampp\php\php.exe database\scripts\cloudinary_upload_media.php
 *
 * Each file keeps its path, without the extension, under "salon/":
 *   frontend/shared/image/Daraga.webp  ->  salon/image/Daraga
 * which is where docker/apache-salon.conf sends the browser once
 * CLOUDINARY_CLOUD_NAME is set on the server. Running it again replaces the
 * files, so it is safe to repeat.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

require_once __DIR__ . '/../../backend/shared/config/setup/cloudinary.php';

if (!function_exists('curl_init')) {
    fwrite(STDERR, "PHP's curl extension is off. In C:\\xampp\\php\\php.ini, remove the ; before extension=curl and run this again.\n");
    exit(1);
}
$creds = Cloudinary::credentials();
if ($creds === null) {
    fwrite(STDERR, "Set CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY and CLOUDINARY_API_SECRET first (private .env or environment).\n");
    exit(1);
}

$root = realpath(__DIR__ . '/../../frontend/shared');
$types = [
    'image' => ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'],
    'video' => ['mp4', 'webm', 'mov'],
];

$files = [];
foreach (['image', 'icon', 'video'] as $folder) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/$folder", FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) $files[] = $file->getPathname();
}
sort($files);

echo "Uploading to Cloudinary account \"{$creds['cloud']}\"...\n";
$failed = 0;
foreach ($files as $path) {
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $ext = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    $type = in_array($ext, $types['video'], true) ? 'video' : (in_array($ext, $types['image'], true) ? 'image' : null);
    if ($type === null) continue;
    if (str_contains($relative, ' ')) {
        echo "  SKIPPED  $relative (rename it without spaces first)\n";
        $failed++;
        continue;
    }
    $publicId = 'salon/' . substr($relative, 0, -strlen($ext) - 1);
    try {
        Cloudinary::upload($path, $publicId, $type, 300);
        echo "  ok       $relative\n";
    } catch (Throwable $e) {
        echo "  FAILED   $relative: {$e->getMessage()}\n";
        $failed++;
    }
}

echo $failed ? "\n$failed file(s) not uploaded. Fix the problem above and run this again.\n" : "\nAll files uploaded.\n";
exit($failed ? 1 : 0);
