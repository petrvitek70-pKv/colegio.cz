<?php
require_once __DIR__ . '/db.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$difficulty = $_GET['difficulty'] ?? 'all';
$limit  = min((int)($_GET['limit']  ?? 20), 100);
$offset = max((int)($_GET['offset'] ?? 0), 0);

$db = getDb();

// Celkový počet záznamů pro danou obtížnost
if ($difficulty === 'all') {
    $total = (int)$db->query('SELECT COUNT(*) FROM scores')->fetchColumn();
    $stmt = $db->prepare(
        'SELECT nickname, score, difficulty, guesses, seconds, timed, repetition, created_at
         FROM scores
         ORDER BY score DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$limit, $offset]);
} else {
    if (!in_array($difficulty, ['easy', 'medium', 'classic', 'hard'])) {
        jsonResponse(['error' => 'Invalid difficulty'], 422);
    }
    $cntStmt = $db->prepare('SELECT COUNT(*) FROM scores WHERE difficulty = ?');
    $cntStmt->execute([$difficulty]);
    $total = (int)$cntStmt->fetchColumn();
    $stmt = $db->prepare(
        'SELECT nickname, score, difficulty, guesses, seconds, timed, repetition, created_at
         FROM scores
         WHERE difficulty = ?
         ORDER BY score DESC
         LIMIT ? OFFSET ?'
    );
    $stmt->execute([$difficulty, $limit, $offset]);
}

$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$entries = array_map(function($row, $index) use ($offset) {
    return [
        'rank'       => $offset + $index + 1,
        'nickname'   => $row['nickname'],
        'score'      => (int)$row['score'],
        'difficulty' => $row['difficulty'],
        'guesses'    => (int)$row['guesses'],
        'seconds'    => (int)$row['seconds'],
        'timed'      => (int)($row['timed']      ?? 0) === 1,
        'repetition' => (int)($row['repetition'] ?? 0) === 1,
        'date'       => substr($row['created_at'], 0, 10),
    ];
}, $rows, array_keys($rows));

jsonResponse(['leaderboard' => $entries, 'count' => count($entries), 'total' => (int)$total, 'offset' => $offset]);
