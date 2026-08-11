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

// Limity podle obtížnosti: [maxGuesses, scoreMultiplier, timedMultiplier]
$DIFFICULTY_LIMITS = [
    'easy'    => ['maxGuesses' => 12, 'scoreMultiplier' => 1],
    'medium'  => ['maxGuesses' => 10, 'scoreMultiplier' => 3],
    'classic' => ['maxGuesses' => 10, 'scoreMultiplier' => 4],
    'hard'    => ['maxGuesses' =>  8, 'scoreMultiplier' => 6],
];

// Přepočet skóre podle stejného algoritmu jako GameLogic (iOS + Android)
function computeScore(int $guesses, int $maxGuesses, int $seconds, bool $isTimed, int $scoreMultiplier): int {
    $guessBonus = match(true) {
        $guesses === 1 => 5000,
        $guesses === 2 => 3000,
        default        => ($maxGuesses - $guesses) * 500,
    };
    $timePenalty    = $isTimed ? 0 : $seconds * 5;
    $modeMultiplier = $isTimed ? 2 : 1;
    return max(0, ($guessBonus - $timePenalty) * $scoreMultiplier * $modeMultiplier);
}

// Minimální reálný čas: každý pokus trvá aspoň 5 sekund
function minRealisticSeconds(int $guesses): int {
    return $guesses * 5;
}

// Validace vstupů
$nickname   = trim($body['nickname'] ?? '');
$score      = (int)($body['score'] ?? 0);
$difficulty = $body['difficulty'] ?? '';
$guesses    = (int)($body['guesses'] ?? 0);
$seconds    = (int)($body['seconds'] ?? 0);

if (strlen($nickname) < 1 || strlen($nickname) > 20) {
    jsonResponse(['error' => 'Invalid nickname (1–20 chars)'], 422);
}
if (!preg_match('/^[\p{L}0-9 _\-\.]+$/u', $nickname)) {
    jsonResponse(['error' => 'Invalid nickname characters'], 422);
}
if (!array_key_exists($difficulty, $DIFFICULTY_LIMITS)) {
    jsonResponse(['error' => 'Invalid difficulty'], 422);
}

$limits      = $DIFFICULTY_LIMITS[$difficulty];
$isTimed     = ($body['timed']      ?? 0) == 1;
$repetition  = ($body['repetition'] ?? 0) == 1;
$platform    = in_array($body['platform'] ?? '', ['ios', 'android']) ? $body['platform'] : '';
$appVersion  = substr(preg_replace('/[^0-9a-zA-Z.\-]/', '', $body['app_version'] ?? ''), 0, 20);
$appLang     = substr(preg_replace('/[^a-zA-Z\-]/', '', $body['app_lang'] ?? ''), 0, 10);

// Země ze IP adresy (server-side, bez závislosti na klientovi)
function getCountryFromIp(string $ip): string {
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return '';
    }
    $response = @file_get_contents("http://ip-api.com/json/{$ip}?fields=countryCode", false,
        stream_context_create(['http' => ['timeout' => 2]]));
    if (!$response) return '';
    $data = json_decode($response, true);
    return $data['countryCode'] ?? '';
}
$ip      = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
$ip      = trim(explode(',', $ip)[0]);
$country = getCountryFromIp($ip);

if ($guesses < 2 || $guesses > $limits['maxGuesses']) {
    jsonResponse(['error' => 'First-guess wins are not eligible for leaderboard'], 422);
}
if ($seconds < 0 || $seconds > 86400) {
    jsonResponse(['error' => 'Invalid seconds'], 422);
}
if ($seconds < minRealisticSeconds($guesses)) {
    jsonResponse(['error' => 'Suspiciously fast time'], 422);
}

// Přepočet skóre — musí přesně odpovídat algoritmu hry
$scoreMultiplier = $limits['scoreMultiplier'] * ($repetition ? 2 : 1);
$expected = computeScore($guesses, $limits['maxGuesses'], $seconds, $isTimed, $scoreMultiplier);
if ($score !== $expected) {
    jsonResponse(['error' => 'Score does not match game parameters'], 422);
}

// Uložení skóre
$db = getDb();
$stmt = $db->prepare(
    'INSERT INTO scores (nickname, score, difficulty, guesses, seconds, timed, repetition, platform, app_version, app_lang, country)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$stmt->execute([$nickname, $score, $difficulty, $guesses, $seconds,
                $isTimed ? 1 : 0, $repetition ? 1 : 0,
                $platform, $appVersion, $appLang, $country]);

jsonResponse(['success' => true, 'id' => $db->lastInsertId()], 201);
