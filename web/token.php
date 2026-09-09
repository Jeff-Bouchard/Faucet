<?php
// Retire the former direct payout handler; every withdrawal uses api.php.
header('Cache-Control: no-store');
header('Location: ./#cashout', true, 303);
exit;
