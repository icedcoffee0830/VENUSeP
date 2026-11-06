{{-- A NEW HALL NAMES ITS STAFF (Aron 2026-11-05): every hall keeps at least one staff member
     of its office. One list per office; only the chosen location's list shows, and only its
     ticks are sent (room-save checks them again). $rfStaff: office name => [user id => name]. --}}
        <div class="vm-row rf-staff" id="rfStaff">
          <fieldset class="vm-field" style="border:0;margin:0;padding:0;min-width:0">
            <legend style="font-size:.85rem;font-weight:600;margin-bottom:.4rem">Staff for this hall</legend>
            <p class="rf-staff-hint" style="margin:0 0 .5rem;color:#606a75;font-size:.82rem">Choose a location first.</p>
@foreach ($rfStaff as $office => $people)
            <div class="rf-staff-office" data-office="{{ $office }}" hidden>
@forelse ($people as $id => $person)
              <label style="display:flex;align-items:center;gap:.5rem;min-height:32px;font-size:.9rem;cursor:pointer">
                <input type="checkbox" class="rf-staff-pick" value="{{ $id }}" /> {{ $person }}
              </label>
@empty
              <p style="margin:0;color:#b23a3a;font-size:.85rem">{{ $office }} has no active staff member. Create or assign one first: every hall needs one.</p>
@endforelse
            </div>
@endforeach
          </fieldset>
        </div>
        <script src="{{ asset_stamped('assets/js/room-form-staff.js') }}"></script>
