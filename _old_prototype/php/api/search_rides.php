<?php
header('Content-Type: application/json');
require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if (strpos($contentType, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);
} else {
    $data = $_POST;
}

$origin = trim($data['origin'] ?? '');
$destination = trim($data['destination'] ?? '');
$date = $data['date'] ?? '';

if (empty($origin) || empty($destination) || empty($date)) {
    echo json_encode(['success' => false, 'message' => 'Origin, destination and date are required']);
    exit;
}

$stmt = $pdo->prepare("
    SELECT r.*, u.name as driver_name 
    FROM rides r 
    JOIN users u ON r.driver_id = u.id 
    WHERE r.origin LIKE ? 
    AND r.destination LIKE ? 
    AND r.date = ? 
    AND r.seats_available > 0
    AND r.status = 'active'
    ORDER BY r.time ASC
");
$originParam = "%$origin%";
$destinationParam = "%$destination%";
$stmt->execute([$originParam, $destinationParam, $date]);
$rides = $stmt->fetchAll();

echo json_encode(['success' => true, 'rides' => $rides]);
?>
