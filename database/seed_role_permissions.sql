START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS _rbac_role_seed_guard;
CREATE TEMPORARY TABLE _rbac_role_seed_guard (
    role_code VARCHAR(50) NOT NULL PRIMARY KEY,
    role_id INT NOT NULL
);

INSERT INTO _rbac_role_seed_guard (role_code, role_id)
SELECT expected.role_code, r.id
FROM (
    SELECT 'ADMIN' AS role_code
    UNION ALL SELECT 'CREATOR'
    UNION ALL SELECT 'KEEPER'
    UNION ALL SELECT 'VIEWER'
) expected
LEFT JOIN roles r ON r.role_code = expected.role_code;

DROP TEMPORARY TABLE IF EXISTS _rbac_permission_seed_guard;
CREATE TEMPORARY TABLE _rbac_permission_seed_guard (
    permission_code VARCHAR(100) NOT NULL PRIMARY KEY,
    permission_id INT NOT NULL
);

INSERT INTO _rbac_permission_seed_guard (permission_code, permission_id)
SELECT expected.permission_code, p.id
FROM (
    SELECT 'products.view' AS permission_code
    UNION ALL SELECT 'products.manage'
    UNION ALL SELECT 'prices.manage'
    UNION ALL SELECT 'documents.create'
    UNION ALL SELECT 'documents.post'
    UNION ALL SELECT 'documents.cancel'
    UNION ALL SELECT 'documents.print'
    UNION ALL SELECT 'rbac.manage'
    UNION ALL SELECT 'tables.view'
    UNION ALL SELECT 'tables.open'
    UNION ALL SELECT 'tables.manage'
    UNION ALL SELECT 'kds.view'
    UNION ALL SELECT 'kds.update'
    UNION ALL SELECT 'pos.view'
    UNION ALL SELECT 'pos.checkout'
    UNION ALL SELECT 'reports.view'
    UNION ALL SELECT 'data.manage'
) expected
LEFT JOIN permissions p ON p.permission_code = expected.permission_code;

DELETE FROM role_permissions
WHERE role_id IN (SELECT role_id FROM _rbac_role_seed_guard);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.role_id, p.permission_id
FROM _rbac_role_seed_guard r
CROSS JOIN _rbac_permission_seed_guard p
WHERE r.role_code = 'ADMIN'
   OR (r.role_code = 'CREATOR' AND p.permission_code IN (
        'products.view', 'tables.view', 'tables.open', 'kds.view'
   ))
   OR (r.role_code = 'KEEPER' AND p.permission_code IN (
        'kds.view', 'kds.update'
   ))
   OR (r.role_code = 'VIEWER' AND p.permission_code IN (
        'documents.print', 'pos.view', 'pos.checkout', 'reports.view'
   ));

DROP TEMPORARY TABLE _rbac_permission_seed_guard;
DROP TEMPORARY TABLE _rbac_role_seed_guard;
COMMIT;
