<?php
require_once __DIR__ . '/db.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$difficulty = $_GET['difficulty'] ?? 'all';
$limit      = min((int)($_GET['limit']  ?? 20), 100);
$offset     = max((int)($_GET['offset'] ?? 0), 0);
$myNick     = trim($_GET['nickname'] ?? '');

$db = getDb();

// Celkový počet záznamů a hlavní seznam
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

function buildEntry(array $row, int $rank): array {
    return [
        'rank'       => $rank,
        'nickname'   => $row['nickname'],
        'score'      => (int)$row['score'],
        'difficulty' => $row['difficulty'],
        'guesses'    => (int)$row['guesses'],
        'seconds'    => (int)$row['seconds'],
        'timed'      => (int)($row['timed']      ?? 0) === 1,
        'repetition' => (int)($row['repetition'] ?? 0) === 1,
        'date'       => substr($row['created_at'], 0, 10),
    ];
}

$entries = array_map(function($row, $index) use ($offset) {
    return buildEntry($row, $offset + $index + 1);
}, $rows, array_keys($rows));

// Hráčův vlastní výsledek, pokud není v aktuálním seznamu
$myEntry = null;
if ($myNick !== '') {
    $nicksInList = array_column($entries, 'nickname');
    if (!in_array($myNick, $nicksInList)) {
        if ($difficulty === 'all') {
            $myStmt = $db->prepare(
                'SELECT nickname, score, difficulty, guesses, seconds, timed, repetition, created_at
                 FROM scores WHERE nickname = ?
                 ORDER BY score DESC LIMIT 1'
            );
            $myStmt->execute([$myNick]);
            $myRow = $myStmt->fetch(PDO::FETCH_ASSOC);
            if ($myRow) {
                $rankStmt = $db->prepare('SELECT COUNT(*) FROM scores WHERE score > ?');
                $rankStmt->execute([(int)$myRow['score']]);
                $myRank = (int)$rankStmt->fetchColumn() + 1;
                $myEntry = buildEntry($myRow, $myRank);
            }
        } else {
            $myStmt = $db->prepare(
                'SELECT nickname, score, difficulty, guesses, seconds, timed, repetition, created_at
                 FROM scores WHERE nickname = ? AND difficulty = ?
                 ORDER BY score DESC LIMIT 1'
            );
            $myStmt->execute([$myNick, $difficulty]);
            $myRow = $myStmt->fetch(PDO::FETCH_ASSOC);
            if ($myRow) {
                $rankStmt = $db->prepare('SELECT COUNT(*) FROM scores WHERE difficulty = ? AND score > ?');
                $rankStmt->execute([$difficulty, (int)$myRow['score']]);
                $myRank = (int)$rankStmt->fetchColumn() + 1;
                $myEntry = buildEntry($myRow, $myRank);
            }
        }
    }
}

jsonResponse([
    'leaderboard' => $entries,
    'count'       => count($entries),
    'total'       => (int)$total,
    'offset'      => $offset,
    'my_entry'    => $myEntry,
]);
