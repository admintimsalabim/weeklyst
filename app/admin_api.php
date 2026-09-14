<?php
/**
 * Weeklyst — admin_api.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed']); exit;
}

$payload = json_decode(file_get_contents('php://input'), true) ?? [];
$action  = $payload['action'] ?? '';
$pass    = $payload['admin_password'] ?? '';

if (!hash_equals(INVITE_PASSWORD, $pass)) {
    usleep(500000);
    http_response_code(401);
    echo json_encode(['error' => 'Ongeldig wachtwoord.']);
    exit;
}

$db = getDB();

if ($action === 'check_admin') {
    echo json_encode(['ok' => true]); exit;
}

if ($action === 'get_families') {
    $families = $db->query('SELECT * FROM families ORDER BY created_at DESC')->fetchAll();
    $result = [];
    foreach ($families as $f) {
        $stmt = $db->prepare('SELECT id, name, email, created_at FROM users WHERE family_id = ?');
        $stmt->execute([$f['id']]);
        $members = $stmt->fetchAll();
        $result[] = [
            'id'                  => $f['id'],
            'name'                => $f['name'],
            'invite_code'         => $f['invite_code'],
            'created_at'          => $f['created_at'],
            'member_count'        => count($members),
            'members'             => $members,
            'delete_requested'    => (bool)($f['delete_requested'] ?? false),
            'delete_requested_at' => $f['delete_requested_at'] ?? null,
            'delete_requested_by' => $f['delete_requested_by'] ?? null,
        ];
    }
    echo json_encode(['ok' => true, 'families' => $result]); exit;
}

if ($action === 'delete_family') {
    $familyId = (int)($payload['family_id'] ?? 0);
    if (!$familyId) { echo json_encode(['error' => 'Geen gezin opgegeven.']); exit; }

    $stmt = $db->prepare('SELECT name FROM families WHERE id = ?');
    $stmt->execute([$familyId]);
    $family = $stmt->fetch();
    if (!$family) { echo json_encode(['error' => 'Gezin niet gevonden.']); exit; }

    try {
        // Juiste volgorde: eerst records zonder FK referentie verwijderen
        // dan pas het parent record

        // 1. Haal user IDs op voor verwijdering
        $uStmt = $db->prepare('SELECT id FROM users WHERE family_id = ?');
        $uStmt->execute([$familyId]);
        $userIds = $uStmt->fetchAll(PDO::FETCH_COLUMN);

        // 2. Verwijder de weeklijst (FK naar families)
        $db->prepare('DELETE FROM lists WHERE family_id = ?')
           ->execute([$familyId]);

        // 3. Koppel users los zodat FK naar families wegvalt
        $db->prepare('UPDATE users SET family_id = NULL WHERE family_id = ?')
           ->execute([$familyId]);

        // 4. Verwijder het gezin
        $db->prepare('DELETE FROM families WHERE id = ?')
           ->execute([$familyId]);

        // 5. Verwijder de users (family_id is nu NULL dus geen FK blokkering)
        if (!empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $db->prepare("DELETE FROM users WHERE id IN ($placeholders)")
               ->execute($userIds);
        }

        echo json_encode(['ok' => true, 'deleted' => $family['name']]); exit;

    } catch (Exception $e) {
        echo json_encode(['error' => 'Fout: ' . $e->getMessage()]); exit;
    }
}

// ── Versie ophalen ───────────────────────────────────────
if ($action === 'get_version') {
    try {
        $stmt = $db->query("SELECT value FROM app_settings WHERE `key` = 'app_version'");
        $row  = $stmt->fetch();
        echo json_encode(['ok' => true, 'version' => $row ? $row['value'] : '1.0']); exit;
    } catch (Exception $e) {
        echo json_encode(['ok' => true, 'version' => '1.0']); exit;
    }
}

// ── Versie opslaan ────────────────────────────────────────
if ($action === 'set_version') {
    $version = trim($payload['version'] ?? '');
    if (!$version) { echo json_encode(['error' => 'Geen versienummer opgegeven.']); exit; }
    try {
        $db->prepare("INSERT INTO app_settings (`key`, `value`) VALUES ('app_version', ?)
                      ON DUPLICATE KEY UPDATE `value` = ?, `updated_at` = NOW()")
           ->execute([$version, $version]);
        echo json_encode(['ok' => true, 'version' => $version]); exit;
    } catch (Exception $e) {
        echo json_encode(['error' => 'Opslaan mislukt: ' . $e->getMessage()]); exit;
    }
}

echo json_encode(['error' => 'Onbekende actie.']);
