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

function computeExpectedScore(int $guesses, int $maxGuesses, int $ms, bool $isTimed, int $scoreMultiplier): int {
    $guessBonus = match(true) {
        $guesses === 1 => 5000,
        $guesses === 2 => 3000,
        default        => ($maxGuesses - $guesses) * 500,
    };
    $timePenalty    = $isTimed ? 0 : (int)floor($ms * 0.005);
    $modeMultiplier = $isTimed ? 2 : 1;
    return max(0, ($guessBonus - $timePenalty) * $scoreMultiplier * $modeMultiplier);
}

$DIFFICULTY_LIMITS = [
    'easy'    => ['maxGuesses' => 12, 'scoreMultiplier' => 1],
    'medium'  => ['maxGuesses' => 10, 'scoreMultiplier' => 3],
    'classic' => ['maxGuesses' => 10, 'scoreMultiplier' => 4],
    'hard'    => ['maxGuesses' =>  8, 'scoreMaxGuesses' => 10, 'scoreMultiplier' => 6],
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
        $ms      = (int)($row['ms'] ?? 0) ?: (int)$row['seconds'] * 1000;
        $seconds = (int)round($ms / 1000);
        $score   = (int)$row['score'];
        $isTimed    = (int)($row['timed']      ?? 0) === 1;
        $repetition = (int)($row['repetition'] ?? 0) === 1;
        $maxG    = $limits['scoreMaxGuesses'] ?? $limits['maxGuesses'];
        $mult    = $limits['scoreMultiplier'] * ($repetition ? 2 : 1);

        $reasons = [];

        // Pravidlo 1: guesses=1 zakázáno
        if ($guesses === 1) {
            $reasons[] = 'guesses=1';
        }

        // Pravidlo 2: příliš rychlý čas
        if ($ms < $guesses * 5000) {
            $reasons[] = "ms($ms) < guesses*5000(" . ($guesses * 5000) . ")";
        }

        // Pravidlo 3: skóre neodpovídá uloženému módu
        if (empty($reasons)) {
            $expected = computeExpectedScore($guesses, $maxG, $ms, $isTimed, $mult);
            // Přechod v1.2.5: Hard záznamy ze starých appek (maxGuesses=8) nemazat — odstranit po recalc_hard
            $expectedLegacy = computeExpectedScore($guesses, $limits['maxGuesses'], $ms, $isTimed, $mult);
            if ($score !== $expected && $score !== $expectedLegacy) {
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


// Jednorázový přepočet Hard skóre na nový vzorec (scoreMaxGuesses=10)
if ($action === 'recalc_hard') {
    $rows = $db->query("SELECT * FROM scores WHERE difficulty='hard'")->fetchAll(PDO::FETCH_ASSOC);
    $updated = 0;
    foreach ($rows as $row) {
        $guesses    = (int)$row['guesses'];
        $ms         = (int)($row['ms'] ?? 0) ?: (int)$row['seconds'] * 1000;
        $isTimed    = (int)($row['timed']      ?? 0) === 1;
        $repetition = (int)($row['repetition'] ?? 0) === 1;
        $mult       = 6 * ($repetition ? 2 : 1);
        $newScore   = computeExpectedScore($guesses, 10, $ms, $isTimed, $mult);
        if ($newScore !== (int)$row['score']) {
            $db->prepare("UPDATE scores SET score=? WHERE id=?")->execute([$newScore, $row['id']]);
            $updated++;
        }
    }
    jsonResponse(['success' => true, 'recalculated' => $updated, 'total_hard' => count($rows)]);
}

// Jednorázová obnova Hard záznamů smazaných auditem #98 (9. 11. 2026); dry_run=true jen ukáže náhled
if ($action === 'restore_hard') {
    $rows   = $body['rows'] ?? [];
    $dryRun = ($body['dry_run'] ?? true) !== false;
    if (!is_array($rows) || !$rows) jsonResponse(['error' => 'No rows'], 422);

    $exists   = $db->prepare('SELECT 1 FROM scores WHERE id=?');
    $profile  = $db->prepare("SELECT platform, app_version, app_lang, country FROM scores
                              WHERE nickname=? AND platform<>'' ORDER BY ABS(id-?) LIMIT 1");
    $before   = $db->prepare('SELECT created_at FROM scores WHERE id<? ORDER BY id DESC LIMIT 1');
    $after    = $db->prepare('SELECT created_at FROM scores WHERE id>? ORDER BY id ASC LIMIT 1');
    $insert   = $db->prepare('INSERT INTO scores (id, nickname, score, difficulty, guesses, seconds, ms, timed, repetition,
                              platform, app_version, app_lang, country, created_at)
                              VALUES (?, ?, ?, \'hard\', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $out = ['restored' => 0, 'skipped_existing' => 0, 'with_profile' => 0, 'preview' => []];
    if (!$dryRun) $db->beginTransaction();
    foreach ($rows as [$id, $nick, $oldScore, $g, $sec, $timed, $rep]) {
        $exists->execute([$id]);
        if ($exists->fetchColumn()) { $out['skipped_existing']++; continue; }

        $mult = 6 * ($rep ? 2 : 1);
        $mode = $timed ? 2 : 1;
        // Přesná penalizace se dá dopočítat z původního skóre (starý vzorec maxGuesses=8)
        $oldBonus = $g === 1 ? 5000 : ($g === 2 ? 3000 : (8 - $g) * 500);
        if (!$timed && $oldScore > 0) {
            $pen = $oldBonus - intdiv($oldScore, $mult);
            $ms  = $pen * 200 + 100;
        } else {
            $ms  = $sec * 1000;
        }
        $newScore = computeExpectedScore($g, 10, $ms, (bool)$timed, $mult);

        $profile->execute([$nick, $id]);
        $p = $profile->fetch(PDO::FETCH_ASSOC) ?: ['platform' => '', 'app_version' => '', 'app_lang' => '', 'country' => ''];
        if ($p['platform'] !== '') $out['with_profile']++;
        $before->execute([$id]); $date = $before->fetchColumn();
        if (!$date) { $after->execute([$id]); $date = $after->fetchColumn(); }

        $rec = [$id, $nick, $newScore, $g, $sec, $ms, $timed, $rep, $p['platform'], $p['app_version'], $p['app_lang'], $p['country'], $date];
        if (count($out['preview']) < 15) $out['preview'][] = $rec;
        if (!$dryRun) $insert->execute($rec);
        $out['restored']++;
    }
    if (!$dryRun) $db->commit();
    $out['dry_run'] = $dryRun;
    jsonResponse(['success' => true] + $out);
}

jsonResponse(['error' => 'Unknown action'], 422);
