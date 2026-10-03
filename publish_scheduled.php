<?php
// Optional cPanel cron (every minute) so scheduled releases also go live on the other site
// even when nobody has opened this one:  * * * * * php /home/USER/public_html/publish_scheduled.php
// api.php does the same sweep on every request, so without cron a release is delayed until the next hit here.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/api.php';
echo publish_due(), " published\n";
