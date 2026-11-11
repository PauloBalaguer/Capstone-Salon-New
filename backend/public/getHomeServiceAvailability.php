<?php
require_once __DIR__.'/../../backend/shared/config/setup/database.php';
require_once __DIR__.'/../../backend/shared/config/booking/HomeServiceRequest.php';
header('Content-Type: application/json');
try {
    $date = $_GET['date'] ?? '';
    $time = $_GET['time'] ?? '';
    $event = $_GET['eventType'] ?? '';
    $package = $_GET['weddingPackage'] ?? '';
    $services = $_GET['services'] ?? [];
    if (!is_string($date) || !is_string($time) || !is_string($event) || !is_string($package)
        || !is_array($services) || count($services)>5 || array_filter($services,fn($s)=>!is_string($s))) throw new InvalidArgumentException('Please choose a valid event, services, date and time.');
    // Responsible branch (optional here so the form can check before one is
    // picked): its hours, holidays and home-service eligibility apply.
    $pdo = Database::getInstance();
    $branchId = (int) ($_GET['branchId'] ?? 0);
    if ($branchId <= 0 && is_string($_GET['branchKey'] ?? null) && $_GET['branchKey'] !== '') {
        $lookup = $pdo->prepare('SELECT id FROM branches WHERE branch_key = ? LIMIT 1');
        $lookup->execute([$_GET['branchKey']]);
        $branchId = (int) $lookup->fetchColumn();
    }
    $error = HomeServiceRequest::validate($date,$time,$event,$package,'1',$services,$pdo,$branchId ?: null);
    if ($error) throw new InvalidArgumentException($error);
    $result = HomeServiceRequest::availability($pdo,['event_type'=>$event,'wedding_package'=>$package,'services'=>$services,'preferred_date'=>$date,'preferred_time'=>$time,'branch_id'=>$branchId ?: null]);
    if ($result['available'] && $branchId <= 0) $result['message'] .= ' Choose the branch that will handle your visit to check its hours.';
    echo json_encode(['success'=>true]+$result);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Availability could not be checked. Please try again.']);
}
