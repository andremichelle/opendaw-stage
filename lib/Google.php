<?php
declare(strict_types=1);

final class Google
{
    public static function isEnabled(array $config): bool
    {
        return ($config['googleClientId'] ?? '') !== '' && ($config['googleClientSecret'] ?? '') !== '';
    }

    public static function redirectUrl(): string
    {
        return Stage::baseUrl() . 'api.php?action=oauth';
    }

    public static function authUrl(array $config, string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                'client_id' => $config['googleClientId'], 'redirect_uri' => self::redirectUrl(),
                'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'prompt' => 'select_account'
            ]);
    }

    public static function exchange(array $config, string $code): ?array
    {
        $response = self::post('https://oauth2.googleapis.com/token', [
            'code' => $code, 'client_id' => $config['googleClientId'], 'client_secret' => $config['googleClientSecret'],
            'redirect_uri' => self::redirectUrl(), 'grant_type' => 'authorization_code'
        ]);
        $token = is_null($response) ? null : json_decode($response, true);
        $parts = is_array($token) && is_string($token['id_token'] ?? null) ? explode('.', $token['id_token']) : [];
        if (count($parts) !== 3) {return null;}
        $claims = json_decode((string)base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!is_array($claims)) {return null;}
        $issuer = (string)($claims['iss'] ?? '');
        if (($claims['aud'] ?? '') !== $config['googleClientId']
            || ($issuer !== 'https://accounts.google.com' && $issuer !== 'accounts.google.com')
            || (int)($claims['exp'] ?? 0) < time() || !is_string($claims['sub'] ?? null)) {
            return null;
        }
        $verified = ($claims['email_verified'] ?? false) === true || ($claims['email_verified'] ?? '') === 'true';
        return [
            'id' => 'google:' . $claims['sub'],
            'name' => substr(trim((string)($claims['name'] ?? 'Listener')), 0, 100),
            'picture' => str_starts_with((string)($claims['picture'] ?? ''), 'https://') ? (string)$claims['picture'] : '',
            'email' => $verified ? (string)($claims['email'] ?? '') : ''
        ];
    }

    private static function post(string $url, array $fields): ?string
    {
        $body = http_build_query($fields);
        if (function_exists('curl_init')) {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
            $response = curl_exec($curl);
            $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            return is_string($response) && $status === 200 ? $response : null;
        }
        $context = stream_context_create(['http' => ['method' => 'POST', 'content' => $body, 'timeout' => 15,
            'header' => 'Content-Type: application/x-www-form-urlencoded', 'ignore_errors' => false]]);
        $response = @file_get_contents($url, false, $context);
        return is_string($response) ? $response : null;
    }
}
