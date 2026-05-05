<?php
// SLV WMS — partials/sw_bootstrap.php
// Renders an inline <script> that:
//   1. Registers /service-worker.js
//   2. Listens for the SLV_SW_ACTIVATED message the SW posts on activate
//   3. Auto-reloads the page once when the message arrives (so a tab
//      open during a deploy refreshes itself and stops serving stale
//      HTML from the previous SW). sessionStorage flag prevents a loop.
//
// Include this once near the closing </body> on every page that wants
// auto-update behaviour: footer.php for desktop, each m/*.php manually
// (since they don't share footer.php).
?>
<script>
(function () {
  if (!('serviceWorker' in navigator)) return;

  // Listen for the activation broadcast first, so we don't miss a message
  // that arrives during the same tick as the register() call below.
  navigator.serviceWorker.addEventListener('message', function (e) {
    if (!e || !e.data || e.data.type !== 'SLV_SW_ACTIVATED') return;
    if (sessionStorage.getItem('slv_sw_reloaded') === '1') return;
    sessionStorage.setItem('slv_sw_reloaded', '1');
    setTimeout(function () { location.reload(); }, 50);
  });

  // Clear the loop guard once we're past the SW-just-took-over window.
  setTimeout(function () { sessionStorage.removeItem('slv_sw_reloaded'); }, 8000);

  window.addEventListener('load', function () {
    navigator.serviceWorker.register('/service-worker.js').catch(function () {});
  });
})();
</script>
