{{-- The Server panel (failover plan §2): the Server page and the emergency page show the same one.
     Rows are filled in by assets/js/admin/server.js, one Test per row (a laptop that is off
     costs 3 s, so the page itself never waits for it). Needs $svRows, $svUrl, $svProtected. --}}
<div class="sv-head">
  <h1>Server</h1>
  <p>Every laptop keeps a live copy of the database. If the main laptop breaks, switch to an up-to-date copy here and nothing is lost. Only the super admin sees this page.</p>
</div>

<section class="sv-card" aria-labelledby="svHereTitle">
  <h2 id="svHereTitle">This laptop</h2>
  <div class="sv-here">
    <div>
      @if ($svUrl)
        <p class="sv-sub">Staff and phones on the hotspot open the system at this address. Scan the code to open it.</p>
        <div class="sv-addr" translate="no">{{ $svUrl }}</div>
      @else
        <p class="sv-sub">This laptop is not on a network right now (no hotspot or Wi-Fi address).</p>
      @endif
    </div>
    @if ($svUrl)<div class="sv-qr" data-sv-qr="{{ $svUrl }}" hidden></div>@endif
  </div>
</section>

<section class="sv-card" aria-labelledby="svListTitle">
  <h2 id="svListTitle">Database servers</h2>
  <p class="sv-sub">Every save goes to the main. Saving waits until at least one copy has it, so a broken laptop loses nothing.</p>
  <table class="sv-table">
    <thead><tr><th scope="col">Laptop</th><th scope="col">Address</th><th scope="col">Role</th><th scope="col">State</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead>
    <tbody id="svRows">
    @foreach ($svRows as $r)
      <tr data-ip="{{ $r['ip'] }}" data-name="{{ $r['name'] }}" data-listed="{{ $r['listed'] ? 1 : 0 }}">
        <td><strong>{{ $r['name'] }}</strong>@if ($r['mine']) <span class="sv-muted">(this one)</span>@endif</td>
        <td class="sv-mono" translate="no">{{ $r['ip'] }}</td>
        <td data-cell="role">—</td>
        <td data-cell="state"><span class="sv-muted">Checking…</span></td>
        <td><div class="sv-actions" data-cell="actions"></div></td>
      </tr>
    @endforeach
    </tbody>
  </table>
  <div class="sv-add">
    <button type="button" class="sv-btn" id="svFind"><i class="bi bi-search" aria-hidden="true"></i>Find servers on this hotspot</button>
    <label class="visually-hidden" for="svIp">IP address to test</label>
    <input id="svIp" class="sv-input" inputmode="decimal" autocomplete="off" spellcheck="false" placeholder="Or type an IP, e.g. 192.168.43.14…">
    <button type="button" class="sv-btn" id="svTest">Test</button>
  </div>
  <div class="sv-testres" id="svResult" aria-live="polite" hidden></div>
</section>

<section class="sv-card" aria-labelledby="svFreeTitle">
  <div class="sv-free">
    <div>
      <h2 id="svFreeTitle">Saving without a copy</h2>
      @if ($svProtected === null)
        <p class="sv-sub" style="margin:0">The main can’t be reached or is not set up for copies yet (SETUP.md, “Two or more laptops”).</p>
      @elseif ($svProtected)
        <p class="sv-sub" style="margin:0">Now: <strong>protected</strong>. If every copy is down, saving stops until one is back. Turn this on only in an emergency: saves made while it is on exist on one laptop only.</p>
      @else
        <p class="sv-sub" style="margin:0">Now: <strong class="sv-bad">not protected</strong>. Saves are not waiting for a copy, so they exist on one laptop only. Turn protection back on as soon as a copy is up again.</p>
      @endif
    </div>
    @if ($svProtected !== null)
      <button type="button" class="sv-btn {{ $svProtected ? 'sv-btn-outline' : 'sv-btn-primary' }}" id="svSaving" data-on="{{ $svProtected ? 0 : 1 }}">{{ $svProtected ? 'Allow saving without a copy' : 'Protect saving again' }}</button>
    @endif
  </div>
</section>
