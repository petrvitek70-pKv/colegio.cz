<?php
// Veřejné API — vrací pouze zveřejněné zprávy
require_once __DIR__ . '/db.php';
corsHeaders();

$db = getFeedbackDb();

$limit = min((int)($_GET['limit'] ?? 6), 20);

$rows = $db->prepare("
    SELECT category, message, name, platform, created_at
    FROM feedback
    WHERE published = 1
    ORDER BY created_at DESC
    LIMIT ?
");
$rows->execute([$limit]);

jsonResponse(['feedback' => $rows->fetchAll(PDO::FETCH_ASSOC)]);
