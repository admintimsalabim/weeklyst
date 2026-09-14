<?php
/**
 * Weeklyst — push.php
 * Verstuurt Web Push notificaties via VAPID.
 */

define('VAPID_PUBLIC_KEY',  'BMSh2JZvnT1hPCukxTimsnUkqZSNPcs694Jc8Rmk1qX-VRqab95JOhPT9hdye1Bhl8lGntzqsIBgnH8azf5-xP4');
define('VAPID_PRIVATE_KEY', "-----BEGIN PRIVATE KEY-----\nMIGHAgEAMBMGByqGSM49AgEGCCqGSM49AwEHBG0wawIBAQQgye9hT6l1nfqNngdd\nC3/cZTOeXEfumPWz8fkGM0MrISGhRANCAATEodiWb509YTwrpMU4prJ1JKmUjT3L\nOveCXPEZpNal/lUamm/eSToT0/YXcntQYZfJRp7c6rCAYJx/Gs3+fsT+\n-----END PRIVATE KEY-----");
define('VAPID_SUBJECT',      'mailto:info@weeklyst.nl');
define('PUSH_DELAY_SECONDS',  120); // 2 minuten debounce

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));
}
function derToRaw(string $der): string {
    $offset = 0;
    if (ord($der[$offset++]) !== 0x30) return $der;
    $offset++;
    if (ord($der[$offset++]) !== 0x02) return $der;
    $rLen = ord($der[$offset++]);
    $r = ltrim(substr($der, $offset, $rLen), "\x00");
    $offset += $rLen;
    if (ord($der[$offset++]) !== 0x02) return $der;
    $sLen = ord($der[$offset++]);
    $s = ltrim(substr($der, $offset, $sLen), "\x00");
    return str_pad($r, 32, "\x00", STR_PAD_LEFT) . str_pad($s, 32, "\x00", STR_PAD_LEFT);
}

function generateVapidHeaders(string $endpoint): ?array {
    $parsed   = parse_url($endpoint);
    $audience = $parsed['scheme'] . '://' . $parsed['host'];
    $header   = base64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
    $payload  = base64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 43200,
        'sub' => VAPID_SUBJECT,
    ]));
    $data       = $header . '.' . $payload;
    $privateKey = openssl_pkey_get_private(VAPID_PRIVATE_KEY);
    if (!$privateKey) return null;
    $signature = '';
    openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    $signature = derToRaw($signature);
    $jwt = $data . '.' . base64url_encode($signature);
    return ['Authorization: vapid t=' . $jwt . ', k=' . VAPID_PUBLIC_KEY];
}

function sendPushNotification(array $subscription, string $title, string $body): bool {
    $endpoint = $subscription['endpoint'];
    $p256dh   = $subscription['p256dh'];
    $auth     = $subscription['auth'];

    $payload = json_encode([
        'title' => $title,
        'body'  => $body,
        'icon'  => '/icon-192.png',
        'badge' => '/icon-192.png',
        'tag'   => 'weeklyst-update',
        'data'  => ['url' => '/app.html'],
    ]);

    $vapidHeaders = generateVapidHeaders($endpoint);
    if (!$vapidHeaders) return false;

    // Stuur payload als plaintext via urgency header
    // Voor productie: gebruik web-push-php library voor volledige encryptie
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => array_merge($vapidHeaders, [
            'Content-Type: application/json',
            'TTL: 86400',
            'Urgency: normal',
        ]),
    ]);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return in_array($httpCode, [200, 201, 202]);
}

function sendFamilyPush(PDO $db, int $familyId, int $senderUserId, string $senderName, string $familyName): void {
    $stmt = $db->prepare('SELECT * FROM push_subscriptions WHERE family_id = ? AND user_id != ?');
    $stmt->execute([$familyId, $senderUserId]);
    $subscriptions = $stmt->fetchAll();

    $title = 'Weeklyst';
    $body  = $senderName . ' heeft de lijst bijgewerkt — ' . $familyName;

    $expired = [];
    foreach ($subscriptions as $sub) {
        $ok = sendPushNotification($sub, $title, $body);
        if (!$ok) $expired[] = $sub['id'];
    }
    if (!empty($expired)) {
        $placeholders = implode(',', array_fill(0, count($expired), '?'));
        $db->prepare("DELETE FROM push_subscriptions WHERE id IN ($placeholders)")->execute($expired);
    }
    $db->prepare('UPDATE families SET push_pending=0, push_pending_at=NULL, push_sender_id=NULL WHERE id=?')
       ->execute([$familyId]);
}
