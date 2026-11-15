<?php
require __DIR__ . '/support/harness.php';
// Presentation-only checks; financial calculations are exercised by cashier_cashless_test.php.
$page=file_get_contents(__DIR__.'/../frontend/cashier/dashboard.html');
$last=-1;$ordered=true;
foreach (['serviceChargesDisplay','reservationReceivedDisplay','remainingServiceDisplay','tipDisplay','cashReceivedInput','changeDueDisplay'] as $id) {
    $pos=strpos($page,'id="'.$id.'"');
    if ($pos===false || $pos<=$last) $ordered=false;
    $last=$pos;
}
check('checkout amounts follow the requested order', $ordered);
check('checkout labels identify service total, deposit and balance', str_contains($page,'>Service total<') && str_contains($page,'>Deposit paid<') && str_contains($page,'>Balance due<'));
$receipt=file_get_contents(__DIR__.'/../frontend/cashier/receipt.html');
check('thermal layout fits an 80mm roll with margins and hides controls', str_contains($receipt,'width: 74mm') && str_contains($receipt,'margin: 3mm') && str_contains($receipt,'.no-print { display: none !important; }'));
foreach (['dashboard','reports'] as $name) {
    $html=file_get_contents(__DIR__.'/../frontend/admin/'.$name.'.html');
    check($name.' collection bars label their axis and currency', str_contains($html,'Horizontal axis:') && str_contains($html,'(PHP)'));
}
finish();
