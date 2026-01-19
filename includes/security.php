<?php
/**
 * Security Framework for SahabFormMaster
 * Provides centralized security functions and utilities
 */

// Prevent direct access
if (!defined('SECURE_ACCESS')) {
    die('Direct access not permitted');
}

/**
 * Environment Configuration Loader
 */
class Env {
    private static $loaded = false;

    public static function load($path = null) {
        if (self::$loaded) {
            return;
        }

        $path = $path ?? __DIR__ . '/../.env';

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if (!array_key_exists($name, $_SERVER) && !array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }

        self::$loaded = true;
    }

    public static function get($key, $default = null) {
        return getenv($key) ?: $default;
    }
}

// Load environment variables
Env::load();

/**
 * Security Configuration
 */
class SecurityConfig {
    const MAX_LOGIN_ATTEMPTS = 5;
    const LOCKOUT_TIME = 900; // 15 minutes
    const SESSION_TIMEOUT = 3600; // 1 hour
    const MAX_FILE_SIZE = 5242880; // 5MB
    const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    const ALLOWED_DOCUMENT_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'text/plain'
    ];
}

/**
 * Input Validation and Sanitization
 */
class Security {
    /**
     * Sanitize string input
     */
    public static function sanitizeString($input, $maxLength = null) {
        if (!is_string($input)) {
            return '';
        }

        $sanitized = trim($input);

        if ($maxLength !== null && strlen($sanitized) > $maxLength) {
            $sanitized = substr($sanitized, 0, $maxLength);
        }

        return htmlspecialchars($sanitized, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Validate email format
     */
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate integer input
     */
    public static function validateInt($input, $min = null, $max = null) {
        $int = filter_var($input, FILTER_VALIDATE_INT);
        if ($int === false) {
            return false;
        }

        if ($min !== null && $int < $min) {
            return false;
        }

        if ($max !== null && $int > $max) {
            return false;
        }

        return $int;
    }

    /**
     * Validate and sanitize SQL input (for use with prepared statements)
     */
    public static function sanitizeSqlInput($input) {
        if (is_string($input)) {
            return trim($input);
        } elseif (is_numeric($input)) {
            return $input;
        } elseif (is_array($input)) {
            return array_map([self::class, 'sanitizeSqlInput'], $input);
        }
        return null;
    }

    /**
     * Generate secure random token
     */
    public static function generateToken($length = 32) {
        return bin2hex(random_bytes($length));
    }

    /**
     * Validate CSRF token
     */
    public static function validateCsrfToken($token) {
        return isset($_SESSION['csrf_token']) &&
               hash_equals($_SESSION['csrf_token'], $token);
    }

    /**
     * Generate and store CSRF token
     */
    public static function generateCsrfToken() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = self::generateToken();
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Secure file upload validation
     */
    public static function validateFileUpload($file, $allowedTypes = null, $maxSize = null) {
        if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid file upload'];
        }

        // Check file size
        $maxSize = $maxSize ?? SecurityConfig::MAX_FILE_SIZE;
        if ($file['size'] > $maxSize) {
            return ['valid' => false, 'error' => 'File size exceeds limit'];
        }

        // Check MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowedTypes = $allowedTypes ?? array_merge(
            SecurityConfig::ALLOWED_IMAGE_TYPES,
            SecurityConfig::ALLOWED_DOCUMENT_TYPES
        );

        if (!in_array($mimeType, $allowedTypes)) {
            return ['valid' => false, 'error' => 'File type not allowed'];
        }

        // Check for malicious file content (basic)
        if (self::isMaliciousFile($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Potentially malicious file detected'];
        }

        return ['valid' => true, 'mime_type' => $mimeType];
    }

    /**
     * Basic malicious file detection
     */
    private static function isMaliciousFile($filePath) {
        $content = file_get_contents($filePath);

        // Check for PHP code in files that shouldn't contain it
        if (preg_match('/<\?php/i', $content)) {
            return true;
        }

        // Check for script tags in non-HTML files
        if (!preg_match('/\.html?$/', $filePath) &&
            preg_match('/<script/i', $content)) {
            return true;
        }

        return false;
    }

    /**
     * Secure session regeneration
     */
    public static function regenerateSession() {
        if (!isset($_SESSION['regenerated'])) {
            session_regenerate_id(true);
            $_SESSION['regenerated'] = true;
        }
    }

    /**
     * Check session timeout
     */
    public static function checkSessionTimeout() {
        $timeout = SecurityConfig::SESSION_TIMEOUT;

        if (isset($_SESSION['last_activity']) &&
            (time() - $_SESSION['last_activity']) > $timeout) {
            session_unset();
            session_destroy();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * Rate limiting for login attempts
     */
    public static function checkLoginAttempts($identifier) {
        $key = "login_attempts_$identifier";

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'last_attempt' => 0];
        }

        $attempts = &$_SESSION[$key];

        // Reset if lockout period has passed
        if ((time() - $attempts['last_attempt']) > SecurityConfig::LOCKOUT_TIME) {
            $attempts['count'] = 0;
        }

        $attempts['last_attempt'] = time();
        $attempts['count']++;

        if ($attempts['count'] > SecurityConfig::MAX_LOGIN_ATTEMPTS) {
            return ['allowed' => false, 'wait_time' => SecurityConfig::LOCKOUT_TIME];
        }

        return ['allowed' => true];
    }

    /**
     * Reset login attempts after successful login
     */
    public static function resetLoginAttempts($identifier) {
        $key = "login_attempts_$identifier";
        unset($_SESSION[$key]);
    }

    /**
     * Secure output function
     */
    public static function secureOutput($data, $flags = ENT_QUOTES) {
        if (is_array($data)) {
            return array_map([self::class, 'secureOutput'], $data);
        }

        if (is_string($data)) {
            return htmlspecialchars($data, $flags, 'UTF-8');
        }

        return $data;
    }

    /**
     * Log security events
     */
    public static function logSecurityEvent($event, $details = []) {
        $logEntry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event' => $event,
            'user_id' => $_SESSION['user_id'] ?? 'unknown',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'details' => $details
        ];

        // In production, this should write to a secure log file
        error_log("SECURITY: " . json_encode($logEntry));
    }
}

