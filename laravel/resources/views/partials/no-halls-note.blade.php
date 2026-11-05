{{-- Staff with no halls see nothing here, so say why. --}}
@if (staff_has_no_halls())
<p role="status" style="margin:1rem 1rem 0;padding:.7rem 1rem;border-radius:10px;background:#fdf3e6;color:#8a5a12;font-size:.9rem">{{ STAFF_NO_HALLS_NOTE }}</p>
@endif
