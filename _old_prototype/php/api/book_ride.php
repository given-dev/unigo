<?php
session_start();
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

$rideId = $data['ride_id'] ?? null;
$userId = $data['user_id'] ?? ($_SESSION['user_id'] ?? null);

if (!$rideId || !$userId) {
    echo json_encode(['success' => false, 'message' => 'Ride ID and user ID are required']);
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM rides WHERE id = ? AND seats_available > 0");
$stmt->execute([$rideId]);
$ride = $stmt->fetch();

if (!$ride) {
    echo json_encode(['success' => false, 'message' => 'Ride not found or no seats available']);
    exit;
}

try {
    $pdo->beginTransaction();
    
    $stmt = $pdo->prepare("UPDATE rides SET seats_available = seats_available - 1 WHERE id = ?");
    $stmt->execute([$rideId]);
    
    $stmt = $pdo->prepare("INSERT INTO bookings (ride_id, user_id, status) VALUES (?, ?, 'confirmed')");
    $stmt->execute([$rideId, $userId]);
    
    $pdo->commit();
    
    echo json_encode(['success' => true, 'message' => 'Ride booked successfully']);
} catch (Exception $e) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Booking failed']);
}
?>
