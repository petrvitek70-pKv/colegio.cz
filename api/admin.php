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

function computeExpectedScore(int $guesses, int $maxGuesses, int $seconds, bool $isTimed, int $scoreMultiplier): int {
    $guessBonus = match(true) {
        $guesses === 1 => 5000,
        $guesses === 2 => 3000,
        default        => ($maxGuesses - $guesses) * 500,
    };
    $timePenalty    = $isTimed ? 0 : $seconds * 5;
    $modeMultiplier = $isTimed ? 2 : 1;
    return max(0, ($guessBonus - $timePenalty) * $scoreMultiplier * $modeMultiplier);
}

$DIFFICULTY_LIMITS = [
    'easy'    => ['maxGuesses' => 12, 'scoreMultiplier' => 1],
    'medium'  => ['maxGuesses' => 10, 'scoreMultiplier' => 3],
    'classic' => ['maxGuesses' => 10, 'scoreMultiplier' => 4],
    'hard'    => ['maxGuesses' =>  8, 'scoreMultiplier' => 6],
];

$action = $body['action'] ?? '';
$db = getDb();

// Smaže jeden konkrétní záznam
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
    jsonResponse(['success' => true, 'deleted' => $stmt->rowCount()]);
}

// Automatický audit — smaže vše co nesplňuje pravidla
if ($action === 'audit') {
    $flagged = [];

    $rows = $db->query('SELECT * FROM scores')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $row) {
        $limits = $DIFFICULTY_LIMITS[$row['difficulty']] ?? null;
        if (!$limits) continue;

        $guesses = (int)$row['guesses'];
        $seconds = (int)$row['seconds'];
        $score   = (int)$row['score'];
        $isTimed    = (int)($row['timed']      ?? 0) === 1;
        $repetition = (int)($row['repetition'] ?? 0) === 1;
        $maxG    = $limits['maxGuesses'];
        $mult    = $limits['scoreMultiplier'] * ($repetition ? 2 : 1);

        $reasons = [];

        // Pravidlo 1: guesses=1 zakázáno
        if ($guesses === 1) {
            $reasons[] = 'guesses=1';
        }

        // Pravidlo 2: příliš rychlý čas
        if ($seconds < $guesses * 5) {
            $reasons[] = "seconds($seconds) < guesses*5(" . ($guesses * 5) . ")";
        }

        // Pravidlo 3: skóre neodpovídá uloženému módu
        if (empty($reasons)) {
            $expected = computeExpectedScore($guesses, $maxG, $seconds, $isTimed, $mult);
            if ($score !== $expected) {
                $reasons[] = "score mismatch (got=$score, expected=$expected, timed=" . ($isTimed?'true':'false') . ")";
            }
        }

        if (!empty($reasons)) {
            $flagged[] = [
                'id'         => $row['id'],
                'nickname'   => $row['nickname'],
                'score'      => $score,
                'difficulty' => $row['difficulty'],
                'guesses'    => $guesses,
                'seconds'    => $seconds,
                'timed'      => $isTimed,
                'repetition' => $repetition,
                'reasons'    => $reasons,
            ];
        }
    }

    if (empty($flagged)) {
        jsonResponse(['success' => true, 'deleted' => 0, 'message' => 'No invalid entries found']);
    }

    $ids = array_column($flagged, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("DELETE FROM scores WHERE id IN ($placeholders)");
    $stmt->execute($ids);

    jsonResponse(['success' => true, 'deleted' => $stmt->rowCount(), 'entries' => $flagged]);
}

jsonResponse(['error' => 'Unknown action'], 422);
