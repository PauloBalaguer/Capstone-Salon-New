<?php
require_once __DIR__ . '/../../backend/config/HomeServiceRequest.php';
function checkHomeService(bool $result, string $label): void {
    if (!$result) throw new RuntimeException($label);
    echo "PASS: $label\n";
}
$monday = (new DateTimeImmutable('next monday', new DateTimeZone('Asia/Manila')))->format('Y-m-d');
foreach (['A', 'B', 'C', 'D'] as $package) {
    checkHomeService(HomeServiceRequest::validate($monday, '10:00', 'Wedding', $package, '3', []) === null, "Package $package accepts a future Monday for review");
    $details = HomeServiceRequest::details('Wedding', $package, '3', [], '', '');
    if ($package === 'D') {
        checkHomeService(str_contains($details, 'Reservation Fee: ₱2,000') && str_contains($details, 'Remaining Balance: ₱10,000'), 'D fee and remaining balance');
    } else {
        checkHomeService(str_contains($details, 'Reservation Fee: To be confirmed after review.') && !str_contains($details, 'Reservation Fee: ₱2,000'), "$package has no assumed reservation fee");
    }
}
checkHomeService(HomeServiceRequest::validate('2000-01-01', '10:00', 'Wedding', 'A', '3', []) !== null, 'Past schedule rejected');
checkHomeService(HomeServiceRequest::validate('2099-02-30', '10:00', 'Wedding', 'A', '3', []) !== null, 'Invalid date rejected');
checkHomeService(HomeServiceRequest::validate($monday, '25:00', 'Wedding', 'A', '3', []) !== null, 'Invalid time rejected');
checkHomeService(HomeServiceRequest::validate($monday, '10:00', 'Wedding', 'X', '3', []) !== null, 'Invalid package rejected');
checkHomeService(HomeServiceRequest::validate($monday, '10:00', 'Debut', '', '0', ['Hair Styling']) !== null, 'Invalid client count rejected');
checkHomeService(HomeServiceRequest::validate($monday, '10:00', 'Debut', '', '3', []) !== null, 'Non-wedding service selection required');
checkHomeService(HomeServiceRequest::validate($monday, '10:00', 'Debut', '', '3', ['Hair Styling']) === null, 'Valid non-wedding request accepted');
checkHomeService(!str_contains(HomeServiceRequest::details('Debut', 'D', '3', ['Hair Styling'], '', ''), 'Reservation Fee'), 'Non-wedding ignores stale wedding selection');
