<?php
// Liveness probe for Render. Deliberately does not load config.php, so a
// brief database or R2 hiccup does not make Render restart the container.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo 'ok';
