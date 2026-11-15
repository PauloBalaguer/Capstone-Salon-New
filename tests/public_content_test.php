<?php
require __DIR__ . '/support/harness.php';
$pdo=clone_database('capstone_public_content_test');
$get=fn($file,$env=[])=>run_endpoint(['script'=>'backend/public/'.$file.'.php','method'=>'GET','env'=>$env]);
$r=$get('getSiteStats');
check('public counts are numbers, with branch hours from the server', ($r['success']??false) && is_int($r['services']) && is_int($r['professionals']) && isset($r['branchHours']['daraga']['open'],$r['date']));
$r=$get('getBookingPolicy',['SALON_LATE_GRACE_MINUTES'=>'17']);
check('late-arrival policy uses configured minutes', ($r['lateGraceMinutes']??0)===17 && str_contains($r['lateArrivalRule']??'', '17'));
$r=$get('getBookingPolicy',['SALON_LATE_GRACE_MINUTES'=>'']);
check('unset grace period never invents a time limit', ($r['lateGraceMinutes']??null)===null && ($r['lateArrivalRule']??null)===null);
$html=file_get_contents(__DIR__.'/../index.html');
check('home page has readable fallbacks before scripts load', !str_contains($html,'Checking hours') && str_contains($html,'Call to confirm hours') && str_contains($html,'data-stat-fallback'));
check('before/after image IDs and alt text agree', (bool)preg_match('/id="baBefore"\s+src="[^"]*Before-rebond.jpg"\s+alt="Before the service"/',$html)
    && (bool)preg_match('/id="baAfter"\s+src="[^"]*after-rebond.jpg"\s+alt="After the service"/',$html));
$r=$get('getLiveConfig');
check('missing optional live-update configuration is a valid disabled response', ($r['success']??false) && ($r['enabled']??true)===false);
finish();
