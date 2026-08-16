<?php
/**
 * Tournament Bot — automatically creates tournaments when fewer than MIN_ACTIVE are open.
 * Called by GitHub Actions scheduled workflow every 6 hours.
 * Authentication: X-Admin-Key header (must match ADMIN_SECRET from config.local.php).
 */

require_once __DIR__ . '/db.php';

// ── Auth ──────────────────────────────────────────────────────────────────────
$headers = getallheaders();
$key = $headers['X-Admin-Key'] ?? $headers['x-admin-key'] ?? '';
if ($key !== ADMIN_SECRET) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// ── Config ────────────────────────────────────────────────────────────────────
const MIN_ACTIVE   = 3;
const DURATION_H   = 72;   // 3 days per tournament
const BOT_NICKNAME = 'ColegioCup';

// Tournament templates: [name, difficulty, game_mode, allow_repetition]
// Rotated based on the week number so there's variety
$templates = [
    ['Easy Open Challenge',      'easy',    'classic', 0],
    ['Medium Speed Run',         'medium',  'timed',   0],
    ['Easy Blitz',               'easy',    'timed',   0],
    ['Medium Precision Cup',     'medium',  'classic', 0],
    ['Monday Classic Cup',       'classic', 'classic', 0],
    ['Easy Weekend Cup',         'easy',    'classic', 0],
    ['Sprint Medium',            'medium',  'timed',   0],
    ['Classic Weekend Open',     'classic', 'classic', 0],
    ['Wednesday Easy Cup',       'easy',    'classic', 0],
    ['Medium Masters Cup',       'medium',  'classic', 0],
    ['Classic Precision Duel',   'classic', 'classic', 0],
    ['Hard Brain Workout',       'hard',    'classic', 0],
];

// ── Check active + upcoming count ─────────────────────────────────────────────
$db = getDb();
$db->exec("PRAGMA journal_mode=WAL;");

// Ensure table exists
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
    )
");

$count = (int)$db->query("
    SELECT COUNT(*) FROM tournaments
    WHERE ends_at > strftime('%s','now')
")->fetchColumn();

$needed  = max(0, MIN_ACTIVE - $count);
$created = [];

// Check if there's already a free (easy/medium) tournament active
$hasFree = (int)$db->query("
    SELECT COUNT(*) FROM tournaments
    WHERE ends_at > strftime('%s','now')
      AND difficulty IN ('easy','medium')
")->fetchColumn() > 0;

if ($needed > 0) {
    // Determine colors based on difficulty (same logic as tournament.php)
    function randomSeedBot(string $difficulty, int $allowRepetition): array {
        $pool    = in_array($difficulty, ['classic', 'hard'])
            ? ['orange','skyBlue','green','yellow','blue','pink']
            : ['orange','skyBlue','green','yellow'];
        $codeLen = $difficulty === 'hard' ? 5 : 4;
        $result  = [];
        for ($i = 0; $i < $codeLen; $i++) {
            if ($allowRepetition) {
                $result[] = $pool[array_rand($pool)];
            } else {
                $remaining = array_values(array_diff($pool, $result));
                $result[]  = $remaining[array_rand($remaining)];
            }
        }
        return $result;
    }

    $stmt = $db->prepare("
        INSERT INTO tournaments
            (name, difficulty, game_mode, allow_repetition, seed, starts_at, ends_at, creator_nickname)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Free templates for guaranteed easy/medium slot
    $freeTemplates = [
        ['Easy Open Challenge',  'easy',   'classic', 0],
        ['Easy Blitz',           'easy',   'timed',   0],
        ['Easy Weekend Cup',     'easy',   'classic', 0],
        ['Medium Speed Run',     'medium', 'timed',   0],
        ['Medium Precision Cup', 'medium', 'classic', 0],
        ['Sprint Medium',        'medium', 'timed',   0],
    ];

    $week = (int)date('W');
    for ($i = 0; $i < $needed; $i++) {
        // First slot: force free difficulty if none active yet
        if ($i === 0 && !$hasFree) {
            $tpl = $freeTemplates[$week % count($freeTemplates)];
        } else {
            $tpl = $templates[($week + $count + $i) % count($templates)];
        }
        [$name, $diff, $mode, $rep] = $tpl;
        $now  = time();
        $seed = randomSeedBot($diff, $rep);

        $stmt->execute([
            $name, $diff, $mode, $rep,
            json_encode($seed),
            $now,
            $now + DURATION_H * 3600,
            BOT_NICKNAME,
        ]);

        $created[] = [
            'id'         => (int)$db->lastInsertId(),
            'name'       => $name,
            'difficulty' => $diff,
            'game_mode'  => $mode,
        ];
    }
}

header('Content-Type: application/json');
echo json_encode([
    'ok'               => true,
    'active_before'    => $count,
    'created'          => count($created),
    'tournaments'      => $created,
], JSON_PRETTY_PRINT);
