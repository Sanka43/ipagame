<?php
// Copy this file to config.php and set a strong password.
// config.php is git-ignored so the real credentials never reach GitHub.
return [
    'admin_user' => 'admin',
    'admin_pass' => 'change-me',
    // Shared MySQL database (the same one the ipagame.store "ipa game site" uses).
    // On cPanel: the CPANELUSER_-prefixed database and user. Left out = local XAMPP defaults.
    'db' => [
        'host' => 'localhost',
        'name' => 'CPANELUSER_ipa_store',
        'user' => 'CPANELUSER_ipauser',
        'pass' => '',
    ],
    // Mailbox that sends the sign-up verification codes. Create info@ipagame.store in cPanel → Email Accounts,
    // then copy the SMTP settings from its "Connect Devices" page. Left out = emails are saved to data/mail/ (dev).
    'mail' => [
        // The server's own name: its SSL certificate is *.web-hosting.com, so 'mail.ipagame.store'
        // fails the certificate check.
        'host' => 'premium362-1.web-hosting.com',
        'port' => 465,
        'secure' => 'ssl',            // 'ssl' for 465, 'tls' for 587
        'user' => 'info@ipagame.store',
        'pass' => '',
        'from' => 'info@ipagame.store',
        'from_name' => 'Game Store',
    ],
    // "Continue with Google": OAuth client ID (Web application) from Google Cloud Console → APIs & Services →
    // Credentials. Authorized JavaScript origins: https://app.ipagame.store (and http://localhost:8099 for dev).
    // Empty = the Google button is hidden.
    'google_client_id' => '',
    // Public URL of this site, e.g. 'https://app.ipagame.store'. Set it on the server: emails only
    // link to this address (never to the request's Host header). Empty = auto-detect for icons only.
    'site_url' => '',
];
