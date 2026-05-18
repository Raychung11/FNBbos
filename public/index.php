<?php
/**
 * Public marketing landing page. The authenticated dashboard now lives at
 * /public/pages/dashboard.php. This page is reachable without login and
 * adapts its top-right CTA based on session state (Login vs Open dashboard).
 */

require __DIR__ . '/../src/bootstrap.php';

use FNBBOS\Csrf;

// Demo request form is handled by public/demo-request.php which redirects back
// here with a flash message (?demo=ok|err).
$demoFlash = (string)input('demo');

include __DIR__ . '/partials/site-header.php';
?>

<!-- ============================================================ HERO -->
<section class="hero">
  <div class="wrap">
    <span class="hero__eyebrow">F&amp;B Operating System for Malaysia</span>
    <h1 class="hero__title">Run your F&amp;B group with finance-grade confidence.</h1>
    <p class="hero__sub">
      Centralise sales from every delivery platform, calculate SST and platform
      fees automatically, reconcile bank settlements, and catch suspicious
      claims before they hit your bottom line — built for Malaysian F&amp;B
      groups operating multiple brands and outlets.
    </p>
    <div class="hero__cta">
      <a class="btn btn--primary btn--lg" href="#demo">Request a demo</a>
      <a class="btn btn--ghost btn--lg" href="#features" style="background: transparent; color: #e6edff; border-color: rgba(255,255,255,.25);">See features</a>
    </div>
    <div class="hero__badges">
      <span>Configurable SST (0% / 6% / 8% / custom)</span>
      <span>Multi-brand, multi-outlet</span>
      <span>WhatsApp alerts via Evolution API</span>
      <span>Built-in audit trail</span>
    </div>
  </div>
</section>

<!-- ============================================================ PROBLEM -->
<section class="section">
  <div class="wrap">
    <div class="section__head">
      <span class="section__eyebrow">Why this exists</span>
      <h2 class="section__title">Multi-outlet F&amp;B groups bleed money in three places.</h2>
      <p class="section__lead">If finance is still matching delivery statements with bank credits by hand, you are losing margin every week.</p>
    </div>
    <div class="problem-grid">
      <div class="problem-card">
        <h3>Reconciliation chaos</h3>
        <p>GrabFood, Foodpanda, ShopeeFood weekly statements never line up cleanly with your bank receipts. Finance spends days on spreadsheets.</p>
      </div>
      <div class="problem-card">
        <h3>Claim leakage</h3>
        <p>Petty cash, supplier purchases and staff claims pile up without scrutiny. Duplicate receipts, split claims and unusual suppliers slip through.</p>
      </div>
      <div class="problem-card">
        <h3>SST guesswork</h3>
        <p>Inclusive vs exclusive, 6% vs 8%, exempt vs taxable. One wrong setting on one outlet creates a compliance headache months later.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ FEATURES -->
<section class="section section--alt" id="features">
  <div class="wrap">
    <div class="section__head">
      <span class="section__eyebrow">What you get</span>
      <h2 class="section__title">Everything finance needs, nothing they don't.</h2>
      <p class="section__lead">Eight production-grade modules. Every fee, every tax rate, every approval limit is configurable — never hard-coded.</p>
    </div>
    <div class="feature-grid">
      <div class="feature">
        <div class="feature__icon">⤓</div>
        <h3>Centralised sales import</h3>
        <p>One CSV format, every platform: GrabFood, Foodpanda, ShopeeFood, POS, Website, WhatsApp, Manual, Catering, plus any custom platform.</p>
      </div>
      <div class="feature">
        <div class="feature__icon">%</div>
        <h3>Configurable fee engine</h3>
        <p>Per-platform commission, payment-gateway fee, fixed fee, and treatment of vouchers / delivery / refunds. Effective-dated rules — old rates stay as audit history.</p>
      </div>
      <div class="feature">
        <div class="feature__icon">∑</div>
        <h3>SST / tax engine</h3>
        <p>0%, 6%, 8% or any custom rate. Inclusive or exclusive. Per outlet, per platform, with effective-from dates. Switching rates is a UI change, not a deploy.</p>
      </div>
      <div class="feature">
        <div class="feature__icon">⇄</div>
        <h3>Bank reconciliation</h3>
        <p>Upload bank statements. The system matches credits to expected platform settlements grouped by platform / outlet / date and flags underpayments and missing settlements.</p>
      </div>
      <div class="feature">
        <div class="feature__icon">⚠</div>
        <h3>AI-style claim risk scoring</h3>
        <p>Every claim gets a 0–100 score from ten signals: amount anomaly, frequency, duplicate receipts, split claims, supplier patterns, time anomaly, budget burn, role mismatch, OCR mismatch, profit impact. Each score comes with a plain-English explanation.</p>
      </div>
      <div class="feature">
        <div class="feature__icon">✓</div>
        <h3>Approval workflow</h3>
        <p>Configurable approval matrix. Below RM100 → Outlet Manager. Above RM2,000 → Director. Critical-risk claims auto-route to Finance. All configurable per claim type.</p>
      </div>
      <div class="feature">
        <div class="feature__icon">◔</div>
        <h3>WhatsApp &amp; email alerts</h3>
        <p>Critical claims, missing settlements, budget overruns, abnormal purchases — pushed to your team via Evolution API or email.</p>
      </div>
      <div class="feature">
        <div class="feature__icon">◫</div>
        <h3>Reports &amp; audit trail</h3>
        <p>Daily sales, platform settlement, claim summary, abnormal claims, outlet profit. CSV export ready. Append-only audit log on every state change.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ HOW IT WORKS -->
