<?php
require __DIR__.'/../backend/config/HomeServiceRequest.php';
$now=new DateTimeImmutable('2026-10-20 12:00:00',new DateTimeZone('Asia/Manila'));
foreach ([['2030-11-10','Service in Progress',false],['2026-10-19','Confirmed',false],['2026-10-19','Service in Progress',true],['2026-10-19','Completed',true]] as [$date,$status,$expected]) {
    $passed=true;
    try { HomeServiceRequest::validateCompletion(['preferred_date'=>$date,'preferred_time'=>'10:00:00','status'=>$status],$now); }
    catch(RuntimeException $e) { $passed=false; }
    if($passed!==$expected) throw new RuntimeException('Incorrect completion validation');
    echo "PASS completion: $date, $status\n";
}
