<?php
/**
 * Weeklyst — csrf.php
 * Genereert en valideert honeypot tokens.
 * Beschermt formulieren tegen bots zonder externe diensten.
 */

require_once __DIR__ . '/config.php';

/**
 * Genereer een formulier token met tijdstempel
 * Token = HMAC van (timestamp + secret)
 */
function generateFormToken(): array {
    $ts    = time();
    $token = hash_hmac('sha256', $ts, COOKIE_SECRET);
    return ['token' => $token, 'ts' => $ts];
}

/**
 * Valideer een formulier token
 * @param string $token    Het token uit het formulier
 * @param int    $ts       Het tijdstempel uit het formulier
 * @param int    $minSecs  Minimale tijd in seconden (bot check)
 * @param int    $maxSecs  Maximale geldigheidsduur
 */
function validateFormToken(string $token, int $ts, int $minSecs = 2, int $maxSecs = 3600): bool {
    // Tijdcheck: te snel = bot
    $elapsed = time() - $ts;
    if ($elapsed < $minSecs) return false;

    // Verlopen token
    if ($elapsed > $maxSecs) return false;

    // Verify HMAC
    $expected = hash_hmac('sha256', $ts, COOKIE_SECRET);
    return hash_equals($expected, $token);
}

// Als direct aangeroepen via GET: geef token terug als JSON
if (isset($_GET['get_token'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(generateFormToken());
    exit;
}
