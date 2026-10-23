Run sendAppointmentReminders.php every minute with the existing scheduler. It now also queues one payment reminder during the last ten minutes of an unpaid PayMongo booking's 30-minute hold. sendPaymentReminders.php can alternatively run independently every minute. Run the existing email outbox worker for delivery. Both workers use the existing account preferences and notification deduplication; stale payment reminder emails are suppressed after payment, cancellation or expiry. No SMS is sent.

Windows command:

    C:\xampp\php\php.exe C:\xampp\htdocs\Capstone-Salon\backend\cron\sendAppointmentReminders.php
