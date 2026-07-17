<?php
require_once __DIR__ . '/db.php';

corsHeaders();

// All color names available as seed values
define('COLORS_4', ['orange','skyBlue','green','yellow']);
define('COLORS_6', ['orange','skyBlue','green','yellow','blue','pink']);

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
            creator_nickname TEXT    NOT NULL DEFAULT '',
            created_at       INTEGER NOT NULL DEFAULT (strftime('%s','now'))
        );
        CREATE TABLE IF NOT EXISTS tournament_entries (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id  INTEGER NOT NULL,
            nickname       TEXT    NOT NULL,
            score          INTEGER NOT NULL DEFAULT 0,
            guesses        INTEGER NOT NULL DEFAULT 0,
            seconds        INTEGER NOT NULL DEFAULT 0,
            seed_issued_at INTEGER,
            submitted_at   INTEGER,
            UNIQUE(tournament_id, nickname),
            FOREIGN KEY(tournament_id) REFERENCES tournaments(id)
        );
        CREATE INDEX IF NOT EXISTS idx_te_tournament ON tournament_entries(tournament_id, score DESC);
    ");
    // Add creator_nickname column to existing DBs that were created before this migration
    try { $db->exec("ALTER TABLE tournaments ADD COLUMN creator_nickname TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    return $db;
}

function randomSeed(string $difficulty, int $allowRepetition): array {
    $pool = in_array($difficulty, ['classic','hard']) ? COLORS_6 : COLORS_4;
    $codeLen = $difficulty === 'hard' ? 5 : 4;
    $result = [];
    for ($i = 0; $i < $codeLen; $i++) {
        if ($allowRepetition) {
            $result[] = $pool[array_rand($pool)];
        } else {
            $remaining = array_values(array_diff($pool, $result));
            $result[] = $remaining[array_rand($remaining)];
        }
    }
    return $result;
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
            'creator_nickname' => $r['creator_nickname'],
        ];
    }
    jsonResponse(['tournaments' => $out]);
}

// ── MY TOURNAMENTS — list tournaments created by a specific nickname ──────────
if ($action === 'my_tournaments') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $nickname = trim($body['nickname'] ?? ($_GET['nickname'] ?? ''));
    $secret   = $body['secret'] ?? ($_GET['secret'] ?? '');
    if ($secret !== API_SECRET) jsonResponse(['error' => 'Unauthorized'], 401);
    if (!$nickname)             jsonResponse(['error' => 'Missing nickname'], 400);

    $stmt = $db->prepare("
        SELECT t.*, COUNT(e.id) AS player_count
        FROM tournaments t
        LEFT JOIN tournament_entries e ON e.tournament_id = t.id
        WHERE t.creator_nickname = ?
        GROUP BY t.id
        ORDER BY t.starts_at DESC
        LIMIT 20
    ");
    $stmt->execute([$nickname]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
            'creator_nickname' => $r['creator_nickname'],
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
    if (strlen($nickname) < 1 || strlen($nickname) > 20) jsonResponse(['error' => 'Invalid nickname'], 400);

    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) jsonResponse(['error' => 'Tournament not found'], 404);
    if (tournamentStatus($t) === 'upcoming') jsonResponse(['error' => 'Tournament not started yet'], 403);

    $stmt = $db->prepare("SELECT id, submitted_at, seed_issued_at FROM tournament_entries WHERE tournament_id = ? AND nickname = ?");
    $stmt->execute([$id, $nickname]);
    $entry = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$entry)              jsonResponse(['error' => 'Not joined'], 403);
    if ($entry['submitted_at']) jsonResponse(['error' => 'Already submitted'], 403);

    // Record when seed was issued (for minimum play-time enforcement at submit)
    if (!$entry['seed_issued_at']) {
        $db->prepare("UPDATE tournament_entries SET seed_issued_at = strftime('%s','now') WHERE tournament_id = ? AND nickname = ?")
           ->execute([$id, $nickname]);
    }

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
    if (!$entry)              jsonResponse(['error' => 'Not joined'], 403);
    if ($entry['submitted_at']) jsonResponse(['error' => 'Already submitted'], 403);

    // Enforce minimum elapsed time since seed was issued
    $issuedAt = (int)($entry['seed_issued_at'] ?? 0);
    if ($issuedAt > 0) {
        $elapsed = time() - $issuedAt;
        $minRequired = $guesses * 5;  // at least 5s per guess
        if ($elapsed < $minRequired) jsonResponse(['error' => 'Submitted too fast'], 400);
    }

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

