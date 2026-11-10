<?php
// Role routing lives in login.php (it already redirects signed-in users).
// Keeping this file config-free avoids a second full boot on the first visit.
header('Location: login.php');
exit;
