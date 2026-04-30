<?php
// SLV WMS — pages/customers/_save.php
// Purpose: Shared validate + persist for customers create.php / edit.php.

declare(strict_types=1);

function customers_save_post(?array $existing): array
{
    $errors = [];
    $values = [
        'code'                 => strtoupper(trim((string)($_POST['code']                 ?? ''))),
        'name'                 => trim((string)($_POST['name']                 ?? '')),
        'contact_person'       => trim((string)($_POST['contact_person']       ?? '')),
        'phone'                => trim((string)($_POST['phone']                ?? '')),
        'email'                => trim((string)($_POST['email']                ?? '')),
        'billing_address'      => trim((string)($_POST['billing_address']      ?? '')),
        'shipping_address'     => trim((string)($_POST['shipping_address']     ?? '')),
        'tax_no'               => trim((string)($_POST['tax_no']               ?? '')),
        'payment_terms'        => trim((string)($_POST['payment_terms']        ?? '')),
        'default_tax_group_id' => ($_POST['default_tax_group_id'] ?? '') === '' ? null : (int)$_POST['default_tax_group_id'],
        'credit_limit'         => (string)($_POST['credit_limit']         ?? '0'),
        'notes'                => trim((string)($_POST['notes']                ?? '')),
        'status'               => (string)($_POST['status']                ?? 'ACTIVE'),
    ];
    if (!preg_match('/^[A-Z0-9_\-]{1,64}$/', $values['code']))                  $errors[] = 'Code 1–64 letters/digits/dash/underscore.';
    if ($values['name'] === '' || mb_strlen($values['name']) > 191)              $errors[] = 'Name required (max 191).';
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
    if (!is_numeric($values['credit_limit']) || (float)$values['credit_limit'] < 0) $errors[] = 'Credit limit must be ≥ 0.';
    if (!in_array($values['status'], ['ACTIVE','INACTIVE'], true))               $errors[] = 'Invalid status.';

    if ($errors) return [$errors, $values];

    try {
        if ($existing) {
            db()->prepare(
                'UPDATE customers
                    SET code=?, name=?, contact_person=?, phone=?, email=?,
                        billing_address=?, shipping_address=?, tax_no=?, payment_terms=?,
                        default_tax_group_id=?, credit_limit=?, status=?, notes=?
                  WHERE id=? AND company_id=?'
            )->execute([
                $values['code'], $values['name'], $values['contact_person'] ?: null,
                $values['phone'] ?: null, $values['email'] ?: null,
                $values['billing_address'] ?: null, $values['shipping_address'] ?: null,
                $values['tax_no'] ?: null, $values['payment_terms'] ?: null,
                $values['default_tax_group_id'], (float)$values['credit_limit'],
                $values['status'], $values['notes'] ?: null,
                $existing['id'], company_id(),
            ]);
            audit_log('customer_update', 'customers', (int)$existing['id'], ['code' => $values['code']]);
        } else {
            db()->prepare(
                'INSERT INTO customers
                   (company_id, code, name, contact_person, phone, email,
                    billing_address, shipping_address, tax_no, payment_terms,
                    default_tax_group_id, credit_limit, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                company_id(), $values['code'], $values['name'],
                $values['contact_person'] ?: null, $values['phone'] ?: null, $values['email'] ?: null,
                $values['billing_address'] ?: null, $values['shipping_address'] ?: null,
                $values['tax_no'] ?: null, $values['payment_terms'] ?: null,
                $values['default_tax_group_id'], (float)$values['credit_limit'],
                $values['status'], $values['notes'] ?: null,
            ]);
            audit_log('customer_create', 'customers', (int)db()->lastInsertId(), ['code' => $values['code']]);
        }
    } catch (PDOException $e) {
        $errors[] = (int)($e->errorInfo[1] ?? 0) === 1062
            ? 'Code already exists.' : 'Database error.';
    }
    return [$errors, $values];
}
