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
        CREATE TABLE IF NOT EXISTS tournament_reactions (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id  INTEGER NOT NULL,
            nickname       TEXT    NOT NULL,
            reaction       TEXT    NOT NULL,
            created_at     INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(tournament_id, nickname)
        );
        CREATE TABLE IF NOT EXISTS player_reactions (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            tournament_id  INTEGER NOT NULL,
            from_nickname  TEXT    NOT NULL,
            to_nickname    TEXT    NOT NULL,
            emoji          TEXT    NOT NULL,
            created_at     INTEGER NOT NULL DEFAULT (strftime('%s','now')),
            UNIQUE(tournament_id, from_nickname, to_nickname)
        );
        CREATE INDEX IF NOT EXISTS idx_te_tournament ON tournament_entries(tournament_id, score DESC);
        CREATE INDEX IF NOT EXISTS idx_tr_tournament ON tournament_reactions(tournament_id);
        CREATE INDEX IF NOT EXISTS idx_pr_tournament ON player_reactions(tournament_id);
    ");
    // Migrations for columns added after initial schema
    try { $db->exec("ALTER TABLE tournaments ADD COLUMN creator_nickname TEXT NOT NULL DEFAULT ''"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE tournament_entries ADD COLUMN seed_issued_at INTEGER"); } catch (\Exception $e) {}
    try { $db->exec("ALTER TABLE tournament_entries ADD COLUMN ms INTEGER NOT NULL DEFAULT 0"); } catch (\Exception $e) {}
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

$action   = $_GET['action'] ?? $_POST['action'] ?? '';
$db       = getTournamentDb();
$rawBody  = file_get_contents('php://input');
$reqBody  = json_decode($rawBody, true) ?? [];

function isAdminRequest(): bool {
    global $reqBody;
    $headers   = getallheaders();
    $headerKey = $headers['X-Admin-Key'] ?? $headers['x-admin-key'] ?? '';
    if ($headerKey === ADMIN_SECRET) return true;
    return ($reqBody['admin_secret'] ?? '') === ADMIN_SECRET;
}

// ── LIST ──────────────────────────────────────────────────────────────────────
if ($action === 'list') {
    $isAdmin = isAdminRequest();

    // Active/upcoming: all; finished: max 10 newest
    $rows = $db->query("
        SELECT t.*, COUNT(e.id) AS player_count
        FROM tournaments t
        LEFT JOIN tournament_entries e ON e.tournament_id = t.id
        GROUP BY t.id
        ORDER BY t.ends_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Split by status and limit finished to 10
    $active   = [];
    $finished = [];
    foreach ($rows as $r) {
        $status = tournamentStatus($r);
        if ($status === 'finished') {
            if (count($finished) < 10) $finished[] = $r;
        } else {
            $active[] = $r;
        }
    }
    $rows = array_merge($active, $finished);

    $out = [];
    foreach ($rows as $r) {
        $item = [
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
        if ($isAdmin) $item['seed'] = json_decode($r['seed']);
        $out[] = $item;
    }
    jsonResponse(['tournaments' => $out]);
}

// ── MY TOURNAMENTS — list tournaments created by a specific nickname ──────────
if ($action === 'my_tournaments') {
    $body     = $reqBody;
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
    $body     = $reqBody;
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
        SELECT nickname, score, guesses, seconds, ms, submitted_at
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
            'ms'       => (int)$e['ms'],
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
    $body     = $reqBody;
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
    $body     = $reqBody;
    $id       = (int)($body['tournament_id'] ?? 0);
    $nickname = trim($body['nickname'] ?? '');
    $score    = (int)($body['score'] ?? -1);
    $guesses  = (int)($body['guesses'] ?? 0);
    $seconds  = (int)($body['seconds'] ?? 0);
    $ms       = isset($body['ms']) ? (int)$body['ms'] : $seconds * 1000;
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
    if ($seconds < $guesses * 5)                jsonResponse(['error' => 'Invalid time'], 400);

    $guessBonus = match(true) {
        $guesses === 1 => 5000,
        $guesses === 2 => 3000,
        default        => ($maxGuesses - $guesses) * 500,
    };
    $modeMultiplier  = $isTimed ? 2 : 1;
    // Accept score computed either way: ms-precision (new clients) or seconds*5 (old clients without ms)
    $penaltyMs  = $isTimed ? 0 : (int)floor($ms * 0.005);
    $penaltySec = $isTimed ? 0 : $seconds * 5;
    $expectedMs  = max(0, ($guessBonus - $penaltyMs)  * $scoreMultiplier * $modeMultiplier);
    $expectedSec = max(0, ($guessBonus - $penaltySec) * $scoreMultiplier * $modeMultiplier);
    if ($score !== $expectedMs && $score !== $expectedSec) {
        jsonResponse(['error' => 'Score mismatch: expected '.$expectedMs.' or '.$expectedSec.' got '.$score], 400);
    }

    $stmt = $db->prepare("
        UPDATE tournament_entries
        SET score = ?, guesses = ?, seconds = ?, ms = ?, submitted_at = strftime('%s','now')
        WHERE tournament_id = ? AND nickname = ?
    ");
    $stmt->execute([$score, $guesses, $seconds, $ms, $id, $nickname]);

    jsonResponse(['ok' => true, 'score' => $score]);
}

// ── CREATE (any player, or admin) ────────────────────────────────────────────
// Seed is generated server-side — creator never sees it, ensuring fair play.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create') {
    $body    = $reqBody;
    $isAdmin = isAdminRequest();
    if (!$isAdmin && ($body['secret'] ?? '') !== API_SECRET) jsonResponse(['error' => 'Unauthorized'], 401);

    $name       = trim($body['name'] ?? '');
    $nickname   = trim($body['nickname'] ?? '');
    $difficulty = $body['difficulty'] ?? '';
    $game_mode  = $body['game_mode'] ?? 'classic';
    $rep        = (int)($body['allow_repetition'] ?? 0);
    $duration_h = (int)($body['duration_hours'] ?? 24);  // 1, 24, 72, 168

    if (!$name || strlen($name) > 40)  jsonResponse(['error' => 'Invalid name'], 400);
    if (!$isAdmin && (!$nickname || strlen($nickname) < 1 || strlen($nickname) > 20)) jsonResponse(['error' => 'Invalid nickname'], 400);
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

// ── UPDATE (admin only) ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    $body = $reqBody;
    if (!isAdminRequest()) jsonResponse(['error' => 'Unauthorized'], 401);

    $id       = (int)($body['id'] ?? 0);
    $name     = trim($body['name'] ?? '');
    $starts   = (int)($body['starts_at'] ?? 0);
    $ends     = (int)($body['ends_at'] ?? 0);
    $seed     = $body['seed'] ?? null;

    if (!$id)   jsonResponse(['error' => 'Missing id'], 400);
    if (!$name || strlen($name) > 40) jsonResponse(['error' => 'Invalid name'], 400);
    if ($starts && $ends && $starts >= $ends) jsonResponse(['error' => 'ends_at must be after starts_at'], 400);

    $stmt = $db->prepare("SELECT id FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) jsonResponse(['error' => 'Not found'], 404);

    $sets = ['name = ?'];
    $params = [$name];
    if ($starts) { $sets[] = 'starts_at = ?'; $params[] = $starts; }
    if ($ends)   { $sets[] = 'ends_at = ?';   $params[] = $ends; }
    if ($seed !== null) { $sets[] = 'seed = ?'; $params[] = json_encode($seed); }
    $params[] = $id;

    $db->prepare("UPDATE tournaments SET " . implode(', ', $sets) . " WHERE id = ?")
       ->execute($params);

    jsonResponse(['ok' => true]);
}

// ── DELETE (creator only, upcoming/active; admin can delete anything) ─────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'delete') {
    $id       = (int)($reqBody['id'] ?? 0);
    $nickname = trim($reqBody['nickname'] ?? '');
    $isAdmin  = isAdminRequest();

    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $stmt = $db->prepare("SELECT * FROM tournaments WHERE id = ?");
    $stmt->execute([$id]);
    $t = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$t) jsonResponse(['error' => 'Not found'], 404);

    if ($isAdmin) {
        // Admin can delete anything
    } elseif (($reqBody['secret'] ?? '') === API_SECRET && $nickname) {
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
    if (!isAdminRequest()) jsonResponse(['error' => 'Unauthorized'], 401);

    $tournament_id = (int)($reqBody['tournament_id'] ?? 0);
    $nickname      = trim($reqBody['nickname'] ?? '');
    if (!$tournament_id || !$nickname) jsonResponse(['error' => 'Missing params'], 400);

    $stmt = $db->prepare("DELETE FROM tournament_entries WHERE tournament_id = ? AND nickname = ?");
    $stmt->execute([$tournament_id, $nickname]);

    jsonResponse(['ok' => true, 'deleted' => $stmt->rowCount()]);
}

// ── ENTRIES (admin only) — list all entries for a tournament ─────────────────
if ($action === 'entries') {
    if (!isAdminRequest()) jsonResponse(['error' => 'Unauthorized'], 401);

    $id = (int)($_GET['id'] ?? ($body['id'] ?? 0));
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    try {
        $stmt = $db->prepare("
            SELECT nickname, score, guesses, seconds, seed_issued_at, submitted_at
            FROM tournament_entries
            WHERE tournament_id = ?
            ORDER BY score DESC, guesses ASC, seconds ASC
        ");
        $stmt->execute([$id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (\Exception $e) {
        jsonResponse(['error' => 'DB error: ' . $e->getMessage()], 500);
    }

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

// ── REACTIONS (tournament-level) ─────────────────────────────────────────────
$ALLOWED_REACTIONS = ['fire', 'muscle', 'sweat', 'mindblown', 'party'];

if ($action === 'reactions') {
    $id       = (int)($_GET['id'] ?? 0);
    $nickname = trim($_GET['nickname'] ?? '');
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $stmt = $db->prepare("SELECT reaction, COUNT(*) as cnt FROM tournament_reactions WHERE tournament_id = ? GROUP BY reaction");
    $stmt->execute([$id]);
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[$row['reaction']] = (int)$row['cnt'];
    }

    $myReaction = null;
    if ($nickname) {
        $s = $db->prepare("SELECT reaction FROM tournament_reactions WHERE tournament_id = ? AND nickname = ?");
        $s->execute([$id, $nickname]);
        $row = $s->fetch(PDO::FETCH_ASSOC);
        if ($row) $myReaction = $row['reaction'];
    }

    jsonResponse(['counts' => $counts, 'my_reaction' => $myReaction]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'react') {
    global $ALLOWED_REACTIONS;
    $id       = (int)($reqBody['tournament_id'] ?? 0);
    $nickname = trim($reqBody['nickname'] ?? '');
    $reaction = trim($reqBody['reaction'] ?? '');
    $secret   = $reqBody['secret'] ?? '';

    if ($secret !== API_SECRET)              jsonResponse(['error' => 'Unauthorized'], 401);
    if (!$id || !$nickname || !$reaction)    jsonResponse(['error' => 'Missing params'], 400);
    if (strlen($nickname) > 20)              jsonResponse(['error' => 'Invalid nickname'], 400);
    if (!in_array($reaction, $ALLOWED_REACTIONS)) jsonResponse(['error' => 'Invalid reaction'], 400);

    $db->prepare("INSERT INTO tournament_reactions (tournament_id, nickname, reaction) VALUES (?, ?, ?)
                  ON CONFLICT(tournament_id, nickname) DO UPDATE SET reaction = excluded.reaction, created_at = strftime('%s','now')")
       ->execute([$id, $nickname, $reaction]);

    jsonResponse(['ok' => true]);
}

// ── PLAYER REACTIONS ─────────────────────────────────────────────────────────
$ALLOWED_EMOJIS = ['clap', 'fire', 'handshake'];

if ($action === 'player_reactions') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) jsonResponse(['error' => 'Missing id'], 400);

    $stmt = $db->prepare("SELECT from_nickname, to_nickname, emoji FROM player_reactions WHERE tournament_id = ?");
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(['reactions' => $rows]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'player_react') {
    global $ALLOWED_EMOJIS;
    $id            = (int)($reqBody['tournament_id'] ?? 0);
    $fromNickname  = trim($reqBody['from_nickname'] ?? '');
    $toNickname    = trim($reqBody['to_nickname'] ?? '');
    $emoji         = trim($reqBody['emoji'] ?? '');
    $secret        = $reqBody['secret'] ?? '';

    if ($secret !== API_SECRET)              jsonResponse(['error' => 'Unauthorized'], 401);
    if (!$id || !$fromNickname || !$toNickname || !$emoji) jsonResponse(['error' => 'Missing params'], 400);
    if (strlen($fromNickname) > 20 || strlen($toNickname) > 20) jsonResponse(['error' => 'Invalid nickname'], 400);
    if ($fromNickname === $toNickname)       jsonResponse(['error' => 'Cannot react to yourself'], 400);
    if (!in_array($emoji, $ALLOWED_EMOJIS)) jsonResponse(['error' => 'Invalid emoji'], 400);

    $db->prepare("INSERT INTO player_reactions (tournament_id, from_nickname, to_nickname, emoji) VALUES (?, ?, ?, ?)
                  ON CONFLICT(tournament_id, from_nickname, to_nickname) DO UPDATE SET emoji = excluded.emoji, created_at = strftime('%s','now')")
       ->execute([$id, $fromNickname, $toNickname, $emoji]);

    jsonResponse(['ok' => true]);
}

jsonResponse(['error' => 'Unknown action'], 400);
