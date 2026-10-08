<?php
/* =====================================================================
   GOOGLE SIGN-IN SETTINGS — TEMPLATE. Copy this file to
   includes/google-config.php (git-ignored) and fill it in there. NEVER
   commit the copy: it holds the OAuth client secret.

   With no google-config.php (or an empty client_id), the "Continue with
   Google" buttons are simply not shown, so a fresh checkout works exactly
   as before.

   WHERE THE VALUES COME FROM: Google Cloud Console, signed in as the
   VENUSeP Gmail account -> project "VENUSeP" -> Google Auth Platform ->
   Clients -> "VENUSeP localhost" (type: Web application).
     client_id      ends in .apps.googleusercontent.com
     client_secret  starts with GOCSPX-
     redirect_uri   must match one of the client's "Authorized redirect
                    URIs" EXACTLY (http vs https, port, capitals) or Google
                    answers "redirect_uri_mismatch".

   Every teammate uses the same client_id and client_secret. A laptop that
   serves the site from a different address (e.g. localhost:8080) needs that
   address added to the client's redirect URIs and set below.
   ===================================================================== */
return [
    'client_id'     => '',
    'client_secret' => '',
    'redirect_uri'  => 'http://localhost/VENUSeP/customer/google-callback.php',
];
