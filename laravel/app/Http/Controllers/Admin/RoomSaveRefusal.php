<?php

namespace App\Http\Controllers\Admin;

use RuntimeException;

/* room-save: a refusal that should reach the admin as a sentence, from inside
   the transaction (the controller rolls back and replies 409 with it).
   Was the RsvRefusal class in admin/room-save.php. */
class RoomSaveRefusal extends RuntimeException
{
}
