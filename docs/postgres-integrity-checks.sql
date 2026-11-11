-- Run only after connecting explicitly to splittrip_test.
-- This script is read-only and does not modify schema or data.

SELECT current_database() AS database_name, current_user AS database_role;

SELECT expected.table_name,
       to_regclass('public.' || expected.table_name) IS NOT NULL AS exists
FROM (VALUES
    ('users'), ('trips'), ('trip_members'), ('trip_invitations'),
    ('trip_access_otps'), ('expenses'), ('expense_splits'),
    ('expense_comments'), ('settlements'), ('settlement_transfers'),
    ('settlement_expense_snapshots'), ('budgets'), ('budget_categories'),
    ('audit_logs'), ('personal_access_tokens'), ('password_reset_tokens')
) AS expected(table_name)
ORDER BY expected.table_name;

SELECT trigger_name, event_manipulation, action_timing
FROM information_schema.triggers
WHERE event_object_schema = 'public'
  AND event_object_table = 'audit_logs'
ORDER BY trigger_name, event_manipulation;

SELECT conrelid::regclass AS table_name,
       conname AS constraint_name,
       contype AS constraint_type,
       convalidated AS is_validated
FROM pg_constraint
WHERE connamespace = 'public'::regnamespace
ORDER BY conrelid::regclass::text, conname;

-- Expected immutable audit trigger function.
SELECT to_regprocedure('public.splittrip_prevent_audit_log_mutation()') IS NOT NULL
       AS audit_immutability_function_exists;
