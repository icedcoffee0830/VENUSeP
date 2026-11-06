<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL for FAQ Management. Columns: venusep_schema.sql (search "CREATE TABLE faqs").

   faqs: one row per question, ordered inside its section by sort_order.
     is_builtin = 1: ships with the system: hidden, never deleted; default_question and
     default_answer are the wording "restore" goes back to.
     policy: any, refunds_on, refunds_off: which refund switch shows it. */
class FaqQueries
{
    /* The next position at the end of a section. */
    public static function nextPosition(string $section): int
    {
        return (int) DB::selectOne('SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM faqs WHERE section_key = ?', [$section])->next_order;
    }

    public static function add(string $section, string $question, string $answer, string $policy, int $order, int $actor): void
    {
        DB::insert("
            INSERT INTO faqs (section_key, question, answer, policy, sort_order, created_by_user_id, updated_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ", [$section, $question, $answer, $policy, $order, $actor, $actor]);
    }

    /* Where a question stands: its section and position; or null. */
    public static function findPosition(int $id): ?array
    {
        $row = DB::selectOne('SELECT section_key, sort_order FROM faqs WHERE id = ?', [$id]);
        return $row ? (array) $row : null;
    }

    public static function save(int $id, string $section, string $question, string $answer, string $policy, int $order, int $actor): void
    {
        DB::update("
            UPDATE faqs
            SET section_key = ?,
                question = ?,
                answer = ?,
                policy = ?,
                sort_order = ?,
                updated_by_user_id = ?
            WHERE id = ?
        ", [$section, $question, $answer, $policy, $order, $actor, $id]);
    }

    /* Show or hide. False when the question is gone. */
    public static function toggle(int $id, int $actor): bool
    {
        return DB::update('UPDATE faqs SET is_active = NOT is_active, updated_by_user_id = ? WHERE id = ?', [$actor, $id]) > 0;
    }

    /* False when nothing was deleted: it is built in, or already gone. */
    public static function delete(int $id): bool
    {
        return DB::delete('DELETE FROM faqs WHERE id = ? AND is_builtin = 0', [$id]) > 0;
    }

    public static function exists(int $id): bool
    {
        return DB::selectOne('SELECT 1 AS found FROM faqs WHERE id = ?', [$id]) !== null;
    }

    /* A built-in question goes back to its original wording. */
    public static function restore(int $id, int $actor): void
    {
        DB::update("
            UPDATE faqs
            SET question = default_question,
                answer = default_answer,
                updated_by_user_id = ?
            WHERE id = ? AND is_builtin = 1 AND default_question IS NOT NULL
        ", [$actor, $id]);
    }

    /* The neighbour in the same section, just above or just below; or null. */
    public static function findNeighbour(string $section, int $order, bool $above): ?array
    {
        $row = DB::selectOne($above
            ? 'SELECT id, sort_order FROM faqs WHERE section_key = ? AND sort_order < ? ORDER BY sort_order DESC, id DESC LIMIT 1'
            : 'SELECT id, sort_order FROM faqs WHERE section_key = ? AND sort_order > ? ORDER BY sort_order ASC, id ASC LIMIT 1',
            [$section, $order]);
        return $row ? (array) $row : null;
    }

    /* Two questions trade places. */
    public static function swap(int $id, int $order, int $otherId, int $otherOrder): void
    {
        DB::transaction(function () use ($id, $order, $otherId, $otherOrder) {
            DB::update('UPDATE faqs SET sort_order = ? WHERE id = ?', [$otherOrder, $id]);
            DB::update('UPDATE faqs SET sort_order = ? WHERE id = ?', [$order, $otherId]);
        });
    }
}
