<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Access-Control-Allow-Credentials: true');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit(0);
}

class Config {
    private static $instance = null;
    private $settings = [];

    private function __construct() {
        $this->settings = [
            'ollama_url' => getenv('OLLAMA_URL') ?: 'http://localhost:11434',
            'ollama_timeout' => (int)(getenv('OLLAMA_TIMEOUT') ?: 120),
            'ollama_connect_timeout' => (int)(getenv('OLLAMA_CONNECT_TIMEOUT') ?: 10),
            'max_retries' => (int)(getenv('OLLAMA_MAX_RETRIES') ?: 3),
            'retry_delay' => (int)(getenv('OLLAMA_RETRY_DELAY') ?: 1000), // milliseconds
            'debug_mode' => getenv('DEBUG_MODE') === 'true',
            'log_errors' => getenv('LOG_ERRORS') !== 'false',
            'allowed_origins' => explode(',', getenv('ALLOWED_ORIGINS') ?: '*'),
        ];
    }

    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key, $default = null) {
        return $this->settings[$key] ?? $default;
    }

    public function isAllowedOrigin($origin) {
        if (in_array('*', $this->settings['allowed_origins'])) {
            return true;
        }
        return in_array($origin, $this->settings['allowed_origins']);
    }
}

class Logger {
    private static $logFile = __DIR__ . '/logs/ollama_api.log';

    public static function log($message, $level = 'INFO', $context = []) {
        if (!Config::getInstance()->get('log_errors') && $level !== 'ERROR') {
            return;
        }

        $timestamp = date('Y-m-d H:i:s');
        $logEntry = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            $level,
            $message,
            !empty($context) ? json_encode($context) : ''
        );

        $logDir = dirname(self::$logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        file_put_contents(self::$logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    public static function error($message, $context = []) {
        self::log($message, 'ERROR', $context);
    }

    public static function info($message, $context = []) {
        self::log($message, 'INFO', $context);
    }

    public static function debug($message, $context = []) {
        if (Config::getInstance()->get('debug_mode')) {
            self::log($message, 'DEBUG', $context);
        }
    }
}

class Validator {
    public static function sanitizeString($input, $maxLength = 10000) {
        $input = trim($input);
        $input = filter_var($input, FILTER_SANITIZE_STRING, FILTER_FLAG_NO_ENCODE_QUOTES);
        return substr($input, 0, $maxLength);
    }

    public static function validateModelName($model) {
        return preg_match('/^[a-zA-Z0-9._:-]+$/', $model) && strlen($model) <= 100;
    }

    public static function validateMessages($messages) {
        if (!is_array($messages) || empty($messages)) {
            return false;
        }

        foreach ($messages as $message) {
            if (!is_array($message) || !isset($message['role']) || !isset($message['content'])) {
                return false;
            }

            if (!in_array($message['role'], ['user', 'assistant', 'system'])) {
                return false;
            }

            if (!is_string($message['content']) || strlen($message['content']) > 50000) {
                return false;
            }
        }

        return true;
    }

    public static function validateAction($action) {
        return in_array($action, ['status', 'models', 'chat', 'chat_stream']);
    }
}

class HttpClient {
    private $config;

    public function __construct() {
        $this->config = Config::getInstance();
    }

    public function request($url, $options = []) {
        $maxRetries = $this->config->get('max_retries');
        $retryDelay = $this->config->get('retry_delay');

        $lastException = null;

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                Logger::debug("HTTP Request attempt {$attempt}/{$maxRetries}", ['url' => $url]);

                $ch = curl_init();

                curl_setopt($ch, CURLOPT_URL, $url);

                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, $this->config->get('ollama_timeout'));
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->config->get('ollama_connect_timeout'));
                curl_setopt($ch, CURLOPT_USERAGENT, 'Ollama-Chat-API/2.0.0');
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'User-Agent: Ollama-Chat-API/2.0.0'
                ]);

                foreach ($options as $option => $value) {
                    curl_setopt($ch, $option, $value);
                }

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $error = curl_error($ch);
                $errno = curl_errno($ch);

                curl_close($ch);

                if ($errno) {
                    throw new Exception("cURL Error ({$errno}): {$error}");
                }

                Logger::debug("HTTP Response", [
                    'http_code' => $httpCode,
                    'response_length' => strlen($response)
                ]);

                return [
                    'success' => true,
                    'http_code' => $httpCode,
                    'response' => $response,
                    'attempt' => $attempt
                ];

            } catch (Exception $e) {
                $lastException = $e;
                Logger::error("HTTP Request failed (attempt {$attempt}/{$maxRetries})", [
                    'error' => $e->getMessage(),
                    'url' => $url
                ]);

                if ($attempt < $maxRetries) {
                    usleep($retryDelay * 1000); // Convert milliseconds to microseconds
                }
            }
        }

        Logger::error("HTTP Request failed after {$maxRetries} attempts", [
            'final_error' => $lastException->getMessage(),
            'url' => $url
        ]);

        return [
            'success' => false,
            'error' => $lastException->getMessage(),
            'attempts' => $maxRetries
        ];
    }
}

