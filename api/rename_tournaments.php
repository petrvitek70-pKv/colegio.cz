<?php
// One-time script to rename tournaments — DELETE AFTER USE
require_once __DIR__ . '/db.php';

$db = getDb();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$renames = [
    'Get Classic game with me.'   => 'Classic Open — August',
    'Today Medium  come and play'  => 'Medium Speed Challenge',
    'For Retarmind'               => 'Medium Masters Cup',
    'Hey come and play'           => 'Easy Starter — Join Now',
    'Andrey. Play now.'           => 'Easy Blitz Round',
];

$results = [];
foreach ($renames as $old => $new) {
    $stmt = $db->prepare('UPDATE tournaments SET name = ? WHERE name = ?');
    $stmt->execute([$new, $old]);
    $results[] = ['old' => $old, 'new' => $new, 'rows' => $stmt->rowCount()];
}

header('Content-Type: application/json');
echo json_encode(['ok' => true, 'results' => $results], JSON_PRETTY_PRINT);
