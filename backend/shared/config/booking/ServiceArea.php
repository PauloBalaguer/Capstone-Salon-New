<?php
// User-confirmed scope: profile and service address forms focus on Albay only.
// Existing home-service terms also specify Albay. Keep policy separate from PSGC data.
$albay = ['050500000'];
return ['address_provinces' => $albay, 'home_service_provinces' => $albay];
