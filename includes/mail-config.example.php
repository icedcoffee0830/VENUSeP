<?php
/* =====================================================================
   MAIL SETTINGS — TEMPLATE. Copy this file to includes/mail-config.php
   (git-ignored) and fill it in there. NEVER commit the copy: on the demo
   machine it holds a Gmail App Password.

   transport  'smtp' sends through the server below.
              'log'  sends nothing; each email is written as a .eml file
                     into <doc_root()>/mail-log/ (outside the web root).
                     This is also what happens when mail-config.php is
                     missing, so a fresh checkout can never email anyone.

   LAPTOPS (everyone on the team): run Mailpit, a local test inbox, and
   keep the values below as they are. Every email the site sends then
   shows at http://localhost:8025 instead of reaching a real person.
     Windows: download mailpit-windows-amd64.zip from
              https://github.com/axllent/mailpit/releases, unzip, run mailpit.exe
     Mac:     brew install mailpit && mailpit

   DEMO MACHINE ONLY (real email through the VENUSeP Gmail account):
     host 'smtp.gmail.com', port 587, encryption 'tls',
     username = the Gmail address,
     password = its 16-character App Password
                (Google Account -> Security -> 2-Step Verification -> App passwords),
     from_email = the same Gmail address (Gmail rewrites any other sender).
   ===================================================================== */
return [
    'transport'  => 'smtp',
    'host'       => '127.0.0.1',     // Mailpit
    'port'       => 1025,
    'encryption' => '',              // '' for Mailpit, 'tls' for Gmail (STARTTLS on port 587)
    'username'   => '',
    'password'   => '',
    'from_email' => 'noreply@venusep.test',
    'from_name'  => 'VENUSeP',
    'base_url'   => 'http://localhost/VENUSeP',   // used to build links in emails; no trailing slash
];
