<?php
require_once __DIR__ . '/db.php';

corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'POST required'], 405);
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$message  = trim($body['message']  ?? '');
$category = trim($body['category'] ?? 'general');
$name     = trim($body['name']     ?? '');
$email    = trim($body['email']    ?? '');
$platform = trim($body['platform'] ?? '');
$appLang  = trim($body['lang']     ?? '');

if (strlen($message) < 3) {
    jsonResponse(['error' => 'Message too short'], 422);
}
if (strlen($message) > 2000) {
    jsonResponse(['error' => 'Message too long'], 422);
}
$allowed = ['bug', 'idea', 'game', 'praise', 'general'];
if (!in_array($category, $allowed)) $category = 'general';

if ($name !== '' && strlen($name) > 100) {
    jsonResponse(['error' => 'Name too long'], 422);
}
$allowed_platforms = ['ios', 'android'];
if ($platform !== '' && !in_array($platform, $allowed_platforms)) $platform = '';
if (strlen($appLang) > 10) $appLang = substr($appLang, 0, 10);

if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Invalid email'], 422);
}

$db = getFeedbackDb();
$stmt = $db->prepare("
    INSERT INTO feedback (category, message, name, email, platform, app_lang)
    VALUES (?, ?, ?, ?, ?, ?)
");
$stmt->execute([$category, $message, $name ?: null, $email ?: null, $platform ?: null, $appLang ?: null]);

// E-mail notifikace
$to      = 'petr.vitek70@gmail.com';
$subject = "[Mastermind feedback] $category" . ($name ? " od $name" : '');
$body_mail = "Kategorie: $category\n";
if ($name)     $body_mail .= "Jméno: $name\n";
if ($email)    $body_mail .= "E-mail: $email\n";
if ($platform) $body_mail .= "Platforma: $platform\n";
if ($appLang)  $body_mail .= "Jazyk: $appLang\n";
$body_mail .= "\n$message";
@mail($to, $subject, $body_mail, "From: noreply@colegio.cz\r\nContent-Type: text/plain; charset=utf-8");

jsonResponse(['status' => 'ok']);
