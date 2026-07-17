<?php
require_once __DIR__ . '/db.php';

corsHeaders();

function getTournamentDb(): PDO {
    $db = getDb();
    $db->exec("PRAGMA journal_mode=WAL;");
    $db->exec("
        CREATE TABLE IF NOT EXISTS tournaments (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            name             TEXT    NOT NULL,
            difficulty       TEXT    NOT NULL,
            game_mode        TEXT    NOT NULL DEFAULT 'classic',
            allow_repetition INTEGER NOT NULL DEFAULT 0,
            seed             TEXT    NOT NULL,
            starts_at        INTEGER NOT NULL,
            ends_at          INTEGER NOT NULL,
            created_at       INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS tournament_entries (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id INTEGER NOT NULL,
            nickname      TEXT    NOT NULL,
            score         INTEGER NOT NULL DEFAULT 0,
            guesses       INTEGER NOT NULL DEFAULT 0,
            seconds       INTEGER NOT NULL DEFAULT 0,
            submitted_at  INTEGER,
            UNIQUE(tournament_id, nickname),
            FOREIGN KEY(tournament_id) REFERENCES tournaments(id)
        );
        CREATE INDEX IF NOT EXISTS idx_te_tournament ON tournament_entries(tournament_id, score DESC);
    ");
    return $db;
}

function tournamentStatus(array $t): string {
    $now = time();
    if ($now < $t['starts_at']) return 'upcoming';
    if ($now > $t['ends_at'])   return 'finished';
    return 'active';
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getTournamentDb();

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $rows = $db->query("
        SELECT t.*, COUNT(e.id) AS player_count
        FROM tournaments t
        LEFT JOIN tournament_entries e ON e.tournament_id = t.id
        GROUP BY t.id
        ORDER BY t.starts_at DESC
        LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'               => (int)$r['id'],
            'name'             => $r['name'],
            'difficulty'       => $r['difficulty'],
            'game_mode'        => $r['game_mode'],
            'allow_repetition' => (bool)$r['allow_repetition'],
            'starts_at'        => (int)$r['starts_at'],
            'ends_at'          => (int)$r['ends_at'],
            'status'           => tournamentStatus($r),
            'player_count'     => (int)$r['player_count'],
        ];
    }
    jsonResponse(['tournaments' => $out]);
}

// ── SEED (get secret code after joining) — POST only, requires API secret ───
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'seed') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($body['tournament_id'] ?? 0);
    $nickname = trim($body['nickname'] ?? '');
    $secret   = $body['secret'] ?? '';

    if ($secret !== API_SECRET)   jsonResponse(['error' => 'Unauthorized'], 401);
    if (!$id || !$nickname)       jsonResponse(['error' => 'Missing params'], 400);

    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) jsonResponse(['error' => 'Tournament not found'], 404);
    if (tournamentStatus($t) === 'upcoming') jsonResponse(['error' => 'Tournament not started yet'], 403);

    $stmt = $db->prepare("SELECT id, submitted_at FROM tournament_entries WHERE tournament_id = ? AND nickname = ?");
    $stmt->execute([$id, $nickname]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry)              jsonResponse(['error' => 'Not joined'], 403);
    if ($entry['submitted_at']) jsonResponse(['error' => 'Already submitted'], 403);

    jsonResponse(['seed' => json_decode($t['seed'])]);
}

// ── LEADERBOARD ──────────────────────────────────────────────────────────────
if ($action === 'leaderboard') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) jsonResponse(['error' => 'Not found'], 404);

    $stmt = $db->prepare("
        SELECT nickname, score, guesses, seconds, submitted_at
        FROM tournament_entries
        WHERE tournament_id = ? AND submitted_at IS NOT NULL
        ORDER BY score DESC, guesses ASC, seconds ASC
        LIMIT 100
    ");
    $stmt->execute([$id]);
    $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($entries as $i => $e) {
        $out[] = [
            'rank'     => $i + 1,
            'nickname' => $e['nickname'],
            'score'    => (int)$e['score'],
            'guesses'  => (int)$e['guesses'],
            'seconds'  => (int)$e['seconds'],
        ];
    }
    jsonResponse([
        'tournament' => [
            'id'     => (int)$t['id'],
            'name'   => $t['name'],
            'status' => tournamentStatus($t),
        ],
        'entries' => $out,
    ]);
}

