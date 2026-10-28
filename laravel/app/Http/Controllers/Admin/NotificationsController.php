<?php

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PDOException;

/* =====================================================================
   THE BELL'S ENDPOINT (2026-10-28) — legacy/includes/notification-bell.php
   calls this about once a minute, from every signed-in page of both portals.
   (Old: notifications.php.)

   GET                            { ok, unread, items[], reminders[] }
   POST _token, action=read, id   mark one read (the person clicked it)
   POST _token, action=read_all   "Mark all as read"

   Only ever the signed-in person's OWN notifications. Staff and admins
   also get the live "about to expire" reminders (staff: their venue).
   Guards itself (JSON 401), so the route has no staff/customer middleware.
   CSRF is Laravel's middleware (the old 400 "page has expired" is now 419).
   ===================================================================== */
class NotificationsController
{
    private function reply(int $status, array $data): JsonResponse
    {
        return response()->json($data, $status, ['Cache-Control' => 'no-store'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function __invoke(Request $request): JsonResponse
    {
        legacy('auth', 'notifications');

        $type = session('account_type');
        if (in_array($type, ['admin', 'staff'], true) && session()->has('user_id')) {
            staff_session_heal();           /* a suspended account loses its session here too */
            $type = session('account_type');
        } elseif (!customer_logged_in()) {
            return $this->reply(401, ['ok' => false, 'message' => 'Not signed in.']);
        }
        $userId = (int) session('user_id', 0);
        if ($userId <= 0 || !in_array($type, ['admin', 'staff', 'customer'], true)) {
            return $this->reply(401, ['ok' => false, 'message' => 'Not signed in.']);
        }
        $pdo = venusep_db();
        if ($pdo === null) {
            return $this->reply(503, ['ok' => false, 'message' => 'The database is unreachable.']);
        }

        if ($request->isMethod('post')) {
            $action = (string) $request->input('action', '');
            if ($action === 'read_all') {
                notif_mark_read($pdo, $userId, null);
            } elseif ($action === 'read' && ctype_digit((string) $request->input('id', ''))) {
                notif_mark_read($pdo, $userId, (int) $request->input('id'));
            } else {
                return $this->reply(400, ['ok' => false, 'message' => 'Unknown action.']);
            }
            return $this->reply(200, ['ok' => true, 'unread' => notif_unread_count($pdo, $userId)]);
        }

        try {
            notif_cleanup($pdo, $userId);
            return $this->reply(200, [
                'ok'        => true,
                'unread'    => notif_unread_count($pdo, $userId),
                'items'     => notif_items($pdo, $userId, $type),
                'reminders' => $type === 'customer' ? [] : notif_reminders($pdo, staff_venue_scope()),
            ]);
        } catch (PDOException $e) {
            error_log('VENUSeP notifications: ' . $e->getMessage());
            return $this->reply(500, ['ok' => false, 'message' => 'Notifications could not be loaded.']);
        }
    }
}