// ── CREATE (any player) ───────────────────────────────────────────────────────
// Seed is generated server-side — creator never sees it, ensuring fair play.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($body['secret'] ?? '') !== API_SECRET) jsonResponse(['error' => 'Unauthorized'], 401);

    $name       = trim($body['name'] ?? '');
    $nickname   = trim($body['nickname'] ?? '');
    $difficulty = $body['difficulty'] ?? '';
    $game_mode  = $body['game_mode'] ?? 'classic';
    $rep        = (int)($body['allow_repetition'] ?? 0);
    $duration_h = (int)($body['duration_hours'] ?? 24);  // 1, 24, 72, 168

    if (!$name || strlen($name) > 40)  jsonResponse(['error' => 'Invalid name'], 400);
    if (!$nickname || strlen($nickname) < 1 || strlen($nickname) > 20) jsonResponse(['error' => 'Invalid nickname'], 400);
    if (!in_array($difficulty, ['easy','medium','classic','hard']))     jsonResponse(['error' => 'Invalid difficulty'], 400);
    if (!in_array($game_mode, ['classic','timed']))                     jsonResponse(['error' => 'Invalid game_mode'], 400);
    if (!in_array($duration_h, [1, 24, 72, 168]))                      jsonResponse(['error' => 'Invalid duration'], 400);

    // Spam protection: max 3 active or upcoming tournaments per creator
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM tournaments
        WHERE creator_nickname = ? AND ends_at > strftime('%s','now')
    ");
    $stmt->execute([$nickname]);
    if ((int)$stmt->fetchColumn() >= 3) {
        jsonResponse(['error' => 'Too many active tournaments. Wait for your existing ones to finish.'], 429);
    }

    $now       = time();
    $ends_at   = $now + $duration_h * 3600;
    $seed      = randomSeed($difficulty, $rep);

    $stmt = $db->prepare("
        INSERT INTO tournaments (name, difficulty, game_mode, allow_repetition, seed, starts_at, ends_at, creator_nickname)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([$name, $difficulty, $game_mode, $rep, json_encode($seed), $now, $ends_at, $nickname]);

    jsonResponse(['ok' => true, 'id' => (int)$db->lastInsertId()]);
}

// ── DELETE (creator only, upcoming/active; admin can delete anything) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $body     = json_decode(file_get_contents('php://input'), true) ?? [];
    $id       = (int)($body['id'] ?? 0);
    $nickname = trim($body['nickname'] ?? '');
    $isAdmin  = ($body['admin_secret'] ?? '') === ADMIN_SECRET;

    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) jsonResponse(['error' => 'Not found'], 404);

    if ($isAdmin) {
        // Admin can delete anything
    } elseif (($body['secret'] ?? '') === API_SECRET && $nickname) {
        // Creator can only delete while still upcoming
        if ($t['creator_nickname'] !== $nickname) jsonResponse(['error' => 'Not your tournament'], 403);
        if (tournamentStatus($t) !== 'upcoming')  jsonResponse(['error' => 'Tournament already started'], 403);
    } else {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $db->prepare("DELETE FROM tournament_entries WHERE tournament_id = ?")->execute([$id]);
    $db->prepare("DELETE FROM tournaments WHERE id = ?")->execute([$id]);

    jsonResponse(['ok' => true]);
}

// ── DISQUALIFY (admin only) — remove one player's entry ──────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'disqualify') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    if (($body['admin_secret'] ?? '') !== ADMIN_SECRET) jsonResponse(['error' => 'Unauthorized'], 401);

    $tournament_id = (int)($body['tournament_id'] ?? 0);
    $nickname      = trim($body['nickname'] ?? '');
    if (!$tournament_id || !$nickname) jsonResponse(['error' => 'Missing params'], 400);

    $stmt = $db->prepare("DELETE FROM tournament_entries WHERE tournament_id = ? AND nickname = ?");
    $stmt->execute([$tournament_id, $nickname]);

    jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

// ── ENTRIES (admin only) — list all entries for a tournament ─────────────────
if ($action === 'entries') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    // Support both GET (with query param) and POST (with JSON body)
    $admin_secret  = $body['admin_secret'] ?? ($_GET['admin_secret'] ?? '');
    if ($admin_secret !== ADMIN_SECRET) jsonResponse(['error' => 'Unauthorized'], 401);

    $id = (int)($_GET['id'] ?? ($body['id'] ?? 0));
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $stmt = $db->prepare("
        SELECT nickname, score, guesses, seconds, seed_issued_at, submitted_at
        FROM tournament_entries
        WHERE tournament_id = ?
        ORDER BY score DESC, guesses ASC, seconds ASC
    ");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'nickname'      => $r['nickname'],
            'score'         => (int)$r['score'],
            'guesses'       => (int)$r['guesses'],
            'seconds'       => (int)$r['seconds'],
            'seed_issued_at'=> $r['seed_issued_at'] ? (int)$r['seed_issued_at'] : null,
            'submitted_at'  => $r['submitted_at']   ? (int)$r['submitted_at']   : null,
        ];
    }
    jsonResponse(['entries' => $out]);
}

jsonResponse(['error' => 'Unknown action'], 400);