// ── JOIN ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'join') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($body['tournament_id'] ?? 0);
    $nickname = trim($body['nickname'] ?? '');
    $secret   = $body['secret'] ?? '';

    if ($secret !== API_SECRET)           jsonResponse(['error' => 'Unauthorized'], 401);
    if (!$id || !$nickname)               jsonResponse(['error' => 'Missing params'], 400);
    if (strlen($nickname) < 1 || strlen($nickname) > 20) jsonResponse(['error' => 'Invalid nickname'], 400);

    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) jsonResponse(['error' => 'Tournament not found'], 404);
    if (tournamentStatus($t) === 'finished') jsonResponse(['error' => 'Tournament finished'], 403);

    $stmt = $db->prepare("INSERT OR IGNORE INTO tournament_entries (tournament_id, nickname) VALUES (?, ?)");
    $stmt->execute([$id, $nickname]);

    jsonResponse(['ok' => true, 'joined' => true]);
}

// ── SUBMIT ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'submit') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($body['tournament_id'] ?? 0);
    $nickname = trim($body['nickname'] ?? '');
    $score    = (int)($body['score'] ?? -1);
    $guesses  = (int)($body['guesses'] ?? 0);
    $seconds  = (int)($body['seconds'] ?? 0);
    $secret   = $body['secret'] ?? '';

    if ($secret !== API_SECRET)   jsonResponse(['error' => 'Unauthorized'], 401);
    if (!$id || !$nickname)       jsonResponse(['error' => 'Missing params'], 400);
    if ($score < 0)               jsonResponse(['error' => 'Invalid score'], 400);

    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) jsonResponse(['error' => 'Tournament not found'], 404);
    if (tournamentStatus($t) !== 'active') jsonResponse(['error' => 'Tournament not active'], 403);

    // Must be joined and not yet submitted
    $stmt = $db->prepare("SELECT * FROM tournament_entries WHERE tournament_id = ? AND nickname = ?");
    $stmt->execute([$id, $nickname]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry)            jsonResponse(['error' => 'Not joined'], 403);
    if ($entry['submitted_at']) jsonResponse(['error' => 'Already submitted'], 403);

    // Validate score server-side
    $seed = json_decode($t['seed'], true);
    $maxGuesses = match($t['difficulty']) { 'easy' => 12, 'hard' => 8, default => 10 };
    $scoreMultiplier = match($t['difficulty']) { 'easy' => 1, 'medium' => 3, 'classic' => 4, 'hard' => 6 };
    if ($t['allow_repetition']) $scoreMultiplier *= 2;
    $isTimed = $t['game_mode'] === 'timed';

    if ($guesses < 1 || $guesses > $maxGuesses) jsonResponse(['error' => 'Invalid guesses'], 400);
    if ($seconds < $guesses * 3)                jsonResponse(['error' => 'Invalid time'], 400);

    $guessBonus = match(true) {
        $guesses === 1 => 5000,
        $guesses === 2 => 3000,
        default        => ($maxGuesses - $guesses) * 500,
    };
    $timePenalty   = $isTimed ? 0 : $seconds * 5;
    $modeMultiplier = $isTimed ? 2 : 1;
    $expected = max(0, ($guessBonus - $timePenalty) * $scoreMultiplier * $modeMultiplier);
    if ($score !== $expected) jsonResponse(['error' => 'Score mismatch'], 400);

    $stmt = $db->prepare("
        UPDATE tournament_entries
        SET score = ?, guesses = ?, seconds = ?, submitted_at = strftime('%s','now')
        WHERE tournament_id = ? AND nickname = ?
    ");
    $stmt->execute([$score, $guesses, $seconds, $id, $nickname]);

    jsonResponse(['ok' => true, 'score' => $score]);
}

// ── CREATE (admin only) ──────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($body['admin_secret'] ?? '') !== ADMIN_SECRET) jsonResponse(['error' => 'Unauthorized'], 401);

    $name       = trim($body['name'] ?? '');
    $difficulty = $body['difficulty'] ?? '';
    $game_mode  = $body['game_mode'] ?? 'classic';
    $rep        = (int)($body['allow_repetition'] ?? 0);
    $seed       = $body['seed'] ?? [];
    $starts_at  = (int)($body['starts_at'] ?? 0);
    $ends_at    = (int)($body['ends_at'] ?? 0);

    if (!$name || !in_array($difficulty, ['easy','medium','classic','hard'])) jsonResponse(['error' => 'Invalid params'], 400);
    if (!in_array($game_mode, ['classic','timed']))  jsonResponse(['error' => 'Invalid game_mode'], 400);
    if (!is_array($seed) || count($seed) < 4)        jsonResponse(['error' => 'Invalid seed'], 400);
    if ($starts_at >= $ends_at)                       jsonResponse(['error' => 'Invalid dates'], 400);

    $stmt = $db->prepare("
        INSERT INTO tournaments (name, difficulty, game_mode, allow_repetition, seed, starts_at, ends_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $difficulty, $game_mode, $rep, json_encode($seed), $starts_at, $ends_at]);

    jsonResponse(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

jsonResponse(['error' => 'Unknown action'], 400);
