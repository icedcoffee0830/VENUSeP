<?php
/* Holds: the bell and its panel in both top bars. */
/* =====================================================================
   THE NOTIFICATION BELL (2026-10-28) — in both shared top bars, so it is
   on every signed-in page: header.php (admin/staff pages and the
   customer account pages) and customer-nav.php (landing, venue
   list, booking pages, FAQ; staff in counter mode too).

   Usage:  <?php $nbLook = 'admin' | 'nav'; include __DIR__ . '/notification-bell.php'; ?>

   Unread count on the bell; the panel lists the newest 30, unread ones bold
   with a dot. A notification stays unread until it is CLICKED — that marks
   it read and opens what it is about. "Mark all as read" clears the lot.
   Reloads when public/assets/js/live.js (loaded here, so it is on every signed-in
   page) says the notifications changed: checked every 30 seconds, paused while the tab is in
   the background. Staff and admins also see live "about to expire"
   reminders at the top; those have no read state and vanish once handled.
   Every page that includes this is one folder below the app root, so the
   endpoint and the links are reached through '../'.
   ===================================================================== */
if (!session()->has('user_id') || !session()->has('account_type')) {
    return;                                 /* signed out: no bell */
}
$nbLook = isset($nbLook) ? $nbLook : 'admin';
$nbFirst = empty($GLOBALS['nbRendered']);
$GLOBALS['nbRendered'] = true;
?>
<span class="nb-wrap nb-<?php echo $nbLook === 'nav' ? 'nav' : 'admin'; ?>">
  <button type="button" class="nb-bell" aria-label="Notifications" aria-haspopup="true" aria-expanded="false" onclick="nbToggle(event)">
    <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    <span class="nb-count" hidden></span>
  </button>
  <div class="nb-panel" role="dialog" aria-label="Notifications" hidden></div>
</span>
<?php if ($nbFirst): ?>
<link rel="stylesheet" href="<?php echo asset_stamped('assets/css/notification-bell.css'); ?>" />
<script src="<?php echo asset_stamped('assets/js/live.js'); ?>" data-check="<?php echo route('root.live-check'); ?>" data-csrf="<?php echo csrf_token(); ?>"></script>
<script>
  /* Values from PHP for assets/js/notification-bell.js */
  var notificationBell = {
    appRoot: <?php echo json_encode(url('/') . '/'); ?>,
    csrf: <?php echo json_encode(csrf_token()); ?>,
  };
</script>
<script src="<?php echo asset_stamped('assets/js/notification-bell.js'); ?>"></script>
<?php endif; ?>
