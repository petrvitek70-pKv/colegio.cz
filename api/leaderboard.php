<?php
require_once __DIR__ . '/db.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$difficulty = $_GET['difficulty'] ?? 'all';
$limit = min((int)($_GET['limit'] ?? 100), 100);

$db = getDb();

if ($difficulty === 'all') {
    $stmt = $db->prepare(
        'SELECT nickname, score, difficulty, guesses, seconds, created_at
         FROM scores
         ORDER BY score DESC
         LIMIT ?'
    );
    $stmt->execute([$limit]);
} else {
    if (!in_array($difficulty, ['easy', 'medium', 'classic', 'hard'])) {
        jsonResponse(['error' => 'Invalid difficulty'], 422);
    }
    $stmt = $db->prepare(
        'SELECT nickname, score, difficulty, guesses, seconds, created_at
         FROM scores
         WHERE difficulty = ?
         ORDER BY score DESC
         LIMIT ?'
    );
    $stmt->execute([$difficulty, $limit]);
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$entries = array_map(function($row, $index) {
    return [
        'rank'       => $index + 1,
        'nickname'   => $row['nickname'],
        'score'      => (int)$row['score'],
        'difficulty' => $row['difficulty'],
        'guesses'    => (int)$row['guesses'],
        'seconds'    => (int)$row['seconds'],
        'date'       => substr($row['created_at'], 0, 10),
    ];
}, $rows, array_keys($rows));

jsonResponse(['leaderboard' => $entries, 'count' => count($entries)]);
