<?php
/**
 * Hot Tub Controller - Legacy PHP API
 * Basic API endpoints for authentication and admin functions
 */

// Load configuration
$config = json_decode(file_get_contents(__DIR__ . '/config.json'), true);
$tokens = json_decode(file_get_contents(__DIR__ . '/tokens.json'), true);

// Set CORS headers
header('Access-Control-Allow-Origin: ' . implode(', ', $config['cors']['allowed_origins']));
header('Access-Control-Allow-Methods: ' . implode(', ', $config['cors']['allowed_methods']));
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get request data
$input = json_decode(file_get_contents('php://input'), true);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Debug logging
if ($config['debug']) {
    error_log("🔧 Hot Tub Proxy: " . $_SERVER['REQUEST_METHOD'] . " $path");
    error_log("🔧 Input: " . json_encode($input));
}

// Route requests
try {
    switch ($path) {
        case '/api/v1/auth':
            handleAuth($input, $config);
            break;
            
            
        case '/api/v1/admin/user':
            handleAdminUserCreate($input, $config, $tokens);
            break;
            
        case '/api/v1/admin/users':
            handleAdminUserList($input, $config, $tokens);
            break;
            
        case '/':
        case '/index.php':
            handleStatus();
            break;
            
        default:
            http_response_code(404);
            echo json_encode(['error' => 'Endpoint not found']);
            break;
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
    if ($config['debug']) {
        error_log("🚨 Error: " . $e->getMessage());
    }
}

/**
 * Status endpoint - shows proxy is running
 */
function handleStatus() {
    echo json_encode([
        'service' => 'Hot Tub Controller PHP Proxy',
        'version' => '1.0.0',
        'status' => 'running',
        'timestamp' => date('c')
    ]);
}

/**
 * Simple master password authentication
 */
function handleAuth($input, $config) {
    if (!isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Password required']);
        return;
    }
    
    if (password_verify($input['password'], $config['auth']['master_password_hash'])) {
        echo json_encode([
            'authenticated' => true,
            'message' => 'Master authentication successful'
        ]);
    } else {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid password']);
    }
}


/**
 * Admin endpoint - create new user token
 */
function handleAdminUserCreate($input, $config, $tokens) {
    // Verify master password
    if (!isset($input['master_password']) || 
        !password_verify($input['master_password'], $config['auth']['master_password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid master password']);
        return;
    }
    
    if (!isset($input['name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'name required']);
        return;
    }
    
    // Generate new token
    $userId = 'usr_' . bin2hex(random_bytes(3));
    $token = 'tk_' . bin2hex(random_bytes(8));
    
    $newUser = [
        'id' => $userId,
        'token' => $token,
        'name' => $input['name'],
        'created' => date('c'),
        'active' => true,
        'last_used' => null
    ];
    
    $tokens['tokens'][] = $newUser;
    file_put_contents(__DIR__ . '/tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));
    
    echo json_encode([
        'token' => $token,
        'user_id' => $userId,
        'created' => $newUser['created']
    ]);
}

/**
 * Admin endpoint - list all users
 */
function handleAdminUserList($input, $config, $tokens) {
    // Verify master password (from query param for GET request)
    $masterPassword = $_GET['master_password'] ?? null;
    if (!$masterPassword || !password_verify($masterPassword, $config['auth']['master_password_hash'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Invalid master password']);
        return;
    }
    
    // Return user list (without sensitive data)
    $users = array_map(function($user) {
        return [
            'id' => $user['id'],
            'name' => $user['name'],
            'created' => $user['created'],
            'active' => $user['active'],
            'last_used' => $user['last_used'],
            'token_preview' => substr($user['token'], 0, 6) . '...'
        ];
    }, $tokens['tokens']);
    
    echo json_encode(['users' => $users]);
}

/**
 * Check if token is valid and active
 */
function isValidToken($token, $tokens) {
    foreach ($tokens['tokens'] as $user) {
        if ($user['token'] === $token && $user['active']) {
            return true;
        }
    }
    return false;
}

/**
 * Update token last_used timestamp
 */
function updateTokenLastUsed($token) {
    $tokens = json_decode(file_get_contents(__DIR__ . '/tokens.json'), true);
    
    foreach ($tokens['tokens'] as &$user) {
        if ($user['token'] === $token) {
            $user['last_used'] = date('c');
            break;
        }
    }
    
    file_put_contents(__DIR__ . '/tokens.json', json_encode($tokens, JSON_PRETTY_PRINT));
}

