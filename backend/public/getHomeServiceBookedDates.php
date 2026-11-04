<?php
/**
 * Public: dates in the next HomeServiceRequest::BOOKED_DATES_DAYS days that
 * are fully booked for the chosen event type + wedding package / services.
 * Used by the guest and customer event forms to mark dates before submitting.
 */
require_once __DIR__.'/../../backend/shared/config/setup/database.php';
require_once __DIR__.'/../../backend/shared/config/booking/HomeServiceRequest.php';
header('Content-Type: application/json');
try {
    $event = $_GET['eventType'] ?? '';
    $package = $_GET['weddingPackage'] ?? '';
    $services = $_GET['services'] ?? [];
    if (!is_string($event) || !is_string($package) || !is_array($services) || count($services)>5
        || array_filter($services,fn($s)=>!is_string($s)) || trim($event) === '') throw new InvalidArgumentException('Please choose a valid event and services.');
    if ($event === 'Wedding' ? !isset(HomeServiceRequest::PACKAGES[$package]) : (!$services || array_diff($services, HomeServiceRequest::SERVICES))) {
        throw new InvalidArgumentException('Please choose a wedding package or the services you need.');
    }
    $today = (new DateTimeImmutable('now', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
    $dates = HomeServiceRequest::fullyBookedDates(Database::getInstance(), ['event_type'=>$event,'wedding_package'=>$package,'services'=>$services], $today);
    echo json_encode(['success'=>true,'from'=>$today,'days'=>HomeServiceRequest::BOOKED_DATES_DAYS,'fullyBooked'=>$dates]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['success'=>false,'message'=>'Booked dates could not be loaded.']);
}
