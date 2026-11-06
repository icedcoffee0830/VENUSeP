<?php

namespace App\Queries;

use Illuminate\Support\Facades\DB;

/* The SQL behind room photos, 360 photos and office cover photos (legacy/includes/room-photos.php
   and venue-photos.php). The files themselves are the caller's.
   Columns: venusep_schema.sql (search "CREATE TABLE room_media").

   room_media: one row per file; media_type is photo or panorama_360, display_order the
     gallery order.
   venues.cover_photo: an office's one cover photo.
   Read only: rooms. */
class RoomMediaQueries
{
    /* A room's id by its code, deleted rooms too; or null. */
    public static function roomId(string $roomCode): ?int
    {
        $row = DB::selectOne('SELECT id FROM rooms WHERE room_code = ?', [$roomCode]);
        return $row ? (int) $row->id : null;
    }

    /* A room's photos in gallery order: their paths. */
    public static function photoPaths(int $roomId): array
    {
        return array_column(DB::select("
            SELECT file_path
            FROM room_media
            WHERE room_id = ? AND media_type = 'photo'
            ORDER BY display_order ASC, id ASC
        ", [$roomId]), 'file_path');
    }

    public static function nextPhotoOrder(int $roomId): int
    {
        return (int) DB::selectOne("
            SELECT COALESCE(MAX(display_order), 0) + 1 AS next_order
            FROM room_media
            WHERE room_id = ? AND media_type = 'photo'
        ", [$roomId])->next_order;
    }

    /* One stored file. $meta: mime, size, hash, as rp_store_file() gives them. */
    public static function add(int $roomId, string $type, string $path, string $originalName, int $order, array $meta, $uploadedBy): void
    {
        DB::insert("
            INSERT INTO room_media
                (room_id, media_type, file_path, original_filename, display_order,
                 mime_type, file_size_bytes, sha256_hash, uploaded_by_user_id)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ", [$roomId, $type, $path, $originalName, $order, $meta['mime'], $meta['size'], $meta['hash'], $uploadedBy]);
    }

    public static function deletePhoto(int $roomId, string $path): void
    {
        DB::delete("DELETE FROM room_media WHERE room_id = ? AND media_type = 'photo' AND file_path = ?", [$roomId, $path]);
    }

    public static function setPhotoOrder(int $roomId, string $path, int $order): void
    {
        DB::update("
            UPDATE room_media
            SET display_order = ?
            WHERE room_id = ? AND media_type = 'photo' AND file_path = ?
        ", [$order, $roomId, $path]);
    }

    /* The newest 360 photo's path, or null. */
    public static function panoramaPath(int $roomId): ?string
    {
        return DB::selectOne("
            SELECT file_path
            FROM room_media
            WHERE room_id = ? AND media_type = 'panorama_360'
            ORDER BY id DESC
            LIMIT 1
        ", [$roomId])->file_path ?? null;
    }

    public static function panoramaPaths(int $roomId): array
    {
        return array_column(DB::select("
            SELECT file_path
            FROM room_media
            WHERE room_id = ? AND media_type = 'panorama_360'
        ", [$roomId]), 'file_path');
    }

    public static function deletePanoramas(int $roomId): void
    {
        DB::delete("DELETE FROM room_media WHERE room_id = ? AND media_type = 'panorama_360'", [$roomId]);
    }

    /* An office with its cover photo, or null. */
    public static function findOffice(int $venueId): ?array
    {
        $row = DB::selectOne('SELECT id, name, description, cover_photo FROM venues WHERE id = ?', [$venueId]);
        return $row ? (array) $row : null;
    }

    public static function coverByOfficeName(string $name): ?string
    {
        return DB::selectOne('SELECT cover_photo FROM venues WHERE name = ?', [$name])->cover_photo ?? null;
    }

    /* $path NULL takes the cover photo away. */
    public static function saveCover(int $venueId, ?string $path): void
    {
        DB::update('UPDATE venues SET cover_photo = ? WHERE id = ?', [$path, $venueId]);
    }
}
