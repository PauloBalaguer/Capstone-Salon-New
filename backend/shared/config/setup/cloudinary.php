<?php

/**
 * Cloudinary storage for uploaded photos (optional).
 *
 * Turned on by three settings, copied from the Cloudinary dashboard into
 * Vercel's environment variables (or the private .env on XAMPP):
 *   CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY, CLOUDINARY_API_SECRET
 *
 * When they are set, a profile photo is uploaded to Cloudinary and only its
 * https URL is saved in the database. When they are missing, or Cloudinary
 * can't be reached, the photo is saved as before (a data URI in the database),
 * so saving a photo never fails because of Cloudinary.
 *
 * The site's own images and videos are sent to Cloudinary by
 * database/scripts/cloudinary_upload_media.php and redirected there by
 * docker/apache-salon.conf.
 */

require_once __DIR__ . '/env.php';

final class Cloudinary
{
    /** @return array{cloud: string, key: string, secret: string}|null */
    public static function credentials(): ?array
    {
        $cloud = env('CLOUDINARY_CLOUD_NAME');
        $key = env('CLOUDINARY_API_KEY');
        $secret = env('CLOUDINARY_API_SECRET');
        if ($cloud === '' || $key === '' || $secret === '') return null;
        return ['cloud' => $cloud, 'key' => $key, 'secret' => $secret];
    }

    /**
     * Uploads $file (a local path, or a data URI) as $publicId, replacing any
     * earlier upload with the same id. Returns Cloudinary's reply.
     *
     * @param 'image'|'video' $type
     * @throws RuntimeException when not configured or the upload fails
     */
    public static function upload(string $file, string $publicId, string $type = 'image', int $timeout = 20): array
    {
        $c = self::credentials();
        if ($c === null) throw new RuntimeException('Cloudinary is not configured.');

        $params = [
            'invalidate' => 'true',
            'overwrite' => 'true',
            'public_id' => $publicId,
            'timestamp' => (string) time(),
        ];
        ksort($params);
        $toSign = implode('&', array_map(fn($k, $v) => "$k=$v", array_keys($params), $params));
        $params['signature'] = sha1($toSign . $c['secret']);
        $params['api_key'] = $c['key'];
        $params['file'] = str_starts_with($file, 'data:') ? $file : new CURLFile($file);

        $ch = curl_init('https://api.cloudinary.com/v1_1/' . rawurlencode($c['cloud']) . '/' . $type . '/upload');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $params,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $reply = is_string($body) ? json_decode($body, true) : null;
        if ($status !== 200 || !is_array($reply) || empty($reply['secure_url'])) {
            $why = $reply['error']['message'] ?? ($error !== '' ? $error : "HTTP $status");
            throw new RuntimeException('Cloudinary upload failed: ' . $why);
        }
        return $reply;
    }

    /**
     * For a profile photo already checked by the caller: returns its Cloudinary
     * URL, or the data URI itself when Cloudinary is off or the upload fails.
     */
    public static function storePhoto(string $dataUri, string $publicId): string
    {
        if (self::credentials() === null) return $dataUri;
        try {
            return (string) self::upload($dataUri, 'salon/profiles/' . $publicId, 'image', 10)['secure_url'];
        } catch (Throwable $e) {
            error_log($e->getMessage());
            return $dataUri;
        }
    }
}