class OllamaAPI {
    private $baseUrl;
    private $httpClient;

    public function __construct() {
        $config = Config::getInstance();
        $this->baseUrl = rtrim($config->get('ollama_url'), '/');
        $this->httpClient = new HttpClient();

        Logger::info("OllamaAPI initialized", ['base_url' => $this->baseUrl]);
    }

    public function checkStatus() {
        try {
            $result = $this->httpClient->request("{$this->baseUrl}/api/tags", [
                CURLOPT_TIMEOUT => 5, // Shorter timeout for status checks
                CURLOPT_CONNECTTIMEOUT => 3
            ]);

            if (!$result['success']) {
                Logger::error("Status check failed", ['error' => $result['error']]);
                return false;
            }

            $isOnline = $result['http_code'] === 200;
            Logger::info("Status check result", ['online' => $isOnline, 'http_code' => $result['http_code']]);
            return $isOnline;

        } catch (Exception $e) {
            Logger::error("Status check exception", ['error' => $e->getMessage()]);
            return false;
        }
    }

    public function getModels() {
        try {
            $result = $this->httpClient->request("{$this->baseUrl}/api/tags");

            if (!$result['success']) {
                Logger::error("Failed to fetch models", ['error' => $result['error']]);
                return [];
            }

            if ($result['http_code'] !== 200) {
                Logger::error("Models API returned non-200 status", ['http_code' => $result['http_code']]);
                return [];
            }

            $data = json_decode($result['response'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::error("Failed to parse models JSON", ['json_error' => json_last_error_msg()]);
                return [];
            }

            $models = $data['models'] ?? [];
            Logger::info("Retrieved models", ['count' => count($models)]);

            $formattedModels = [];
            foreach ($models as $model) {
                if (isset($model['name']) && isset($model['size'])) {
                    $formattedModels[] = [
                        'name' => Validator::sanitizeString($model['name'], 200),
                        'size' => round($model['size'] / 1024 / 1024, 2) . ' MB'
                    ];
                }
            }

            return $formattedModels;

        } catch (Exception $e) {
            Logger::error("Exception in getModels", ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function sendChat($model, $messages, $useMemory = true) {
        if (!Validator::validateModelName($model)) {
            throw new Exception('Invalid model name');
        }

        if (!Validator::validateMessages($messages)) {
            throw new Exception('Invalid message format');
        }

        $messagesToSend = $messages;
        if (!$useMemory && count($messages) > 1) {
            $messagesToSend = [end($messages)]; // Only send last message
        }

        $payload = [
            'model' => $model,
            'messages' => $messagesToSend,
            'stream' => false,
            'options' => [
                'temperature' => 0.7,
                'top_p' => 0.9,
                'top_k' => 40,
                'num_ctx' => 16384,
                'num_predict' => -1  // -1 means unlimited
            ]
        ];

        Logger::info("Sending chat request", [
            'model' => $model,
            'message_count' => count($messagesToSend),
            'use_memory' => $useMemory
        ]);

        $result = $this->httpClient->request("{$this->baseUrl}/api/chat", [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ]
        ]);

        if (!$result['success']) {
            throw new Exception("Connection failed: {$result['error']}");
        }

        if ($result['http_code'] !== 200) {
            throw new Exception("Ollama API error: HTTP {$result['http_code']}");
        }

        $response = json_decode($result['response'], true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response from Ollama');
        }

        if (isset($response['error'])) {
            throw new Exception("Ollama error: {$response['error']}");
        }

        $content = $response['message']['content'] ?? '';
        Logger::info("Chat response received", [
            'content_length' => strlen($content),
            'has_error' => isset($response['error'])
        ]);

        return $content;
    }

    public function sendStreamingChat($model, $messages, $useMemory = true) {
        if (!Validator::validateModelName($model)) {
            echo "data: " . json_encode(['error' => 'Invalid model name']) . "\n\n";
            return;
        }

        if (!Validator::validateMessages($messages)) {
            echo "data: " . json_encode(['error' => 'Invalid message format']) . "\n\n";
            return;
        }

        $messagesToSend = $messages;
        if (!$useMemory && count($messages) > 1) {
            $messagesToSend = [end($messages)];
        }

        $payload = [
            'model' => $model,
            'messages' => $messagesToSend,
            'stream' => true,
            'options' => [
                'temperature' => 0.7,
                'top_p' => 0.9,
                'top_k' => 40,
                'num_ctx' => 16384,
                'num_predict' => -1  // -1 means unlimited
            ]
        ];

        Logger::info("Starting streaming chat", [
            'model' => $model,
            'message_count' => count($messagesToSend),
            'use_memory' => $useMemory
        ]);

        header('Content-Type: text/plain');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('Access-Control-Allow-Origin: ' . ($_SERVER['HTTP_ORIGIN'] ?? '*'));
        header('Access-Control-Allow-Credentials: true');

        if (ob_get_level()) {
            ob_end_clean();
        }

        $config = Config::getInstance();
        $ch = curl_init("{$this->baseUrl}/api/chat");
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_WRITEFUNCTION => [$this, 'streamCallback'],
            CURLOPT_TIMEOUT => $config->get('ollama_timeout'),
            CURLOPT_CONNECTTIMEOUT => $config->get('ollama_connect_timeout')
        ]);

        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($httpCode !== 200) {
            Logger::error("Streaming failed", ['http_code' => $httpCode, 'error' => $error]);
            echo "data: " . json_encode(['error' => "HTTP {$httpCode}: {$error}"]) . "\n\n";
        }

        echo "data: [DONE]\n\n";
        flush();
    }

    private function streamCallback($ch, $data) {
        static $buffer = '';

        $buffer .= $data;
        $lines = explode("\n", $buffer);

        $buffer = array_pop($lines);

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;

            $json = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                Logger::debug("Skipping invalid JSON line", ['line' => substr($line, 0, 100)]);
                continue;
            }

            if (isset($json['error'])) {
                Logger::error("Stream error received", ['error' => $json['error']]);
                echo "data: " . json_encode(['error' => $json['error']]) . "\n\n";
                flush();
                return strlen($data);
            }

            if (isset($json['message']['content'])) {
                $content = $json['message']['content'];
                echo "data: " . json_encode(['content' => $content]) . "\n\n";
                flush();
            }

            if (isset($json['done']) && $json['done']) {
                Logger::debug("Stream completed");
                return strlen($data);
            }
        }

