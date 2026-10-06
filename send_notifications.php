<?php
// cPanel cron (every minute) that emails members about newly published games, a few at a time:
//   * * * * * php /home/USER/public_html/send_notifications.php
// Needs sql/new_app_emails.sql imported and 'site_url' set in config.php (the emails link to it).
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/api.php';
if (SITE_URL === '') { fwrite(STDERR, "send_notifications: set 'site_url' in config.php\n"); exit(1); }
$r = mail_sweep();
echo "queued {$r['queued']}, sent {$r['sent']}, failed {$r['failed']}, pending {$r['pending']}" . ($r['note'] ? " ({$r['note']})" : '') . "\n";
