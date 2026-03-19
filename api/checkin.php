<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed']);
    exit;
}

requireApiKey();
checkRateLimit();

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$note = isset($body['note']) ? substr(trim($body['note']), 0, 255) : null;

$result = checkin($note);
http_response_code($result['success'] ? 200 : 409);
echo json_encode($result);
