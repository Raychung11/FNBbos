<?php
// SLV WMS — pages/suppliers/_save.php
// Purpose: Shared validate + persist for create.php and edit.php.

declare(strict_types=1);

function suppliers_save_post(?array $existing): array
{
    $errors = [];
    $values = [
        'code'           => strtoupper(trim((string)($_POST['code']           ?? ''))),
        'name'           => trim((string)($_POST['name']           ?? '')),
        'contact_person' => trim((string)($_POST['contact_person'] ?? '')),
        'phone'          => trim((string)($_POST['phone']          ?? '')),
        'email'          => trim((string)($_POST['email']          ?? '')),
        'address'        => trim((string)($_POST['address']        ?? '')),
        'tax_no'         => trim((string)($_POST['tax_no']         ?? '')),
        'payment_terms'  => trim((string)($_POST['payment_terms']  ?? '')),
        'notes'          => trim((string)($_POST['notes']          ?? '')),
        'status'         => (string)($_POST['status']              ?? 'ACTIVE'),
    ];
    if (!preg_match('/^[A-Z0-9_\-]{1,64}$/', $values['code']))                $errors[] = 'Code 1–64 letters/digits/dash/underscore.';
    if ($values['name'] === '' || mb_strlen($values['name']) > 191)            $errors[] = 'Name required (max 191).';
    if ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email is not valid.';
    if (!in_array($values['status'], ['ACTIVE','INACTIVE'], true))             $errors[] = 'Invalid status.';

    if ($errors) return [$errors, $values];

    try {
        if ($existing) {
            db()->prepare(
                'UPDATE suppliers
                    SET code=?, name=?, contact_person=?, phone=?, email=?, address=?,
                        tax_no=?, payment_terms=?, status=?, notes=?
                  WHERE id=? AND company_id=?'
            )->execute([
                $values['code'], $values['name'], $values['contact_person'] ?: null,
                $values['phone'] ?: null, $values['email'] ?: null, $values['address'] ?: null,
                $values['tax_no'] ?: null, $values['payment_terms'] ?: null,
                $values['status'], $values['notes'] ?: null,
                $existing['id'], company_id(),
            ]);
            audit_log('supplier_update', 'suppliers', (int)$existing['id'], ['code' => $values['code']]);
        } else {
            db()->prepare(
                'INSERT INTO suppliers
                   (company_id, code, name, contact_person, phone, email, address,
                    tax_no, payment_terms, status, notes)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                company_id(), $values['code'], $values['name'],
                $values['contact_person'] ?: null, $values['phone'] ?: null, $values['email'] ?: null,
                $values['address'] ?: null, $values['tax_no'] ?: null, $values['payment_terms'] ?: null,
                $values['status'], $values['notes'] ?: null,
            ]);
            audit_log('supplier_create', 'suppliers', (int)db()->lastInsertId(), ['code' => $values['code']]);
        }
    } catch (PDOException $e) {
        $errors[] = (int)($e->errorInfo[1] ?? 0) === 1062
            ? 'Code already exists.' : 'Database error.';
    }
    return [$errors, $values];
}
