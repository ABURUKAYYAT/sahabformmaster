# Data Isolation Guidelines for SahabFormMaster

## Overview
This document outlines the security and implementation guidelines for maintaining proper data isolation in the multi-tenant SahabFormMaster application. All admin pages must implement school-based data isolation to prevent cross-school data leakage.

## Core Principles

### 1. Authentication & Authorization
- **Always** call `require_school_auth()` at the start of admin pages
- This function returns the current user's school_id or handles super admin access
- Never proceed without proper authentication

### 2. Database Queries
- **All SELECT queries** must filter by `school_id`
- **All INSERT/UPDATE/DELETE operations** must include school validation
- Use parameterized queries to prevent SQL injection

### 3. Data Ownership Validation
- Validate record ownership before displaying sensitive data
- Use `validate_record_ownership($table, $id, $school_id)` for ownership checks
- Implement proper error handling for unauthorized access

## Implementation Templates

### Admin Page Template
```php
<?php
session_start();
require_once '../config/db.php';
require_once '../includes/functions.php';

// 1. Authentication & School Context
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();

// 2. School-Filtered Data Retrieval
$students = get_school_students($pdo, $current_school_id);
$classes = get_school_classes($pdo, $current_school_id);
$teachers = get_school_teachers($pdo, $current_school_id);

// 3. Operations with Validation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update_record') {
        $record_id = intval($_POST['id'] ?? 0);

        // Validate ownership
        if (!validate_record_ownership('target_table', $record_id, $current_school_id)) {
            $errors[] = 'Access denied or record not found.';
        } else {
            // Safe to proceed with update
            $stmt = $pdo->prepare("UPDATE target_table SET column = ? WHERE id = ? AND school_id = ?");
            $stmt->execute([$value, $record_id, $current_school_id]);
        }
    }
}
?>
```

### AJAX Endpoint Template
```php
<?php
session_start();
require_once '../../config/db.php';
require_once '../../includes/functions.php';

// 1. Authentication
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'principal') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// 2. School Context
$current_school_id = require_school_auth();

// 3. Validate Request Parameters
$record_id = intval($_GET['id'] ?? 0);
if ($record_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

// 4. School Ownership Validation
if (!validate_record_ownership('target_table', $record_id, $current_school_id)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

// 5. Process Request
// ... safe operations here
?>
```

## Helper Functions Reference

### Authentication Functions
- `require_school_auth()` - Get current school_id with authentication
- `get_current_school_id()` - Get school_id without authentication check
- `validate_school_access($school_id)` - Check if user can access specific school

### Data Retrieval Functions
- `get_school_students($pdo, $school_id, $limit, $class_id)` - Get filtered students
- `get_school_classes($pdo, $school_id)` - Get filtered classes
- `get_school_teachers($pdo, $school_id)` - Get filtered teachers
- `get_school_subjects($pdo, $school_id)` - Get filtered subjects
- `get_school_users($pdo, $school_id, $role)` - Get filtered users
- `get_school_filtered_options($table, $school_id, $columns, $order_by, $where_clause)` - Generic filtered options

### Validation Functions
- `validate_record_ownership($table, $id, $school_id, $id_column)` - Check record ownership
- `validate_bulk_school_access($table, $ids, $school_id, $id_column)` - Check bulk operation access
- `add_school_filter($query, &$params, $school_id)` - Add school filter to existing query

### Utility Functions
- `get_school_statistics($pdo, $school_id)` - Get school statistics safely
- `log_access_attempt($action, $resource_type, $resource_id, $details)` - Log security events

## Security Checklist

### For All Admin Pages
- [ ] `require_school_auth()` called at page start
- [ ] All database queries include `school_id` filtering
- [ ] Foreign key relationships validated for school ownership
- [ ] Error messages don't reveal sensitive information
- [ ] Session data validated before use

### For AJAX Endpoints
- [ ] Authentication checked before processing
- [ ] School ownership validated for all records
- [ ] Proper HTTP status codes returned
- [ ] JSON responses include success/error status

### For Bulk Operations
- [ ] All target records validated for school ownership
- [ ] Operations fail safely if any record lacks access
- [ ] Progress logged for audit trails
- [ ] Rollback mechanisms in place for failures

### For File Uploads/Downloads
- [ ] File paths include school_id for isolation
- [ ] Access permissions checked before serving files
- [ ] File operations logged for security audit

## Common Pitfalls & Solutions

### Pitfall 1: Missing School Filter in JOIN Queries
```php
// WRONG - Missing school filter in JOIN
$query = "SELECT * FROM students s JOIN classes c ON s.class_id = c.id WHERE s.school_id = ?";

// CORRECT - Filter both tables
$query = "SELECT * FROM students s JOIN classes c ON s.class_id = c.id AND c.school_id = ? WHERE s.school_id = ?";
```

### Pitfall 2: Trusting Client-Side Data
```php
// WRONG - Trusting POST data without validation
$student_id = $_POST['student_id'];
$query = "SELECT * FROM students WHERE id = ?";

// CORRECT - Validate ownership
$student_id = intval($_POST['student_id']);
if (!validate_record_ownership('students', $student_id, $current_school_id)) {
    die('Access denied');
}
$query = "SELECT * FROM students WHERE id = ? AND school_id = ?";
```

### Pitfall 3: Unfiltered Dropdown Options
```php
// WRONG - Shows all classes
$classes = $pdo->query("SELECT * FROM classes ORDER BY class_name")->fetchAll();

// CORRECT - School-filtered
$classes = get_school_classes($pdo, $current_school_id);
```

### Pitfall 4: Insecure Direct Object References
```php
// WRONG - Allows access to any school's data
$student_id = $_GET['id'];
$student = $pdo->query("SELECT * FROM students WHERE id = $student_id")->fetch();

// CORRECT - Validates ownership
$student_id = intval($_GET['id']);
if (!validate_record_ownership('students', $student_id, $current_school_id)) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}
```

## Testing Guidelines

### Unit Testing
- Test each function with different school contexts
- Verify super admin access works correctly
- Test error conditions and edge cases

### Integration Testing
- Test complete user workflows
- Verify cross-school data isolation
- Test bulk operations across school boundaries

### Security Testing
- Attempt IDOR (Insecure Direct Object Reference) attacks
- Test with manipulated school_id parameters
- Verify proper session handling

## Performance Considerations

### Query Optimization
- Use proper indexes on `school_id` columns
- Avoid N+1 query problems with JOINs
- Cache frequently accessed school-filtered data

### Database Design
- Ensure all tenant-specific tables have `school_id` columns
- Use foreign key constraints for data integrity
- Partition large tables by school_id if needed

## Monitoring & Maintenance

### Audit Logging
- Log all data access attempts
- Monitor for unusual access patterns
- Regular security audits

### Code Reviews
- All admin page changes require security review
- Use automated tools to detect security issues
- Maintain this checklist in pull request templates

## Emergency Procedures

### If Data Leak Suspected
1. Immediately isolate affected systems
2. Audit access logs for suspicious activity
3. Notify affected schools
4. Implement additional security measures
5. Conduct thorough security review

### Rollback Procedures
1. Identify all affected files
2. Revert to last known secure state
3. Test thoroughly before redeployment
4. Update security monitoring rules

## Support Resources

- Security Team: security@sahabformmaster.com
- Development Team: dev@sahabformmaster.com
- Documentation: https://docs.sahabformmaster.com/security

---

**Last Updated:** January 2026
**Version:** 1.0
**Review Cycle:** Monthly
