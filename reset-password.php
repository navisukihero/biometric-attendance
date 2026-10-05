<?php
declare(strict_types=1);

require __DIR__ . '/config/database.php';
require __DIR__ . '/includes/functions.php';

// Password changes now require the staged username/email and old-password
// verification flow. Retired reset-token links cannot bypass that check.
redirect('forgot-password.php');
