<?php
// Admin API — vrací seznam zpětné vazby (chráněno API klíčem)
require_once __DIR__ . '/db.php';

corsHeaders();

$key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
if ($key !== ADMIN_SECRET) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$dbPath = __DIR__ . '/../data/feedback.db';
if (!file_exists($dbPath)) {
    jsonResponse(['feedback' => []]);
}

$db = new PDO('sqlite:' . $dbPath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Akce: zveřejnit/skrýt zprávu
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $id        = (int)($body['id']        ?? 0);
    $published = (int)($body['published'] ?? 0);
    if ($id > 0) {
        $db->prepare("UPDATE feedback SET published=? WHERE id=?")->execute([$published, $id]);
        jsonResponse(['status' => 'ok']);
    }
    jsonResponse(['error' => 'Missing id'], 422);
}

$limit  = min((int)($_GET['limit'] ?? 100), 500);
$offset = (int)($_GET['offset'] ?? 0);

$rows = $db->prepare("
    SELECT id, category, message, name, email, platform, app_lang, published, created_at
    FROM feedback
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
");
$rows->execute([$limit, $offset]);

$total = $db->query("SELECT COUNT(*) FROM feedback")->fetchColumn();

jsonResponse([
    'total'    => (int)$total,
    'feedback' => $rows->fetchAll(PDO::FETCH_ASSOC),
]);
