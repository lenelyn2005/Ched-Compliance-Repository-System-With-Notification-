#!/bin/sh
# Replaces cron / Windows Task Scheduler: drain the email queue periodically.
INTERVAL="${EMAIL_QUEUE_INTERVAL:-30}"
while true; do
    php /var/www/html/process_email_queue.php > /dev/null || true
    sleep "$INTERVAL"
done
