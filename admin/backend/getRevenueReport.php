<?php
require_once __DIR__.'/../../shared/backend/config/session.php';
require_once __DIR__.'/../../shared/backend/config/database.php';
require_once __DIR__.'/../../shared/backend/config/RevenueReport.php';
header('Content-Type: application/json');
if (!isLoggedIn() || ($_SESSION['user_role']??'')!=='Admin') {
    http_response_code(401);echo json_encode(['success'=>false,'message'=>'Please log in as an administrator.']);exit;
}
try {
    echo json_encode(['success'=>true,'collections'=>RevenueReport::rows(Database::getInstance(),$_GET)]);
} catch(InvalidArgumentException $e) {
    http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch(PDOException $e) {
    error_log('Revenue report error: '.$e->getMessage());http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Could not load collected payments. Please retry.']);
}
