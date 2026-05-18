<footer class="site-foot">
  <div class="wrap">
    <div class="site-foot__top">
      <div>
        <h4><?= e(config('app.name')) ?></h4>
        <p>Finance-grade operations for Malaysian F&amp;B groups. Centralise sales,
           protect margins, automate compliance.</p>
      </div>
      <div>
        <h4>Product</h4>
        <a href="#features">Features</a><br>
        <a href="#how">How it works</a><br>
        <a href="#demo">Request demo</a>
      </div>
      <div>
        <h4>Account</h4>
        <a href="<?= e(url('login.php')) ?>">Login</a><br>
        <a href="<?= e(url('index.php#demo')) ?>">Get started</a>
      </div>
    </div>
    <div class="site-foot__bottom">
      <span>&copy; <?= date('Y') ?> <?= e(config('app.name')) ?>. Built for Malaysian F&amp;B.</span>
      <span>PHP 8.2 · MySQL 8 · No platform lock-in</span>
    </div>
  </div>
</footer>
</body>
</html>
