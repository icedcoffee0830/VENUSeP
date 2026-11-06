/* Script for resources/views/valid-id-view.blade.php. */
  /* Back to wherever this was opened from — it opens in the same tab now — or,
     opened directly, to the booking's own page. */
  function viBack() {
    var cameFromHere = document.referrer && document.referrer.indexOf(location.origin) === 0;
    if (cameFromHere && history.length > 1) { history.back(); return; }
    location.href = validIdView.viHome;
  }
  var viTurn = 0;
  function viRotate() {
    var img = document.getElementById('viImg');
    if (!img) return;
    viTurn = (viTurn + 90) % 360;
    img.style.transform = 'rotate(' + viTurn + 'deg)';
  }
  function viZoom() {
    var stage = document.getElementById('viStage');
    var on = stage.classList.toggle('is-actual');
    var btn = document.getElementById('viZoomBtn');
    if (btn) btn.innerHTML = '<i class="bi ' + (on ? 'bi-arrows-angle-contract' : 'bi-arrows-angle-expand') + '" aria-hidden="true"></i>';
  }
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') viBack(); });
