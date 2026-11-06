{{-- One room on Venue Management: a hall, or (when $hostel) a hostel room. A hostel room is
     booked per bed, so it shows tonight's beds where a hall shows its capacity, and a price per
     head. The same maintenance line and review question for both.
     $mt: vmMaint() of the room, worked out by the page, which also counts it under Needs attention. --}}
@php
  $form      = $hostel ? 'admin.hostel-room-form' : 'admin.room-form';
  $roomPhotos = rp_gallery_urls($room['id']);
  $roomCover  = $roomPhotos ? $roomPhotos[0] : rp_cover_url($room['id']);   // fall back to the legacy single file
  $mine      = $vmMyVenue === null || in_array($room['venue'], $vmMyVenue, true);   // staff: own venue's rooms only
  $closedNow = $hostel && $room['maintenance'] && $room['maintenance']['blocks'] && hostelMaintCovers($room['maintenance'], $TODAY);
@endphp
<article class="vm-card{{ $room['active'] ? '' : ' is-inactive' }}"@if (!$room['active'] || $mt) data-attention="1"@endif>
  <div class="vm-thumb">
@if ($roomCover)
    <img src="{{ $roomCover }}" alt="{{ $room['name'] }}" width="320" height="200" loading="lazy" />
@else
    <i class="bi bi-image" aria-hidden="true"></i><span class="vm-sr">No photo yet</span>
@endif
@if (!$room['active'])
    <span class="vz-chip vz-chip-grey">Hidden</span>
@elseif ($mt)
    <span class="vz-chip vz-chip-amber">{{ $room['maintenance']['blocks'] ? 'Closed' : 'Maintenance' }}</span>
@endif
  </div>
  <div class="vm-room-body">
    <h3>{{ $room['name'] }}</h3>
@if ($hostel)
    <div class="vm-facts"><span>{{ (int) $room['beds'] }} beds · {{ $HOSTEL_CR_LABEL[$room['cr_type']] }}</span><b>₱{{ usep_peso($room['rate']) }} / head / night</b></div>
@if (!$closedNow)
@php
  $free = hostelBedsFree($room, $TODAY);
  $taken = hostelBedsTaken($room, $TODAY);
@endphp
    <span class="vm-beds" title="Counted from the guest roster — never a stored number">
      {!! vmBedStrip($taken, $room['beds']) !!}
      <span>{{ $free ? $free . ' of ' . (int) $room['beds'] . ' free tonight' : 'full tonight' }}{{ $taken ? ' · ' . vmMixLabel(hostelGenderMix($room, $TODAY)) : '' }}</span>
    </span>
@endif
@else
    <div class="vm-facts"><span>Up to <b>{{ number_format((int) $room['capacity']) }}</b> people</span><b>₱{{ usep_peso($room['fee']) }} / day</b></div>
@endif
@if (!$room['active'])
    <span class="vm-note vm-note-grey"><i class="bi bi-eye-slash" aria-hidden="true"></i>Customers can't see or book it</span>
@endif
@if ($mt)
    <span class="vm-note" title="{{ $mt['reason'] }}"><i class="bi bi-tools" aria-hidden="true"></i>{{ $mt['label'] }}</span>
@if ($mt['review'] && $mine)
    <!-- The rot guard. An indefinite closure is the only window that cannot end on its own,
         so this is the only thing that will ever ask about it, and it carries the answer. -->
    <span class="vm-review">Closed for {{ $mt['days'] }} days with no end date. Still closed?
      <a href="{{ route($form, ['id' => $room['id']]) }}" title="[SIM] opens the room's maintenance window">Reopen</a>
    </span>
@endif
@endif
  </div>
  <div class="vm-card-foot">
@if ($mine)
    <a class="vm-btn vm-edit-link" href="{{ route($form, ['id' => $room['id']]) }}">Edit room</a>
    <button type="button" class="vm-btn vm-toggle-link{{ $room['active'] ? '' : ' is-off' }}" data-toggle-id="{{ $room['id'] }}" data-toggle-name="{{ $room['name'] }}">{{ $room['active'] ? 'Hide from customers' : 'Show to customers' }}</button>
@endif
@if (admin_is_admin())
    <button type="button" class="vm-delete-link" data-delete-entity="room" data-delete-id="{{ $room['id'] }}" data-delete-name="{{ $room['name'] }}">Delete</button>
@endif
  </div>
</article>
