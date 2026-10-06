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
    // SMTP that sends the sign-up / password reset codes. Left out = emails are saved to data/mail/ (dev).
    // Brevo (inbox delivery): Brevo → SMTP & API → SMTP tab. 'user' is the "Login" shown there
    // (xxxx@smtp-brevo.com), 'pass' is an SMTP key generated on that page. 'from' must be a verified Brevo
    // sender on the authenticated ipagame.store domain.
    // (Old cPanel mailbox instead: host premium362-1.web-hosting.com, port 465, secure ssl,
    //  user/from info@ipagame.store and the mailbox password.)
    'mail' => [
        'host' => 'smtp-relay.brevo.com',
        'port' => 587,                // 2525 also works if the host blocks 587
        'secure' => 'tls',            // 'ssl' for 465, 'tls' for 587/2525
        'user' => 'xxxxxxxxx@smtp-brevo.com',
        'pass' => '',
        'from' => 'info@ipagame.store',
        'from_name' => 'IPA Game Store',
    ],
    // Optional: a separate SMTP for the "new app" / announcement emails (the sign-up codes keep using 'mail').
    // Left out = those emails use 'mail' too. Example: the cPanel mailbox.
    // 'bulk_mail' => [
    //     'host' => 'premium362-1.web-hosting.com',
    //     'port' => 465,
    //     'secure' => 'ssl',
    //     'user' => 'info@ipagame.store',
    //     'pass' => '',
    //     'from' => 'info@ipagame.store',
    //     'from_name' => 'IPA Game Store',
    // ],
    // "Continue with Google": OAuth client ID (Web application) from Google Cloud Console → APIs & Services →
    // Credentials. Authorized JavaScript origins: https://app.ipagame.store (and http://localhost:8099 for dev).
    // Empty = the Google button is hidden.
    'google_client_id' => '',
    // Public URL of this site, e.g. 'https://app.ipagame.store'. Set it on the server: emails only
    // link to this address (never to the request's Host header). Empty = auto-detect for icons only.
    'site_url' => '',
    // Social buttons in the home page header. Full URLs; leave one empty to hide that button.
    'social' => [
        'x' => '#',          // https://x.com/yourname
        'telegram' => '#',   // https://t.me/yourchannel
        'youtube' => '#',    // https://youtube.com/@yourchannel
    ],
];
