<?php
/**
 * Static template download endpoint.
 *
 * Some shared hosts (e.g. Hostinger) serve unknown extensions like .csv as
 * text/plain, which causes the browser to save the file as .txt. This script
 * forces the correct Content-Type and Content-Disposition headers so the
 * download lands with the right name and extension every time.
 *
 * Allowed names are listed in $TEMPLATES — anything else returns 404.
 */

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$TEMPLATES = [
    'sales_import_csv' => [
        'path'  => FNBBOS_PUBLIC . '/assets/templates/sales_import_template.csv',
        'name'  => 'sales_import_template.csv',
        'mime'  => 'text/csv; charset=utf-8',
    ],
    'sales_import_readme' => [
        'path'  => FNBBOS_PUBLIC . '/assets/templates/sales_import_template_README.txt',
        'name'  => 'sales_import_template_README.txt',
        'mime'  => 'text/plain; charset=utf-8',
    ],
];

$key = (string)input('name');
if (!isset($TEMPLATES[$key])) {
    http_response_code(404);
    echo 'Template not found.';
    exit;
}

$tpl = $TEMPLATES[$key];
if (!is_readable($tpl['path'])) {
    http_response_code(500);
    echo 'Template file is missing on the server.';
    exit;
}

header('Content-Type: ' . $tpl['mime']);
header('Content-Disposition: attachment; filename="' . $tpl['name'] . '"');
header('Content-Length: ' . filesize($tpl['path']));
header('Cache-Control: no-store');
readfile($tpl['path']);
exit;
