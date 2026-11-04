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
    $error = HomeServiceRequest::validate($date,$time,$event,$package,'1',$services);
    if ($error) throw new InvalidArgumentException($error);
    $result = HomeServiceRequest::availability(Database::getInstance(),['event_type'=>$event,'wedding_package'=>$package,'services'=>$services,'preferred_date'=>$date,'preferred_time'=>$time]);
    echo json_encode(['success'=>true]+$result);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Availability could not be checked. Please try again.']);
}
