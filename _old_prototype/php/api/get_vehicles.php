<?php
header('Content-Type: application/json');
require_once '../config/database.php';

try {
    $stmt = $pdo->query("SELECT * FROM vehicles WHERE status = 'active'");
    $vehicles = $stmt->fetchAll();
    echo json_encode(['success' => true, 'vehicles' => $vehicles]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Error fetching vehicles']);
}
?>
