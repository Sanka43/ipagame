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
    // Public URL of this site, e.g. 'https://app.ipagame.store'. Empty = auto-detect.
    'site_url' => '',
];
