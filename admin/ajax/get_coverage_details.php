<?php
// admin/ajax/get_coverage_details.php
session_start();
require_once '../../config/db.php';
require_once '../../includes/functions.php';

// Only allow principals to access this
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'principal') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get current school for data isolation
$current_school_id = require_school_auth();

$coverage_id = intval($_GET['id'] ?? 0);

if ($coverage_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid coverage ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT cc.*,
               s.subject_name, cl.class_name,
               t.full_name as teacher_name,
               p.full_name as principal_name
        FROM content_coverage cc
        JOIN subjects s ON cc.subject_id = s.id AND s.school_id = ?
        JOIN classes cl ON cc.class_id = cl.id AND cl.school_id = ?
        JOIN users t ON cc.teacher_id = t.id AND t.school_id = ?
        LEFT JOIN users p ON cc.principal_id = p.id
        WHERE cc.id = ?
    ");
    $stmt->execute([$current_school_id, $current_school_id, $current_school_id, $coverage_id]);
    $coverage = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$coverage) {
        echo json_encode(['success' => false, 'message' => 'Coverage entry not found or access denied']);
        exit;
    }

    // Build HTML response
    $html = '
        <div class="detail-grid">
            <div class="detail-item">
                <div class="detail-label">Teacher</div>
                <div class="detail-value">' . htmlspecialchars($coverage['teacher_name']) . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Subject</div>
                <div class="detail-value">' . htmlspecialchars($coverage['subject_name']) . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Class</div>
                <div class="detail-value">' . htmlspecialchars($coverage['class_name']) . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Term</div>
                <div class="detail-value">' . htmlspecialchars($coverage['term']) . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Week</div>
                <div class="detail-value">' . ($coverage['week'] > 0 ? 'Week ' . $coverage['week'] : 'Not specified') . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Date Covered</div>
                <div class="detail-value">' . date('F d, Y', strtotime($coverage['date_covered'])) . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Time Period</div>
                <div class="detail-value">' .
                    ($coverage['time_start'] ? date('H:i', strtotime($coverage['time_start'])) : 'Not specified') .
                    ' - ' .
                    ($coverage['time_end'] ? date('H:i', strtotime($coverage['time_end'])) : 'Not specified') .
                '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Period</div>
                <div class="detail-value">' . htmlspecialchars($coverage['period'] ?: 'Not specified') . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Status</div>
                <div class="detail-value">
                    <span class="status-badge status-' . $coverage['status'] . '">' .
                        ucfirst(str_replace('_', ' ', $coverage['status'])) .
                    '</span>
                </div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Submitted</div>
                <div class="detail-value">' . date('F d, Y H:i', strtotime($coverage['submitted_at'])) . '</div>
            </div>';

    if ($coverage['approved_at']) {
        $html .= '
            <div class="detail-item">
                <div class="detail-label">Approved By</div>
                <div class="detail-value">' . htmlspecialchars($coverage['principal_name'] ?: 'Unknown') . '</div>
            </div>
            <div class="detail-item">
                <div class="detail-label">Approved At</div>
                <div class="detail-value">' . date('F d, Y H:i', strtotime($coverage['approved_at'])) . '</div>
            </div>';
    }

    $html .= '</div>';

    // Topics covered
    $html .= '
        <div class="detail-item">
            <div class="detail-label">Topics Covered</div>
            <div class="topics-preview">' . nl2br(htmlspecialchars($coverage['topics_covered'])) . '</div>
        </div>';

    // Additional details
    if ($coverage['objectives_achieved']) {
        $html .= '
            <div class="detail-item">
                <div class="detail-label">Objectives Achieved</div>
                <div class="detail-value">' . nl2br(htmlspecialchars($coverage['objectives_achieved'])) . '</div>
            </div>';
    }

    if ($coverage['resources_used']) {
        $html .= '
            <div class="detail-item">
                <div class="detail-label">Resources Used</div>
                <div class="detail-value">' . nl2br(htmlspecialchars($coverage['resources_used'])) . '</div>
            </div>';
    }

    if ($coverage['assessment_done']) {
        $html .= '
            <div class="detail-item">
                <div class="detail-label">Assessment Done</div>
                <div class="detail-value">' . nl2br(htmlspecialchars($coverage['assessment_done'])) . '</div>
            </div>';
    }

    if ($coverage['challenges']) {
        $html .= '
            <div class="detail-item">
                <div class="detail-label">Challenges Faced</div>
                <div class="detail-value">' . nl2br(htmlspecialchars($coverage['challenges'])) . '</div>
            </div>';
    }

    if ($coverage['notes']) {
        $html .= '
            <div class="detail-item">
                <div class="detail-label">Additional Notes</div>
                <div class="detail-value">' . nl2br(htmlspecialchars($coverage['notes'])) . '</div>
            </div>';
    }

    if ($coverage['principal_comments']) {
        $html .= '
            <div class="detail-item">
                <div class="detail-label">Principal Comments</div>
                <div class="detail-value" style="background: var(--gray-50); padding: 1rem; border-radius: 8px; border-left: 4px solid var(--primary-500);">' .
                    nl2br(htmlspecialchars($coverage['principal_comments'])) .
                '</div>
            </div>';
    }

    echo json_encode([
        'success' => true,
        'html' => $html,
        'coverage' => $coverage
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
