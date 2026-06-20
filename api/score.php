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

// Ověření API secret
if (($body['secret'] ?? '') !== API_SECRET) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

// Validace vstupů
$nickname = trim($body['nickname'] ?? '');
$score    = (int)($body['score'] ?? 0);
$difficulty = $body['difficulty'] ?? '';
$guesses  = (int)($body['guesses'] ?? 0);
$seconds  = (int)($body['seconds'] ?? 0);

if (strlen($nickname) < 1 || strlen($nickname) > 20) {
    jsonResponse(['error' => 'Invalid nickname (1–20 chars)'], 422);
}
if (!preg_match('/^[\p{L}0-9 _\-\.]+$/u', $nickname)) {
    jsonResponse(['error' => 'Invalid nickname characters'], 422);
}
if ($score < 0 || $score > 999999) {
    jsonResponse(['error' => 'Invalid score'], 422);
}
if (!in_array($difficulty, ['easy', 'medium', 'classic', 'hard'])) {
    jsonResponse(['error' => 'Invalid difficulty'], 422);
}
if ($guesses < 1 || $guesses > 12) {
    jsonResponse(['error' => 'Invalid guesses'], 422);
}

// Uložení skóre
$db = getDb();
$stmt = $db->prepare(
    'INSERT INTO scores (nickname, score, difficulty, guesses, seconds) VALUES (?, ?, ?, ?, ?)'
);
$stmt->execute([$nickname, $score, $difficulty, $guesses, $seconds]);

jsonResponse(['success' => true, 'id' => $db->lastInsertId()], 201);
