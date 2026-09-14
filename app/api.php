<?php
/**
 * Weeklyst – api.php
 * Centrale API voor alle app-functionaliteit.
 * Alle responses zijn JSON.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';
if (file_exists(__DIR__ . '/csrf.php')) {
    require_once __DIR__ . '/csrf.php';
if (file_exists(__DIR__ . '/push.php')) require_once __DIR__ . '/push.php';
} else {
    // Fallback als csrf.php ontbreekt
    function validateFormToken(string $token, int $ts, int $minSecs = 2, int $maxSecs = 3600): bool { return true; }
    function generateFormToken(): array { return ['token' => '', 'ts' => time()]; }
}

header('Content-Type: application/json; charset=utf-8');

// ── CORS ──────────────────────────────────────────────────
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Origin: ' . (APP_URL ? parse_url(APP_URL, PHP_URL_SCHEME) . '://' . parse_url(APP_URL, PHP_URL_HOST) : '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ── Helpers ───────────────────────────────────────────────

function out(int $code, array $data): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function rateLimit(): void {
    $ip   = md5($_SERVER['REMOTE_ADDR'] ?? '');
    $file = sys_get_temp_dir() . '/wl_rate_' . $ip;
    $now  = time();
    $d    = file_exists($file) ? (json_decode(file_get_contents($file), true) ?? []) : [];
    if (($d['t'] ?? 0) < $now - RATE_WINDOW) $d = ['t' => $now, 'n' => 0];
    $d['n']++;
    file_put_contents($file, json_encode($d), LOCK_EX);
    if ($d['n'] > RATE_LIMIT) out(429, ['error' => 'Te veel verzoeken, wacht even.']);
}

function generateCode(int $length = 6): string {
    return str_pad((string)random_int(0, (int)str_repeat('9', $length)), $length, '0', STR_PAD_LEFT);
}

function generateInviteCode(): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    for ($i = 0; $i < 6; $i++) $code .= $chars[random_int(0, strlen($chars) - 1)];
    return $code;
}

// ── Sessiebeheer ──────────────────────────────────────────

function ensureSessDir(): string {
    $dir = SESSION_DIR;
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
        file_put_contents($dir . '/.htaccess', "Deny from all\n");
    }
    return $dir;
}

function sessPath(string $token): string {
    return ensureSessDir() . '/s_' . hash('sha256', $token) . '.json';
}

function createSession(int $userId): string {
    $token = bin2hex(random_bytes(32));
    $sig   = hash_hmac('sha256', $token, COOKIE_SECRET);
    file_put_contents(sessPath($token), json_encode([
        'user_id' => $userId,
        'sig'     => $sig,
        'expires' => time() + COOKIE_DAYS * 86400,
    ]), LOCK_EX);
    return $token;
}

function sendSessionCookie(string $token): void {
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $secure = $https ? '; Secure' : '';
    header(
        'Set-Cookie: ' . COOKIE_NAME . '=' . rawurlencode($token)
        . '; Max-Age=' . (COOKIE_DAYS * 86400)
        . '; Path=/'
        . '; HttpOnly'
        . '; SameSite=Lax'
        . $secure,
        false
    );
}

function getSession(): ?array {
    $raw = rawurldecode($_COOKIE[COOKIE_NAME] ?? '');
    if ($raw === '') return null;
    $path = sessPath($raw);
    if (!file_exists($path)) return null;
    $d = json_decode(file_get_contents($path), true);
    if (!is_array($d) || ($d['expires'] ?? 0) < time()) { @unlink($path); return null; }
    if (!hash_equals(hash_hmac('sha256', $raw, COOKIE_SECRET), $d['sig'] ?? '')) return null;
    return $d;
}

function requireAuth(): array {
    $sess = getSession();
    if (!$sess) out(401, ['error' => 'auth_required']);
    $db   = getDB();
    $stmt = $db->prepare('SELECT u.*, f.name AS family_name, f.invite_code FROM users u LEFT JOIN families f ON u.family_id = f.id WHERE u.id = ?');
    $stmt->execute([$sess['user_id']]);
    $user = $stmt->fetch();
    if (!$user) out(401, ['error' => 'auth_required']);
    return $user;
}

// ── Routing ───────────────────────────────────────────────

rateLimit();

$method  = $_SERVER['REQUEST_METHOD'];
$payload = $method === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?? []) : [];
$action  = $payload['action'] ?? ($_GET['action'] ?? '');


// ── Bot bescherming (alleen honeypot) ────────────────────
$botProtected = ['register', 'register_member', 'login', 'forgot_password', 'magic_send'];
if (in_array($action, $botProtected)) {
    $honeypot = $payload['_hp'] ?? null;

    // Honeypot: als het veld is ingevuld is het een bot
    if ($honeypot !== null && $honeypot !== '') {
        usleep(500000);
        out(400, ['error' => 'Validatiefout.']);
    }
    // Tijdcheck tijdelijk uitgeschakeld — token flow wordt herzien
}

// ════════════════════════════════════════════════════════
//  REGISTRATIE
// ════════════════════════════════════════════════════════
if ($action === 'register') {
    $email = trim(strtolower($payload['email'] ?? ''));
    $name  = trim($payload['name'] ?? '');
    $pass  = $payload['password'] ?? '';

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(400, ['error' => 'Ongeldig e-mailadres.']);
    if (strlen($name) < 2)  out(400, ['error' => 'Naam is te kort.']);
    if (strlen($pass) < 8)  out(400, ['error' => 'Wachtwoord moet minimaal 8 tekens zijn.']);

    $db   = getDB();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) out(409, ['error' => 'Dit e-mailadres is al in gebruik.']);

    $code    = generateCode(6);
    $expires = date('Y-m-d H:i:s', time() + 900); // 15 min
    $hash    = password_hash($pass, PASSWORD_DEFAULT);

    $authMethod = ($payload['auth_method'] ?? 'password') === 'magic' ? 'magic' : 'password';
    // Bij magic login geen wachtwoord opslaan
    if ($authMethod === 'magic') $hash = '';

    $stmt = $db->prepare('INSERT INTO users (email, name, password_hash, auth_method, verify_code, verify_expires) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([$email, $name, $hash, $authMethod, $code, $expires]);

    if (!mailVerifyCode($email, $name, $code)) {
        out(500, ['error' => 'E-mail kon niet worden verstuurd. Probeer later opnieuw.']);
    }

    out(200, ['ok' => true, 'step' => 'verify_email']);
}

// ════════════════════════════════════════════════════════
//  E-MAIL VERIFICATIE
// ════════════════════════════════════════════════════════
if ($action === 'verify_email') {
    $email = trim(strtolower($payload['email'] ?? ''));
    $code  = trim($payload['code'] ?? '');

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) out(404, ['error' => 'Gebruiker niet gevonden.']);
    if ($user['email_verified']) out(400, ['error' => 'E-mail is al geverifieerd.']);
    if ($user['verify_code'] !== $code) { usleep(300000); out(400, ['error' => 'Onjuiste code.']); }
    if (strtotime($user['verify_expires']) < time()) out(400, ['error' => 'Code is verlopen. Vraag een nieuwe aan.']);

    $db->prepare('UPDATE users SET email_verified=1, verify_code=NULL, verify_expires=NULL WHERE id=?')
       ->execute([$user['id']]);

    // Direct inloggen na verificatie
    $token = createSession((int)$user['id']);
    sendSessionCookie($token);

    out(200, ['ok' => true, 'step' => 'setup_family', 'user' => ['name' => $user['name'], 'email' => $user['email']]]);
}

// ════════════════════════════════════════════════════════
//  VERIFICATIECODE OPNIEUW STUREN
// ════════════════════════════════════════════════════════
if ($action === 'resend_verify') {
    $email = trim(strtolower($payload['email'] ?? ''));
    $db    = getDB();
    $stmt  = $db->prepare('SELECT * FROM users WHERE email = ? AND email_verified = 0');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) out(404, ['error' => 'Gebruiker niet gevonden of al geverifieerd.']);

    $code    = generateCode(6);
    $expires = date('Y-m-d H:i:s', time() + 900);
    $db->prepare('UPDATE users SET verify_code=?, verify_expires=? WHERE id=?')
       ->execute([$code, $expires, $user['id']]);

    mailVerifyCode($email, $user['name'], $code);
    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════════════
//  CHECK AUTH METHOD (voor login flow)
// ════════════════════════════════════════════════════════
if ($action === 'check_auth_method') {
    $email = trim(strtolower($payload['email'] ?? ''));
    $db    = getDB();
    $stmt  = $db->prepare('SELECT auth_method FROM users WHERE email = ? AND email_verified = 1');
    $stmt->execute([$email]);
    $user  = $stmt->fetch();
    // Geef altijd 'password' terug als gebruiker niet gevonden (voorkomt email enumeration)
    out(200, ['auth_method' => $user ? $user['auth_method'] : 'password']);
}

// ════════════════════════════════════════════════════════
//  MAGIC LOGIN — stuur code
// ════════════════════════════════════════════════════════
if ($action === 'magic_send') {
    $email = trim(strtolower($payload['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) out(400, ['error' => 'Ongeldig e-mailadres.']);

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ? AND email_verified = 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Altijd OK teruggeven — voorkomt e-mail enumeration
    if ($user) {
        $code    = generateCode(6);
        $expires = date('Y-m-d H:i:s', time() + 900);
        $db->prepare('UPDATE users SET magic_code=?, magic_expires=? WHERE id=?')
           ->execute([$code, $expires, $user['id']]);
        mailMagicCode($email, $user['name'], $code);
    }
    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════════════
//  MAGIC LOGIN — verifieer code
// ════════════════════════════════════════════════════════
if ($action === 'magic_verify') {
    $email = trim(strtolower($payload['email'] ?? ''));
    $code  = trim($payload['code'] ?? '');

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $user['magic_code'] !== $code) {
        usleep(300000);
        out(400, ['error' => 'Onjuiste of verlopen code.']);
    }
    if (strtotime($user['magic_expires']) < time()) {
        out(400, ['error' => 'Code is verlopen. Vraag een nieuwe aan.']);
    }

    // Code wissen zodat hij maar één keer werkt
    $db->prepare('UPDATE users SET magic_code=NULL, magic_expires=NULL WHERE id=?')
       ->execute([$user['id']]);

    $token = createSession((int)$user['id']);
    sendSessionCookie($token);

    out(200, ['ok' => true, 'user' => [
        'name'       => $user['name'],
        'email'      => $user['email'],
        'has_family' => !is_null($user['family_id']),
    ]]);
}

// ════════════════════════════════════════════════════════
//  INLOGGEN (email+wachtwoord OF naam+gezinscode+wachtwoord)
// ════════════════════════════════════════════════════════
if ($action === 'login') {
    $db   = getDB();
    $pass = $payload['password'] ?? '';

    // Methode 1: naam + gezinscode (voor gezinsleden)
    if (!empty($payload['name']) && !empty($payload['invite_code'])) {
        $name = trim($payload['name'] ?? '');
        $code = strtoupper(trim($payload['invite_code'] ?? ''));

        $stmt = $db->prepare('SELECT u.* FROM users u JOIN families f ON u.family_id = f.id WHERE u.name = ? AND f.invite_code = ?');
        $stmt->execute([$name, $code]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($pass, $user['password_hash'])) {
            usleep(400000);
            out(401, ['error' => 'Onjuiste naam, gezinscode of wachtwoord.']);
        }

        $token = createSession((int)$user['id']);
        sendSessionCookie($token);

        // Check of beheerder
        $fStmt = $db->prepare('SELECT owner_id, name, invite_code FROM families WHERE id = ?');
        $fStmt->execute([$user['family_id']]);
        $family = $fStmt->fetch();

        out(200, ['ok' => true, 'user' => [
            'name'        => $user['name'],
            'email'       => $user['email'],
            'has_family'  => true,
            'family_name' => $family['name'] ?? '',
            'invite_code' => $family['invite_code'] ?? '',
            'is_owner'    => ($user['role'] ?? 'member') === 'owner',
            'auth_method' => $user['auth_method'],
        ]]);
    }

    // Methode 2: email + wachtwoord (voor beheerder)
    $email = trim(strtolower($payload['email'] ?? ''));
    if (!$email) out(400, ['error' => 'Vul je e-mailadres of naam in.']);

    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($pass, $user['password_hash'])) {
        usleep(400000);
        out(401, ['error' => 'Onjuist e-mailadres of wachtwoord.']);
    }
    if (!$user['email_verified']) {
        out(403, ['error' => 'Bevestig eerst je e-mailadres.', 'step' => 'verify_email']);
    }

    $token = createSession((int)$user['id']);
    sendSessionCookie($token);

    // Check of beheerder
    $isOwner = false;
    if ($user['family_id']) {
        $fStmt = $db->prepare('SELECT owner_id FROM families WHERE id = ?');
        $fStmt->execute([$user['family_id']]);
        $fRow = $fStmt->fetch();
        $isOwner = ($fRow && $fRow['owner_id'] == $user['id']);
    }

    out(200, ['ok' => true, 'user' => [
        'name'        => $user['name'],
        'email'       => $user['email'],
        'has_family'  => !is_null($user['family_id']),
        'is_owner'    => $isOwner,
        'auth_method' => $user['auth_method'],
    ]]);
}

// ════════════════════════════════════════════════════════
//  UITLOGGEN
// ════════════════════════════════════════════════════════
if ($action === 'logout') {
    $raw = rawurldecode($_COOKIE[COOKIE_NAME] ?? '');
    if ($raw !== '') @unlink(sessPath($raw));
    $https  = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $secure = $https ? '; Secure' : '';
    header('Set-Cookie: ' . COOKIE_NAME . '=; Max-Age=0; Path=/; HttpOnly; SameSite=Lax' . $secure, false);
    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════════════
//  WACHTWOORD VERGETEN
//  - Beheerder: stuurt code naar eigen email
//  - Gezinslid: stuurt reset link naar gezinsbeheerder
// ════════════════════════════════════════════════════════
if ($action === 'forgot_password') {
    $db = getDB();

    // Gezinslid: naam + gezinscode
    if (!empty($payload['name']) && !empty($payload['invite_code'])) {
        $name = trim($payload['name'] ?? '');
        $code = strtoupper(trim($payload['invite_code'] ?? ''));

        // Zoek gebruiker
        $stmt = $db->prepare('SELECT u.*, f.name as family_name, f.owner_id, f.invite_code as fcode FROM users u JOIN families f ON u.family_id = f.id WHERE u.name = ? AND f.invite_code = ?');
        $stmt->execute([$name, $code]);
        $user = $stmt->fetch();

        if ($user) {
            // Genereer reset token
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600); // 1 uur
            $db->prepare('UPDATE users SET reset_code=?, reset_expires=? WHERE id=?')
               ->execute([$token, $expires, $user['id']]);

            // Zoek gezinsbeheerder email
            $ownerStmt = $db->prepare('SELECT email, name FROM users WHERE id = ?');
            $ownerStmt->execute([$user['owner_id']]);
            $owner = $ownerStmt->fetch();

            if ($owner && $owner['email']) {
                // Reset link voor het lid
                $resetLink = APP_URL . '/index.html?reset_token=' . $token . '&member_id=' . $user['id'];
                mailMemberResetRequest($owner['email'], $owner['name'], $user['name'], $resetLink);
            }
        }
        out(200, ['ok' => true, 'method' => 'member']);
    }

    // Beheerder: email
    $email = trim(strtolower($payload['email'] ?? ''));
    $stmt  = $db->prepare('SELECT * FROM users WHERE email = ? AND email_verified = 1');
    $stmt->execute([$email]);
    $user  = $stmt->fetch();

    if ($user) {
        $resetCode = generateCode(6);
        $expires   = date('Y-m-d H:i:s', time() + 900);
        $db->prepare('UPDATE users SET reset_code=?, reset_expires=? WHERE id=?')
           ->execute([$resetCode, $expires, $user['id']]);
        mailResetCode($email, $user['name'], $resetCode);
    }
    out(200, ['ok' => true, 'method' => 'owner']);
}

// ════════════════════════════════════════════════════════
//  WACHTWOORD OPNIEUW INSTELLEN
//  - Via token (gezinslid via link van beheerder)
//  - Via code (beheerder via email)
// ════════════════════════════════════════════════════════
if ($action === 'reset_password') {
    $newPass = $payload['password'] ?? '';
    if (strlen($newPass) < 6) out(400, ['error' => 'Wachtwoord moet minimaal 6 tekens zijn.']);

    $db = getDB();

    // Token reset (gezinslid)
    if (!empty($payload['token']) && !empty($payload['member_id'])) {
        $token    = $payload['token'];
        $memberId = (int)$payload['member_id'];

        $stmt = $db->prepare('SELECT * FROM users WHERE id = ? AND reset_code = ?');
        $stmt->execute([$memberId, $token]);
        $user = $stmt->fetch();

        if (!$user) { usleep(300000); out(400, ['error' => 'Ongeldige of verlopen reset link.']); }
        if (strtotime($user['reset_expires']) < time()) out(400, ['error' => 'Reset link is verlopen.']);

        $hash = password_hash($newPass, PASSWORD_DEFAULT);
        $db->prepare('UPDATE users SET password_hash=?, reset_code=NULL, reset_expires=NULL WHERE id=?')
           ->execute([$hash, $memberId]);

        out(200, ['ok' => true]);
    }

    // Code reset (beheerder via email)
    $email = trim(strtolower($payload['email'] ?? ''));
    $code  = trim($payload['code'] ?? '');

    $stmt = $db->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || $user['reset_code'] !== $code) { usleep(300000); out(400, ['error' => 'Onjuiste code.']); }
    if (strtotime($user['reset_expires']) < time()) out(400, ['error' => 'Code is verlopen.']);

    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash=?, reset_code=NULL, reset_expires=NULL WHERE id=?')
       ->execute([$hash, $user['id']]);

    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════════════
//  SESSIE CHECK (voor auto-login)
// ════════════════════════════════════════════════════════
if ($action === 'me' || $method === 'GET') {
    $sess = getSession();
    if (!$sess) out(401, ['error' => 'auth_required']);

    $db   = getDB();
    $stmt = $db->prepare('SELECT u.*, f.name AS family_name, f.invite_code FROM users u LEFT JOIN families f ON u.family_id = f.id WHERE u.id = ?');
    $stmt->execute([$sess['user_id']]);
    $user = $stmt->fetch();
    if (!$user) out(401, ['error' => 'auth_required']);

    $isOwner = ($user['role'] ?? 'member') === 'owner';

    out(200, ['ok' => true, 'user' => [
        'name'        => $user['name'],
        'email'       => $user['email'],
        'has_family'  => !is_null($user['family_id']),
        'family_name' => $user['family_name'],
        'invite_code' => $user['invite_code'],
        'auth_method' => $user['auth_method'],
        'is_owner'    => $isOwner,
    ]]);
}

// ════════════════════════════════════════════════════════
//  GEZIN AANMAKEN
// ════════════════════════════════════════════════════════
if ($action === 'create_family') {
    $user = requireAuth();
    if ($user['family_id']) out(400, ['error' => 'Je zit al in een gezin.']);

    $name = trim($payload['name'] ?? '');
    if (strlen($name) < 2) out(400, ['error' => 'Gezinsnaam is te kort.']);

    $db   = getDB();
    // Genereer unieke invite code
    do {
        $code = generateInviteCode();
        $stmt = $db->prepare('SELECT id FROM families WHERE invite_code = ?');
        $stmt->execute([$code]);
    } while ($stmt->fetch());

    $db->beginTransaction();
    try {
        $db->prepare('INSERT INTO families (name, invite_code) VALUES (?, ?)')->execute([$name, $code]);
        $familyId = (int)$db->lastInsertId();
        $db->prepare('UPDATE users SET family_id = ? WHERE id = ?')->execute([$familyId, $user['id']]);

        // Maak een lege lijst aan voor dit gezin
        $defaultList = json_encode([
            'title' => 'Wat eten we? En boodschappen planner',
            'who'   => '',
            'meals' => array_fill(0, 7, ['day' => '', 'text' => '']),
            'shops' => [],
            'ideas' => [],
        ]);
        $db->prepare('INSERT INTO lists (family_id, data, updated_by_name) VALUES (?, ?, ?)')->execute([$familyId, $defaultList, $user['name']]);

        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        out(500, ['error' => 'Aanmaken mislukt.']);
    }

    mailWelcome($user['email'], $user['name'], $name, $code);
    out(200, ['ok' => true, 'family_name' => $name, 'invite_code' => $code]);
}

// ════════════════════════════════════════════════════════
//  GEZIN JOINEN VIA INVITE CODE (bestaande gebruiker)
// ════════════════════════════════════════════════════════
if ($action === 'join_family') {
    $user = requireAuth();
    if ($user['family_id']) out(400, ['error' => 'Je zit al in een gezin.']);

    $code = strtoupper(trim($payload['invite_code'] ?? ''));
    if (!$code) out(400, ['error' => 'Vul een uitnodigingscode in.']);

    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM families WHERE invite_code = ?');
    $stmt->execute([$code]);
    $family = $stmt->fetch();
    if (!$family) out(404, ['error' => 'Ongeldige uitnodigingscode.']);

    $db->prepare('UPDATE users SET family_id = ? WHERE id = ?')->execute([$family['id'], $user['id']]);
    out(200, ['ok' => true, 'family_name' => $family['name']]);
}

// ════════════════════════════════════════════════════════
//  GEZINSLID REGISTREREN (zonder email, via gezinscode)
// ════════════════════════════════════════════════════════
if ($action === 'register_member') {
    $name = trim($payload['name'] ?? '');
    $code = strtoupper(trim($payload['invite_code'] ?? ''));
    $pass = $payload['password'] ?? '';

    if (strlen($name) < 2)  out(400, ['error' => 'Naam is te kort.']);
    if (strlen($pass) < 6)  out(400, ['error' => 'Wachtwoord moet minimaal 6 tekens zijn.']);
    if (strlen($code) !== 6) out(400, ['error' => 'Ongeldige gezinscode.']);

    $db   = getDB();

    // Zoek gezin op code
    $stmt = $db->prepare('SELECT * FROM families WHERE invite_code = ?');
    $stmt->execute([$code]);
    $family = $stmt->fetch();
    if (!$family) out(404, ['error' => 'Gezinscode niet gevonden.']);

    // Check of naam al bestaat in dit gezin
    $stmt = $db->prepare('SELECT id FROM users WHERE name = ? AND family_id = ?');
    $stmt->execute([$name, $family['id']]);
    if ($stmt->fetch()) out(409, ['error' => 'Deze naam is al in gebruik in dit gezin.']);

    $hash = password_hash($pass, PASSWORD_DEFAULT);

    // Maak gebruiker aan zonder email, direct gekoppeld aan gezin
    $stmt = $db->prepare('INSERT INTO users (name, password_hash, family_id, email_verified, auth_method) VALUES (?, ?, ?, 1, ?)');
    $stmt->execute([$name, $hash, $family['id'], 'password']);
    $userId = (int)$db->lastInsertId();

    // Maak sessie aan
    $token = createSession($userId);
    sendSessionCookie($token);

    out(200, ['ok' => true, 'user' => [
        'name'        => $name,
        'family_name' => $family['name'],
        'has_family'  => true,
        'is_owner'    => false,
    ]]);
}

// ════════════════════════════════════════════════════════
//  LIJST OPHALEN
// ════════════════════════════════════════════════════════
if ($action === 'get_list') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Je hebt nog geen gezin.']);

    $db   = getDB();
    $stmt = $db->prepare('SELECT data, updated_at, updated_by_name FROM lists WHERE family_id = ?');
    $stmt->execute([$user['family_id']]);
    $row  = $stmt->fetch();
    if (!$row) out(404, ['error' => 'Lijst niet gevonden.']);

    // Check push debounce: zijn 2 minuten verstreken na laatste opslag?
    if (function_exists('sendFamilyPush')) {
        $fStmt = $db->prepare('SELECT * FROM families WHERE id = ?');
        $fStmt->execute([$user['family_id']]);
        $fam = $fStmt->fetch();
        if ($fam && !empty($fam['push_pending']) && !empty($fam['push_pending_at'])) {
            $elapsed = time() - strtotime($fam['push_pending_at']);
            if ($elapsed >= PUSH_DELAY_SECONDS) {
                // Stuur push naar alle anderen
                $sStmt = $db->prepare('SELECT name FROM users WHERE id = ?');
                $sStmt->execute([$fam['push_sender_id']]);
                $sender = $sStmt->fetch();
                sendFamilyPush($db, $user['family_id'], (int)$fam['push_sender_id'],
                    $sender['name'] ?? 'Gezinslid', $fam['name'] ?? 'Weeklyst');
            }
        }
    }

    out(200, [
        'ok'              => true,
        'data'            => json_decode($row['data'], true),
        'updated_at'      => $row['updated_at'],
        'updated_by_name' => $row['updated_by_name'],
    ]);
}

// ════════════════════════════════════════════════════════
//  LIJST OPSLAAN
// ════════════════════════════════════════════════════════
if ($action === 'save_list') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Je hebt nog geen gezin.']);

    $data = $payload['data'] ?? null;
    if (!$data) out(400, ['error' => 'Geen data ontvangen.']);

    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    if (strlen($json) > 65536) out(413, ['error' => 'Data te groot.']);

    $db   = getDB();
    $stmt = $db->prepare('UPDATE lists SET data = ?, updated_by_name = ? WHERE family_id = ?');
    $stmt->execute([$json, $user['name'], $user['family_id']]);

    // Debounce push: sla pending op en reset timer bij elke opslag
    $db->prepare('UPDATE families SET push_pending=1, push_pending_at=NOW(), push_sender_id=? WHERE id=?')
       ->execute([$user['id'], $user['family_id']]);

    out(200, ['ok' => true, 'updated_by_name' => $user['name']]);
}

// ════════════════════════════════════════════════════════
//  GEZINSNAAM WIJZIGEN
// ════════════════════════════════════════════════════════
if ($action === 'update_family_name') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Je hebt nog geen gezin.']);
    $name = trim($payload['name'] ?? '');
    if (strlen($name) < 2) out(400, ['error' => 'Naam te kort.']);
    $db = getDB();
    $db->prepare('UPDATE families SET name=? WHERE id=?')->execute([$name, $user['family_id']]);
    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════════════
//  WACHTWOORD WIJZIGEN
// ════════════════════════════════════════════════════════
if ($action === 'change_password') {
    $user   = requireAuth();
    $oldPw  = $payload['old_password'] ?? '';
    $newPw  = $payload['new_password'] ?? '';
    if (strlen($newPw) < 8) out(400, ['error' => 'Nieuw wachtwoord minimaal 8 tekens.']);
    $db   = getDB();
    $stmt = $db->prepare('SELECT password_hash, auth_method FROM users WHERE id=?');
    $stmt->execute([$user['id']]);
    $row  = $stmt->fetch();
    if ($row['auth_method'] === 'magic') out(400, ['error' => 'Magic login gebruikers hebben geen wachtwoord.']);
    if (!password_verify($oldPw, $row['password_hash'])) {
        usleep(300000);
        out(401, ['error' => 'Huidig wachtwoord onjuist.']);
    }
    $hash = password_hash($newPw, PASSWORD_DEFAULT);
    $db->prepare('UPDATE users SET password_hash=? WHERE id=?')->execute([$hash, $user['id']]);
    out(200, ['ok' => true]);
}


// ════════════════════════════════════════════════════════
//  PUSH NOTIFICATIES
// ════════════════════════════════════════════════════════
if ($action === 'push_subscribe') {
    $user     = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Geen gezin.']);
    $endpoint = trim($payload['endpoint'] ?? '');
    $p256dh   = trim($payload['p256dh']   ?? '');
    $auth     = trim($payload['auth']     ?? '');
    if (!$endpoint || !$p256dh || !$auth) out(400, ['error' => 'Ongeldige subscription.']);
    $db = getDB();
    // Verwijder oude subscription van dit apparaat
    $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?')
       ->execute([$user['id'], $endpoint]);
    // Voeg nieuwe toe
    $db->prepare('INSERT INTO push_subscriptions (user_id, family_id, endpoint, p256dh, auth) VALUES (?,?,?,?,?)')
       ->execute([$user['id'], $user['family_id'], $endpoint, $p256dh, $auth]);
    out(200, ['ok' => true]);
}

if ($action === 'push_unsubscribe') {
    $user     = requireAuth();
    $endpoint = trim($payload['endpoint'] ?? '');
    if (!$endpoint) out(400, ['error' => 'Geen endpoint.']);
    $db = getDB();
    $db->prepare('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint = ?')
       ->execute([$user['id'], $endpoint]);
    out(200, ['ok' => true]);
}

if ($action === 'push_vapid_key') {
    // Geef publieke VAPID key terug voor subscription
    out(200, ['ok' => true, 'key' => defined('VAPID_PUBLIC_KEY') ? VAPID_PUBLIC_KEY : '']);
}

// ════════════════════════════════════════════════════════
//  KLANTENKAARTEN
// ════════════════════════════════════════════════════════
if ($action === 'get_cards') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Geen gezin.']);
    $db   = getDB();
    $stmt = $db->prepare('SELECT * FROM loyalty_cards WHERE family_id = ? ORDER BY shop_name ASC');
    $stmt->execute([$user['family_id']]);
    out(200, ['ok' => true, 'cards' => $stmt->fetchAll()]);
}

if ($action === 'add_card') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Geen gezin.']);
    $name  = trim($payload['shop_name'] ?? '');
    $code  = trim($payload['code'] ?? '');
    $type  = $payload['code_type'] ?? 'ean13';
    $color = $payload['color'] ?? '#2b6cb0';
    if (!$name || !$code) out(400, ['error' => 'Naam en code zijn verplicht.']);
    if (!in_array($type, ['ean13','code128','qr'])) $type = 'code128';
    $db   = getDB();
    $stmt = $db->prepare('INSERT INTO loyalty_cards (family_id, shop_name, code, code_type, color) VALUES (?,?,?,?,?)');
    $stmt->execute([$user['family_id'], $name, $code, $type, $color]);
    out(200, ['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

if ($action === 'delete_card') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Geen gezin.']);
    $cardId = (int)($payload['card_id'] ?? 0);
    if (!$cardId) out(400, ['error' => 'Geen kaart opgegeven.']);
    $db   = getDB();
    // Controleer dat kaart bij dit gezin hoort
    $stmt = $db->prepare('DELETE FROM loyalty_cards WHERE id = ? AND family_id = ?');
    $stmt->execute([$cardId, $user['family_id']]);
    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════════════
//  VERWIJDERVERZOEK
// ════════════════════════════════════════════════════════
if ($action === 'request_delete') {
    $user = requireAuth();
    $db   = getDB();

    // Haal gezinsinfo op
    $familyName = $user['family_name'] ?? 'Onbekend';
    $inviteCode = $user['invite_code'] ?? '';

    // Tel aantal leden
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM users WHERE family_id = ?');
    $stmt->execute([$user['family_id']]);
    $memberCount = $stmt->fetch()['cnt'] ?? 1;

    // Sla verwijderverzoek op in database
    try {
        $db->prepare('UPDATE families SET delete_requested = 1, delete_requested_at = NOW(), delete_requested_by = ? WHERE id = ?')
           ->execute([$user['name'] . ' (' . $user['email'] . ')', $user['family_id']]);
    } catch (Exception $e) {
        // Kolom bestaat nog niet — migratie nog niet uitgevoerd
    }

    // Stuur e-mail naar admin
    $subject = 'Weeklyst — Verwijderverzoek van ' . $familyName;
    $html = '
    <div style="font-family:Arial,sans-serif;max-width:500px;margin:0 auto;background:#eef3fa;border-radius:12px;overflow:hidden">
      <div style="background:#c0392b;padding:20px 24px">
        <h2 style="margin:0;color:#fff;font-size:18px">&#128465; Verwijderverzoek</h2>
      </div>
      <div style="padding:24px">
        <table style="width:100%;border-collapse:collapse">
          <tr><td style="padding:6px 0;font-size:13px;color:#4a6480;width:140px">Gezinsnaam</td><td style="padding:6px 0;font-size:14px;font-weight:600;color:#1a2433">' . htmlspecialchars($familyName) . '</td></tr>
          <tr><td style="padding:6px 0;font-size:13px;color:#4a6480">Uitnodigingscode</td><td style="padding:6px 0;font-size:14px;font-weight:600;color:#1a2433">' . htmlspecialchars($inviteCode) . '</td></tr>
          <tr><td style="padding:6px 0;font-size:13px;color:#4a6480">Aanvrager</td><td style="padding:6px 0;font-size:14px;color:#1a2433">' . htmlspecialchars($user['name']) . ' (' . htmlspecialchars($user['email']) . ')</td></tr>
          <tr><td style="padding:6px 0;font-size:13px;color:#4a6480">Aantal leden</td><td style="padding:6px 0;font-size:14px;color:#1a2433">' . $memberCount . '</td></tr>
          <tr><td style="padding:6px 0;font-size:13px;color:#4a6480">Tijdstip</td><td style="padding:6px 0;font-size:14px;color:#1a2433">' . date('d-m-Y H:i') . '</td></tr>
        </table>
        <div style="margin-top:20px;padding:14px;background:#fef2f2;border-radius:8px;font-size:13px;color:#c0392b;line-height:1.5">
          Verwijder via het admin dashboard alle leden van dit gezin en het gezin zelf uit de database.
        </div>
      </div>
    </div>';

    $mailSent = sendMail('info@weeklyst.nl', 'Weeklyst Admin', $subject, $html);
    // Stuur ook naar backup adres voor de zekerheid
    if (!$mailSent) {
        sendMail(SMTP_USER, 'Weeklyst Admin', $subject, $html);
    }
    out(200, ['ok' => true, 'mail_sent' => $mailSent]);
}

// ════════════════════════════════════════════════════════
//  VERSIE OPHALEN (geen auth vereist)
// ════════════════════════════════════════════════════════
if ($action === 'get_version' || ($method === 'GET' && isset($_GET['version']))) {
    $db   = getDB();
    try {
        $stmt = $db->query("SELECT value FROM app_settings WHERE `key` = 'app_version'");
        $row  = $stmt->fetch();
        $version = $row ? $row['value'] : '1.0';
    } catch (Exception $e) {
        $version = '1.0';
    }
    out(200, ['ok' => true, 'version' => $version]);
}

// ════════════════════════════════════════════════════════
//  BEHEERDER ROL TOGGLING (meerdere beheerders mogelijk)
// ════════════════════════════════════════════════════════
if ($action === 'transfer_owner') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Je hebt nog geen gezin.']);
    if (($user['role'] ?? 'member') !== 'owner') out(403, ['error' => 'Alleen een beheerder kan rollen wijzigen.']);

    $db         = getDB();
    $targetId   = (int)($payload['new_owner_id'] ?? 0);
    $makeOwner  = $payload['make_owner'] ?? true; // true = maak beheerder, false = verwijder rol

    if (!$targetId) out(400, ['error' => 'Geen lid opgegeven.']);

    // Check of lid in hetzelfde gezin zit
    $uStmt = $db->prepare('SELECT id, name, role FROM users WHERE id = ? AND family_id = ?');
    $uStmt->execute([$targetId, $user['family_id']]);
    $target = $uStmt->fetch();
    if (!$target) out(404, ['error' => 'Lid niet gevonden in dit gezin.']);

    $newRole = $makeOwner ? 'owner' : 'member';
    $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$newRole, $targetId]);

    // Sync ook owner_id in families voor backward compatibility
    if ($makeOwner) {
        $db->prepare('UPDATE families SET owner_id = ? WHERE id = ?')
           ->execute([$targetId, $user['family_id']]);
    }

    out(200, ['ok' => true, 'name' => $target['name'], 'is_owner' => $makeOwner]);
}

// ════════════════════════════════════════════════════════
//  GEZIN VERLATEN (gezinslid verwijdert zichzelf)
// ════════════════════════════════════════════════════════
if ($action === 'leave_family') {
    $user = requireAuth();
    if (!$user['family_id']) out(400, ['error' => 'Je zit niet in een gezin.']);

    $db = getDB();

    // Beheerder kan niet weggaan als hij de enige beheerder is
    if (($user['role'] ?? 'member') === 'owner') {
        $db = getDB();
        $countStmt = $db->prepare('SELECT COUNT(*) as cnt FROM users WHERE family_id = ? AND role = ?');
        $countStmt->execute([$user['family_id'], 'owner']);
        $ownerCount = $countStmt->fetch()['cnt'] ?? 0;
        if ($ownerCount <= 1) {
            out(403, ['error' => 'Je bent de enige beheerder. Maak eerst iemand anders beheerder voor je het gezin verlaat.']);
        }
    }

    // Verwijder het lid
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$user['id']]);

    // Verwijder sessie
    $token = getSessionToken();
    if ($token) {
        $db->prepare('DELETE FROM sessions WHERE token = ?')->execute([$token]);
    }
    clearSessionCookie();

    out(200, ['ok' => true]);
}

// ════════════════════════════════════════════════════════
//  GEZINSLID VERWIJDEREN
// ════════════════════════════════════════════════════════
if ($action === 'remove_member') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Je hebt nog geen gezin.']);

    $db = getDB();

    // Check of huidige gebruiker de beheerder is
    $fStmt = $db->prepare('SELECT owner_id FROM families WHERE id = ?');
    $fStmt->execute([$user['family_id']]);
    $family = $fStmt->fetch();
    if (!$family || $family['owner_id'] != $user['id']) {
        out(403, ['error' => 'Alleen de beheerder kan leden verwijderen.']);
    }

    $memberId = (int)($payload['member_id'] ?? 0);
    if (!$memberId) out(400, ['error' => 'Geen lid opgegeven.']);
    if ($memberId === (int)$user['id']) out(400, ['error' => 'Je kunt jezelf niet verwijderen.']);

    // Check of lid in hetzelfde gezin zit
    $mStmt = $db->prepare('SELECT id, name FROM users WHERE id = ? AND family_id = ?');
    $mStmt->execute([$memberId, $user['family_id']]);
    $member = $mStmt->fetch();
    if (!$member) out(404, ['error' => 'Lid niet gevonden.']);

    // Verwijder het lid
    $db->prepare('DELETE FROM users WHERE id = ?')->execute([$memberId]);

    out(200, ['ok' => true, 'removed' => $member['name']]);
}

// ════════════════════════════════════════════════════════
//  GEZINSLEDEN OPHALEN
// ════════════════════════════════════════════════════════
if ($action === 'get_members') {
    $user = requireAuth();
    if (!$user['family_id']) out(403, ['error' => 'Je hebt nog geen gezin.']);

    $db   = getDB();
    $stmt = $db->prepare('SELECT id, name, email, role, created_at FROM users WHERE family_id = ? ORDER BY created_at ASC');
    $stmt->execute([$user['family_id']]);
    $members = $stmt->fetchAll();

    $result = array_map(function($m) {
        return [
            'id'       => $m['id'],
            'name'     => $m['name'],
            'email'    => $m['email'],
            'is_owner' => ($m['role'] === 'owner'),
        ];
    }, $members);

    out(200, ['ok' => true, 'members' => $result]);
}

out(404, ['error' => 'Onbekende actie.']);