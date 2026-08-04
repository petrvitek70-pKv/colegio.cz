<?php
require_once __DIR__ . '/db.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    jsonResponse(['error' => 'Invalid JSON'], 400);
}

if (($body['admin_secret'] ?? '') !== ADMIN_SECRET) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$action = $body['action'] ?? '';
$db = getDb();

if ($action === 'delete') {
    $nickname   = $body['nickname']   ?? null;
    $score      = $body['score']      ?? null;
    $guesses    = $body['guesses']    ?? null;
    $seconds    = $body['seconds']    ?? null;
    $difficulty = $body['difficulty'] ?? null;

    if (!$nickname || $score === null || $guesses === null || $seconds === null || !$difficulty) {
        jsonResponse(['error' => 'Missing parameters'], 422);
    }

    $stmt = $db->prepare(
        'DELETE FROM scores WHERE nickname=? AND score=? AND guesses=? AND seconds=? AND difficulty=?'
    );
    $stmt->execute([$nickname, $score, $guesses, $seconds, $difficulty]);
    $deleted = $stmt->rowCount();
    jsonResponse(['success' => true, 'deleted' => $deleted]);
}

if ($action === 'delete_invalid') {
    // Smaže všechny záznamy které nesplňují nová pravidla (guesses=1, nebo seconds < guesses*5)
    $stmt = $db->prepare(
        'DELETE FROM scores WHERE guesses = 1 OR seconds < guesses * 5'
    );
    $stmt->execute();
    $deleted = $stmt->rowCount();
    jsonResponse(['success' => true, 'deleted' => $deleted]);
}

jsonResponse(['error' => 'Unknown action'], 422);
