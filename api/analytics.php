<?php
require_once __DIR__ . '/db.php';

corsHeaders();

$adminKey = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
if (!$adminKey || $adminKey !== ADMIN_SECRET) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$db = getDb();

// Total scores
$total = $db->query("SELECT COUNT(*) FROM scores")->fetchColumn();

// Platform breakdown
$platforms = $db->query("
    SELECT
        CASE WHEN platform IS NULL OR platform = '' THEN 'unknown' ELSE platform END as platform,
        COUNT(*) as cnt
    FROM scores GROUP BY platform ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Country top 15
$countries = $db->query("
    SELECT
        CASE WHEN country IS NULL OR country = '' THEN 'unknown' ELSE country END as country,
        COUNT(*) as cnt
    FROM scores GROUP BY country ORDER BY cnt DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Difficulty breakdown
$difficulties = $db->query("
    SELECT difficulty, COUNT(*) as cnt
    FROM scores GROUP BY difficulty ORDER BY cnt DESC
")->fetchAll(PDO::FETCH_ASSOC);

// App version top 10
$versions = $db->query("
    SELECT
        CASE WHEN app_version IS NULL OR app_version = '' THEN 'unknown' ELSE app_version END as app_version,
        COUNT(*) as cnt
    FROM scores GROUP BY app_version ORDER BY cnt DESC LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// App language top 15
$languages = $db->query("
    SELECT
        CASE WHEN app_lang IS NULL OR app_lang = '' THEN 'unknown' ELSE app_lang END as app_lang,
        COUNT(*) as cnt
    FROM scores GROUP BY app_lang ORDER BY cnt DESC LIMIT 15
")->fetchAll(PDO::FETCH_ASSOC);

// Timed vs classic
$modes = $db->query("
    SELECT
        CASE WHEN timed = 1 THEN 'timed' ELSE 'classic' END as mode,
        COUNT(*) as cnt
    FROM scores GROUP BY timed
")->fetchAll(PDO::FETCH_ASSOC);

// Scores per day — last 30 days
$daily = $db->query("
    SELECT date(created_at) as day, COUNT(*) as cnt
    FROM scores
    WHERE created_at >= date('now', '-30 days')
    GROUP BY day ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

// Unique nicknames
$uniqueNicks = $db->query("SELECT COUNT(DISTINCT nickname) FROM scores")->fetchColumn();

// Average score per difficulty
$avgScores = $db->query("
    SELECT difficulty, ROUND(AVG(score)) as avg_score, MAX(score) as max_score
    FROM scores GROUP BY difficulty ORDER BY avg_score DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Cumulative unique players per day (all time)
$cumulativePlayers = $db->query("
    SELECT day, SUM(new_nicks) OVER (ORDER BY day) as total
    FROM (
        SELECT date(MIN(created_at)) as day, nickname, 1 as new_nicks
        FROM scores GROUP BY nickname
    ) GROUP BY day ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

// Daily scores by platform (last 60 days)
$dailyByPlatform = $db->query("
    SELECT
        date(created_at) as day,
        CASE WHEN platform IS NULL OR platform = '' THEN 'unknown' ELSE platform END as platform,
        COUNT(*) as cnt
    FROM scores
    WHERE created_at >= date('now', '-60 days')
    GROUP BY day, platform ORDER BY day
")->fetchAll(PDO::FETCH_ASSOC);

// Difficulty over time — weekly counts (last 90 days)
$diffByWeek = $db->query("
    SELECT
        strftime('%Y-W%W', created_at) as week,
        difficulty,
        COUNT(*) as cnt
    FROM scores
    WHERE created_at >= date('now', '-90 days')
    GROUP BY week, difficulty ORDER BY week
")->fetchAll(PDO::FETCH_ASSOC);

jsonResponse([
    'total'               => (int)$total,
    'unique_nicks'        => (int)$uniqueNicks,
    'platforms'           => $platforms,
    'countries'           => $countries,
    'difficulties'        => $difficulties,
    'versions'            => $versions,
    'languages'           => $languages,
    'modes'               => $modes,
    'daily'               => $daily,
    'avg_scores'          => $avgScores,
    'cumulative_players'  => $cumulativePlayers,
    'daily_by_platform'   => $dailyByPlatform,
    'diff_by_week'        => $diffByWeek,
]);
