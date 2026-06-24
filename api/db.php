<?php
define('DB_PATH', __DIR__ . '/../data/scores.db');
define('API_SECRET', 'mm_colegio_2026_xK9pQ');

// ADMIN_SECRET se načítá z konfiguračního souboru mimo repozitář
$_cfg = __DIR__ . '/../config.local.php';
if (file_exists($_cfg)) require_once $_cfg;
if (!defined('ADMIN_SECRET')) define('ADMIN_SECRET', 'change-me');

function getDb(): PDO {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    $db = new PDO('sqlite:' . DB_PATH);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("
        CREATE TABLE IF NOT EXISTS scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            nickname TEXT NOT NULL,
            score INTEGER NOT NULL,
            difficulty TEXT NOT NULL,
            guesses INTEGER NOT NULL,
            seconds INTEGER NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        CREATE INDEX IF NOT EXISTS idx_score ON scores(score DESC);
        CREATE INDEX IF NOT EXISTS idx_difficulty ON scores(difficulty);
    ");
    return $db;
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function getFeedbackDb(): PDO {
    $path = __DIR__ . '/../data/feedback.db';
    $dir  = dirname($path);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $db = new PDO('sqlite:' . $path);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("
        CREATE TABLE IF NOT EXISTS feedback (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            category   TEXT    NOT NULL DEFAULT 'general',
            message    TEXT    NOT NULL,
            name       TEXT,
            email      TEXT,
            platform   TEXT,
            app_lang   TEXT,
            published  INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
    ");
    return $db;
}

function corsHeaders(): void {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}