<section class="section" id="how">
  <div class="wrap">
    <div class="section__head">
      <span class="section__eyebrow">How it works</span>
      <h2 class="section__title">Live in days, not months.</h2>
    </div>
    <div class="steps">
      <div class="step">
        <h3>Set up your structure</h3>
        <p>Add your brands, outlets, users and roles. Each outlet gets its own SST profile, claim budget and bank account.</p>
      </div>
      <div class="step">
        <h3>Configure your rules</h3>
        <p>Plug in your platform fee rates, tax profiles and approval matrix. We ship sensible defaults — tweak only what's different.</p>
      </div>
      <div class="step">
        <h3>Operate &amp; monitor</h3>
        <p>Upload sales, sync bank statements, log claims. The dashboard surfaces leakage, suspicious claims and loss-making outlets in real time.</p>
      </div>
    </div>
  </div>
</section>

<!-- ============================================================ DEMO -->
<section class="section section--alt" id="demo">
  <div class="wrap">
    <div class="demo">
      <div>
        <h2>Request a demo</h2>
        <p>Tell us about your group. We'll set up a sandbox tenant with your outlets pre-configured and walk you through a live reconciliation in under 30 minutes.</p>
        <ul>
          <li>Sandbox tenant with sample data</li>
          <li>Live walkthrough of sales import + risk scoring</li>
          <li>Custom fee rules for the platforms you actually use</li>
          <li>No credit card, no commitment</li>
        </ul>
      </div>
      <form class="demo-form" method="post" action="<?= e(url('demo-request.php')) ?>">
        <?= Csrf::field() ?>
        <?php if ($demoFlash === 'ok'): ?>
          <div class="alert alert--ok">Thanks — we'll be in touch within one business day.</div>
        <?php elseif ($demoFlash === 'err'): ?>
          <div class="alert alert--error">Something went wrong. Please check the form and try again.</div>
        <?php endif; ?>
        <div class="grid2">
          <div class="row"><label>Full name *</label><input type="text" name="full_name" required maxlength="120"></div>
          <div class="row"><label>Company *</label><input type="text" name="company_name" required maxlength="160"></div>
        </div>
        <div class="grid2">
          <div class="row"><label>Work email *</label><input type="email" name="email" required maxlength="160"></div>
          <div class="row"><label>Phone (WhatsApp)</label><input type="text" name="phone" maxlength="40"></div>
        </div>
        <div class="grid2">
          <div class="row"><label>Number of outlets</label>
            <select name="outlet_count">
              <option value="">Select…</option>
              <option value="1">1</option>
              <option value="3">2–5</option>
              <option value="10">6–15</option>
              <option value="30">16–50</option>
              <option value="100">50+</option>
            </select>
          </div>
          <div class="row"><label>Platforms used</label>
            <input type="text" name="platforms" placeholder="e.g. GrabFood, Foodpanda, POS" maxlength="255">
          </div>
        </div>
        <div class="row"><label>Anything we should know?</label><textarea name="message" maxlength="2000" placeholder="Pain points, urgency, current finance setup…"></textarea></div>
        <!-- honeypot for bots -->
        <div class="hp" aria-hidden="true"><label>Leave this empty</label><input type="text" name="website" tabindex="-1" autocomplete="off"></div>
        <button class="btn btn--primary btn--lg" type="submit">Request my demo</button>
        <p style="font-size:12px;color:var(--site-ink-soft);margin-top:10px;">By submitting you consent to be contacted about a demo. We don't share your details.</p>
      </form>
    </div>
  </div>
</section>

<?php include __DIR__ . '/partials/site-footer.php'; ?>
