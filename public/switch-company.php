<?php
/**
 * Super Admin company switcher. Sets the active company scope in the session
 * so the SaaS operator can operate any tenant. Non-super-admins are ignored.
 */

require __DIR__ . '/../src/bootstrap.php';

use FNBBOS\Auth;
use FNBBOS\Csrf;
use FNBBOS\AuditLog;

Auth::requireLogin();

if (requestMethod() !== 'POST') {
    redirect('pages/dashboard.php');
}
Csrf::requireValid();

$companyId = asInt(input('company_id')) ?: null;
Auth::setActiveCompany($companyId);
AuditLog::record('company.switch', 'company', $companyId, ['to' => $companyId]);

$back = (string)input('back', 'pages/dashboard.php');
// Only allow internal relative redirects.
if (str_contains($back, '://') || str_starts_with($back, '//')) {
    $back = 'pages/dashboard.php';
}
redirect($back);
