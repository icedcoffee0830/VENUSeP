<?php
/* customer/ has no start page of its own — send the visitor to the landing page
   so a phone typing the folder address never sees the raw file list. */
header('Location: venusep_venue_booking.php');
exit;
