<?php
// Professional paper templates for TCPDF
session_start();
require_once '../includes/functions.php';

// Check if teacher is logged in
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'teacher') {
    header("Location: ../index.php");
    exit;
}
$current_school_id = require_school_auth();

class PaperTemplates {
    
    // Standard school template
    public static function getStandardTemplate($paper, $questions) {
        $template = [
            'header' => '
                <table width="100%" cellpadding="5" style="border-bottom: 2px solid #000;">
                    <tr>
                        <td width="15%" style="text-align: center;">
                            [LOGO_PLACEHOLDER]
                        </td>
                        <td width="70%" style="text-align: center;">
                            <h1 style="margin: 0; font-size: 18pt;">' . strtoupper($paper['school_name']) . '</h1>
                            <p style="margin: 2px 0; font-style: italic; font-size: 9pt;">' . $paper['motto'] . '</p>
                            <p style="margin: 2px 0; font-size: 8pt;">' . $paper['school_address'] . '</p>
                        </td>
                        <td width="15%" style="text-align: center;">
                            <div style="border: 1px solid #000; padding: 5px;">
                                <strong>Roll No.</strong><br>
                                <div style="height: 20px;"></div>
                            </div>
                        </td>
                    </tr>
                </table>
            ',
            
            'paper_info' => '
                <table width="100%" cellpadding="5" style="margin: 15px 0; border: 1px solid #000;">
                    <tr style="background-color: #f0f0f0;">
                        <th width="25%">SUBJECT</th>
                        <th width="25%">CLASS</th>
                        <th width="25%">TIME</th>
                        <th width="25%">MAX MARKS</th>
                    </tr>
                    <tr>
                        <td align="center">' . strtoupper($paper['subject_name']) . '</td>
                        <td align="center">' . strtoupper($paper['class_name']) . '</td>
                        <td align="center">' . $paper['time_allotted'] . ' minutes</td>
                        <td align="center">' . $paper['total_marks'] . '</td>
                    </tr>
                    <tr style="background-color: #f0f0f0;">
                        <th>PAPER CODE</th>
                        <th>DATE</th>
                        <th>TERM</th>
                        <th>SESSION</th>
                    </tr>
                    <tr>
                        <td align="center">' . $paper['paper_code'] . '</td>
                        <td align="center">' . date('d/m/Y') . '</td>
                        <td align="center">' . strtoupper(str_replace('_', ' ', $paper['exam_type'])) . '</td>
                        <td align="center">' . date('Y') . '</td>
                    </tr>
                </table>
            ',
            
            'instructions' => '
                <div style="border: 1px solid #000; padding: 15px; margin: 15px 0; background-color: #f9f9f9;">
                    <h3 style="margin: 0 0 10px 0; font-size: 11pt;">GENERAL INSTRUCTIONS:</h3>
                    <ul style="margin: 0; padding-left: 20px;">
                        <li>All questions are compulsory</li>
                        <li>Read each question carefully before answering</li>
                        <li>Write your answers neatly and legibly</li>
                        <li>Marks are indicated against each question</li>
                        <li>Use black or blue pen only</li>
                    </ul>
                </div>
            '
        ];
        
        return $template;
    }
    
    // Board exam pattern template
    public static function getBoardTemplate($paper, $questions) {
        $template = [
            'header' => '
                <table width="100%" cellpadding="5">
                    <tr>
                        <td width="100%" style="text-align: center; border-bottom: 2px solid #000;">
                            <h1 style="margin: 5px 0; font-size: 20pt; letter-spacing: 2px;">BOARD OF SECONDARY EDUCATION</h1>
                            <h2 style="margin: 5px 0; font-size: 16pt;">ANNUAL EXAMINATION ' . date('Y') . '</h2>
                            <h3 style="margin: 5px 0; font-size: 14pt;">' . strtoupper($paper['subject_name']) . ' - ' . strtoupper($paper['class_name']) . '</h3>
                        </td>
                    </tr>
                </table>
            ',
            
            'paper_info' => '
                <table width="100%" cellpadding="8" style="margin: 20px 0; border: 2px solid #000;">
                    <tr>
                        <td width="50%" style="border-right: 1px solid #000;">
                            <strong>Time:</strong> ' . $paper['time_allotted'] . ' minutes
                        </td>
                        <td width="50%">
                            <strong>Maximum Marks:</strong> ' . $paper['total_marks'] . '
                        </td>
                    </tr>
                    <tr>
                        <td style="border-right: 1px solid #000;">
                            <strong>Paper Code:</strong> ' . $paper['paper_code'] . '
                        </td>
                        <td>
                            <strong>Date:</strong> ' . date('d F, Y') . '
                        </td>
                    </tr>
                </table>
            ',
            
            'instructions' => '
                <div style="border-left: 4px solid #000; padding-left: 15px; margin: 20px 0;">
                    <h3 style="margin: 0 0 10px 0; font-size: 12pt;">INSTRUCTIONS TO CANDIDATES:</h3>
                    <ol style="margin: 0; padding-left: 20px;">
                        <li>Write your Roll Number in the space provided on the top of this page.</li>
                        <li>This question paper contains ' . count($questions) . ' questions.</li>
                        <li>Attempt all questions.</li>
                        <li>Marks allotted to each question are indicated against it.</li>
                        <li>Write your answers neatly and legibly in the answer-book provided.</li>
                        <li>Do not write anything on the question paper except your Roll Number.</li>
                    </ol>
                </div>
            '
        ];
        
        return $template;
    }
}
?>
