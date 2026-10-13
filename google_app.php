<?php
// Clean URL for the iOS app's Google sign-in (Google's redirect URI list prefers URLs without a query string).
// Register https://<site>/google_app.php under "Authorized redirect URIs"; the logic lives in api.php (action google_app).
$_GET['action'] = 'google_app';
require __DIR__ . '/api.php';