/**
 * Database Security Wrapper
 */
class SecureDB {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Execute secure SELECT query
     */
    public function select($query, $params = [], $schoolFilter = true) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            Security::logSecurityEvent('database_error', ['error' => $e->getMessage(), 'query' => $query]);
            throw new Exception('Database error occurred');
        }
    }

    /**
     * Execute secure INSERT query
     */
    public function insert($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            Security::logSecurityEvent('database_error', ['error' => $e->getMessage(), 'query' => $query]);
            throw new Exception('Database error occurred');
        }
    }

    /**
     * Execute secure UPDATE query
     */
    public function update($query, $params = []) {
        try {
            $stmt = $this->pdo->prepare($query);
            $stmt->execute($params);
            return $stmt->rowCount();
        } catch (PDOException $e) {
            Security::logSecurityEvent('database_error', ['error' => $e->getMessage(), 'query' => $query]);
            throw new Exception('Database error occurred');
        }
    }
}

/**
 * Security Headers Middleware
 */
class SecurityHeaders {
    public static function setHeaders() {
        // Prevent clickjacking
        header('X-Frame-Options: SAMEORIGIN');

        // Prevent MIME type sniffing
        header('X-Content-Type-Options: nosniff');

        // Enable XSS protection
        header('X-XSS-Protection: 1; mode=block');

        // Referrer policy
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Content Security Policy (enhanced)
        header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://code.jquery.com https://cdnjs.cloudflare.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https: blob:; frame-ancestors 'self'; form-action 'self'; upgrade-insecure-requests");

        // HSTS (HTTP Strict Transport Security) - enabled for security
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

        // Permissions Policy (restrict potentially harmful features)
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), accelerometer=(), gyroscope=(), ambient-light-sensor=(), autoplay=(), encrypted-media=(), fullscreen=(self), picture-in-picture=()");

        // Cross-Origin Embedder Policy
        header('Cross-Origin-Embedder-Policy: require-corp');

        // Cross-Origin Opener Policy
        header('Cross-Origin-Opener-Policy: same-origin');

        // Cross-Origin Resource Policy
        header('Cross-Origin-Resource-Policy: same-origin');
    }
}

// Initialize security headers
SecurityHeaders::setHeaders();
?>
