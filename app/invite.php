<?php
/**
 * Weeklyst — invite.php
 * Verstuurt een uitnodigingsmail via SMTP.
 * Beveiligd met een apart admin wachtwoord.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mailer.php';

header('Content-Type: application/json; charset=utf-8');

// Alleen POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];

// Controleer admin wachtwoord
$adminPass = $payload['admin_password'] ?? '';
if (!hash_equals(INVITE_PASSWORD, $adminPass)) {
    usleep(500000);
    http_response_code(401);
    echo json_encode(['error' => 'Ongeldig wachtwoord.']);
    exit;
}

// Valideer velden
$type      = ($payload['type'] ?? 'family') === 'test' ? 'test' : 'family';
$toName    = trim($payload['name']    ?? '');
$toEmail   = trim($payload['email']   ?? '');
$invCode   = strtoupper(trim($payload['invite_code'] ?? ''));
$fromName  = trim($payload['from_name'] ?? 'Weeklyst');
$personal  = trim($payload['personal_msg'] ?? '');

if (!$toName)  { http_response_code(400); echo json_encode(['error' => 'Naam is verplicht.']); exit; }
if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) { http_response_code(400); echo json_encode(['error' => 'Ongeldig e-mailadres.']); exit; }
if ($type === 'family' && strlen($invCode) !== 6) { http_response_code(400); echo json_encode(['error' => 'Uitnodigingscode moet 6 tekens zijn.']); exit; }

// Verstuur de mail
$ok = $type === 'family'
    ? mailInvite($toEmail, $toName, $fromName, $invCode, $personal)
    : mailInviteTest($toEmail, $toName, $fromName, $personal);

if ($ok) {
    echo json_encode(['ok' => true, 'message' => "Uitnodiging verstuurd naar $toEmail"]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Versturen mislukt. Controleer SMTP instellingen.']);
}
