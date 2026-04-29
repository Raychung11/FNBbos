<?php
// SLV WMS — partials/footer.php
// Purpose: Closes the main layout opened in partials/header.php.
// Last updated: 2026-04-29
?>
</main>
<footer class="border-t border-gray-200 mt-8">
  <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 text-xs text-gray-500 flex justify-between">
    <span><?= e_(setting('brand.company_name', 'SLV Group Sdn. Bhd.')) ?> · WMS</span>
    <span>&copy; <?= e_(date('Y')) ?></span>
  </div>
</footer>
</body>
</html>
