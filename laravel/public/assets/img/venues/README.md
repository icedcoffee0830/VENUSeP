# Venue and hostel photos

Photos are uploaded through the admin UI and recorded in the database. This
file explains where they end up and what the limits are.

## The normal way: upload them in the admin

    Room photos + 360°   Venue Management -> a room -> Edit -> Photos
    Venue cover photo    Venue Management -> a venue -> Edit Details -> Change photo

Each upload writes the file to disk AND a row in the database, so every copy
of the database shows the same gallery in the same order. Uploading is the
only way to get a gallery, an ordering, or a 360° panorama.

## Where the files land

    assets/img/venues/rooms/<room>/photos/p_<hash>.jpg   room gallery (a row in room_media)
    assets/img/venues/rooms/<room>/pano.jpg              the room's one 360° photo
    assets/img/venues/covers/<venue id>.jpg              venue cover (venues.cover_photo)

`<room>` is the room's code — r1..r8 for event rooms, h1..h5 for hostel rooms.
The filenames under `photos/` are generated; do not rename them, the database
row points at the path.

The cover photo of a room is simply the FIRST photo in its gallery. Reorder
the gallery in the admin and the cover follows.

## Limits

    5 gallery photos per room      (the 360° panorama is separate: one per room)
    8 MB per file
    .jpg .jpeg .png .webp

## Dropping a file in by hand (fallback, cover only)

Still supported, for a room with NO uploaded photos:

    assets/img/venues/r1.jpg      Alumni Grand Ballroom
    assets/img/venues/h1.jpg      Hostel Room 1
    ...and so on for any room code

That file is used as the room's cover photo on the landing page. It does NOT
create a gallery, it does NOT create a database row, and it is ignored the
moment a real photo is uploaded for that room. Use it for a quick placeholder;
use the admin uploader for anything real.

NOTE: there is no such fallback for VENUES. Filenames like
`venue-bahay-alumni.jpg` are not read by anything — a venue's cover photo
comes only from Edit Details -> Change photo.

## Size them first

A phone photo is 3-5 MB; sixteen of those is a 60 MB page. Resize to about
1600px wide and save as JPG at 80% (or WebP). Aim for 150-250 KB each.
Landscape orientation.

Room codes come from `includes/venue-rooms.php` and `includes/hostel-rooms.php`.
Until a photo exists the page shows a drawn placeholder for that room.
