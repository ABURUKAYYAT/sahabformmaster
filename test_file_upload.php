<?php
// Test script to verify file upload validation
session_start();

// Include necessary files
require_once 'config/db.php';
require_once 'includes/security.php';

// Test different file types
$testFiles = [
    [
        'name' => 'test.jpg',
        'type' => 'image/jpeg',
        'tmp_name' => '/tmp/php' . uniqid(),
        'size' => 1024000
    ],
    [
        'name' => 'test.png',
        'type' => 'image/png',
        'tmp_name' => '/tmp/php' . uniqid(),
        'size' => 1024000
    ],
    [
        'name' => 'test.pdf',
        'type' => 'application/pdf',
        'tmp_name' => '/tmp/php' . uniqid(),
        'size' => 1024000
    ],
    [
        'name' => 'test.docx',
        'type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'tmp_name' => '/tmp/php' . uniqid(),
        'size' => 1024000
    ]
];

echo "<h2>File Upload Validation Test</h2>";
echo "<p>Testing file types that should be allowed:</p>";

foreach ($testFiles as $file) {
    echo "<h3>Testing: {$file['name']} ({$file['type']})</h3>";
    
    // Create a dummy file for testing
    file_put_contents($file['tmp_name'], "dummy content");
    
    // Test validation
    $validation = Security::validateFileUpload($file, array_merge(
        SecurityConfig::ALLOWED_IMAGE_TYPES, 
        SecurityConfig::ALLOWED_DOCUMENT_TYPES
    ));
    
    if ($validation['valid']) {
        echo "<p style='color: green;'>✅ PASS: File type is allowed</p>";
    } else {
        echo "<p style='color: red;'>❌ FAIL: " . $validation['error'] . "</p>";
    }
    
    // Clean up
    unlink($file['tmp_name']);
}

echo "<h3>Security Configuration:</h3>";
echo "<p>Allowed Image Types: " . implode(', ', SecurityConfig::ALLOWED_IMAGE_TYPES) . "</p>";
echo "<p>Allowed Document Types: " . implode(', ', SecurityConfig::ALLOWED_DOCUMENT_TYPES) . "</p>";

echo "<h3>Test Summary:</h3>";
echo "<p>The fix has been applied to admin/students.php. The uploadFile function now uses both image and document types for validation.</p>";
echo "<p>You can now upload:</p>";
echo "<ul>";
echo "<li>Images: JPG, JPEG, PNG, GIF, WebP (for passport photos)</li>";
echo "<li>Documents: PDF, DOC, DOCX, TXT (for birth certificates, transfer letters)</li>";
echo "</ul>";
?>