        return strlen($data);
    }
}

class APIResponse {
    public static function success($data = null, $message = null) {
        $response = ['success' => true];
        if ($data !== null) $response['data'] = $data;
        if ($message) $response['message'] = $message;

        Logger::info("API Success response", ['has_data' => $data !== null]);
        echo json_encode($response);
    }

    public static function error($message, $code = 400, $details = null) {
        http_response_code($code);

        $response = [
            'success' => false,
            'error' => $message,
            'timestamp' => date('c')
        ];

        if ($details && Config::getInstance()->get('debug_mode')) {
            $response['details'] = $details;
        }

        Logger::error("API Error response", [
            'message' => $message,
            'code' => $code,
            'has_details' => $details !== null
        ]);

        echo json_encode($response);
    }
}

try {
    $config = Config::getInstance();
    $api = new OllamaAPI();

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if (!$config->isAllowedOrigin($origin)) {
        APIResponse::error('Origin not allowed', 403);
        exit;
    }

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $action = $_GET['action'] ?? '';

        if (!Validator::validateAction($action)) {
            APIResponse::error('Invalid action parameter', 400);
            exit;
        }

        switch ($action) {
            case 'status':
                $status = $api->checkStatus();
                echo json_encode(['status' => $status ? 'online' : 'offline']);
                break;

            case 'models':
                $models = $api->getModels();
                echo json_encode([
                    'success' => true,
                    'models' => $models
                ]);
                break;

            default:
                APIResponse::error('Unknown GET action', 400);
        }

    } elseif ($method === 'POST') {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            APIResponse::error('Invalid JSON input', 400);
            exit;
        }

        if (!$data || !isset($data['action'])) {
            APIResponse::error('Missing action parameter', 400);
            exit;
        }

        $action = $data['action'];

        if (!Validator::validateAction($action)) {
            APIResponse::error('Invalid action parameter', 400);
            exit;
        }

        switch ($action) {
            case 'chat':
                if (empty($data['model']) || empty($data['messages'])) {
                    APIResponse::error('Model and messages are required', 400);
                    exit;
                }

                $model = Validator::sanitizeString($data['model'], 100);
                $messages = $data['messages'];
                $useMemory = $data['useMemory'] ?? true;

                try {
                    $response = $api->sendChat($model, $messages, $useMemory);
                    echo json_encode([
                        'success' => true,
                        'response' => $response
                    ]);
                } catch (Exception $e) {
                    Logger::error("Chat request failed", [
                        'error' => $e->getMessage(),
                        'model' => $model
                    ]);
                    APIResponse::error('Failed to process chat request: ' . $e->getMessage(), 500);
                }
                break;

            case 'chat_stream':
                if (empty($data['model']) || empty($data['messages'])) {
                    APIResponse::error('Model and messages are required', 400);
                    exit;
                }

                $model = Validator::sanitizeString($data['model'], 100);
                $messages = $data['messages'];
                $useMemory = $data['useMemory'] ?? true;

                try {
                    $api->sendStreamingChat($model, $messages, $useMemory);
                } catch (Exception $e) {
                    Logger::error("Streaming chat failed", [
                        'error' => $e->getMessage(),
                        'model' => $model
                    ]);
                    echo "data: " . json_encode(['error' => 'Streaming failed: ' . $e->getMessage()]) . "\n\n";
                }
                break;

            default:
                APIResponse::error('Unknown POST action', 400);
        }

    } else {
        APIResponse::error('Method not allowed', 405);
    }

} catch (Throwable $e) {
    Logger::error("Unhandled exception", [
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ]);

    APIResponse::error('Internal server error', 500);
}
?>
