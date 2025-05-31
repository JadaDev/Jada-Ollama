<?php
// Ollama Model Management Interface - Jada OLLAMA
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle different API endpoints
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'list':
        handleListModels();
        break;
    case 'download':
        handleDownloadModel();
        break;
    case 'remove':
        handleRemoveModel();
        break;
    case 'check_status':
        handleCheckStatus();
        break;
    default:
        showInterface();
        break;
}

// Helper function to send Server-Sent Events
function sendSSEEvent($name, $data) {
    echo "event: " . $name . "\n";
    echo "data: " . json_encode($data) . "\n\n";
    ob_flush();
    flush();
}

// Helper function to format bytes into human-readable size
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max(0, $bytes);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << ($pow * 10));
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function handleListModels() {
    header('Content-Type: application/json');
    
    // Use API approach like the status check (same method as ollama_api.php)
    $apiUrl = 'http://localhost:11434/api/tags';
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode !== 200) {
        $error_message = 'Ollama service is not running or not accessible.';
        if ($error) {
            $error_message .= ' Error: ' . $error;
        } else {
            $error_message .= ' HTTP ' . $httpCode;
        }
        echo json_encode(['error' => $error_message]);
        exit;
    }

    $data = json_decode($response, true);
    if (!$data || !isset($data['models'])) {
        echo json_encode(['error' => 'Invalid response from Ollama API']);
        exit;
    }

    $models = [];
    foreach ($data['models'] as $model) {
        $models[] = [
            'name' => $model['name'],
            'id' => substr($model['digest'], 0, 12) . '...',
            'size' => formatBytes($model['size']),
            'modified' => date('Y-m-d H:i:s', strtotime($model['modified_at']))
        ];
    }

    echo json_encode(['models' => $models]);
    exit;
}

function handleDownloadModel() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    header('Access-Control-Allow-Origin: *');

    $input = json_decode(file_get_contents('php://input'), true);
    $model = $input['model'] ?? $_GET['model'] ?? '';
    
    if (empty($model)) {
        sendSSEEvent('error', ['error' => 'Model name is required']);
        exit;
    }

    // First check if Ollama is accessible
    $ollama_url = 'http://localhost:11434/api/tags';
    $ch = curl_init($ollama_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        sendSSEEvent('error', ['error' => 'Cannot connect to Ollama service. Please ensure Ollama is running.']);
        exit;
    }

    // Now proceed with download
    $pull_url = 'http://localhost:11434/api/pull';
    $post_data = json_encode(['name' => $model]);

    sendSSEEvent('status', ['message' => "Starting download of $model..."]);

    $ch = curl_init($pull_url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($post_data)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1800);

    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) {
        static $buffer = '';
        $buffer .= $data;

        while (($newline_pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline_pos);
            $buffer = substr($buffer, $newline_pos + 1);

            if (empty(trim($line))) continue;

            $json_data = json_decode($line, true);

            if ($json_data === null) {
                sendSSEEvent('info', ['message' => trim($line)]);
                continue;
            }

            if (isset($json_data['error'])) {
                sendSSEEvent('error', ['error' => $json_data['error']]);
                return 0;
            }

            if (isset($json_data['status'])) {
                $status = $json_data['status'];
                $total = $json_data['total'] ?? 0;
                $completed = $json_data['completed'] ?? 0;
                
                $percentage = 0;
                if ($total > 0) {
                    $percentage = round(($completed / $total) * 100, 1);
                }

                $message = $status;
                if ($percentage > 0) {
                    $message .= " ($percentage%)";
                }

                sendSSEEvent('progress', [
                    'message' => $message,
                    'percentage' => $percentage,
                    'completed' => $completed,
                    'total' => $total
                ]);

                if ($status === 'success') {
                    sendSSEEvent('success', ['message' => "Model $model downloaded successfully!"]);
                }
            }
        }

        return strlen($data);
    });

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false || $http_code !== 200) {
        $error_message = $error ? "Connection Error: " . $error : "HTTP Error: " . $http_code;
        sendSSEEvent('error', ['error' => 'Download failed: ' . $error_message]);
    }

    exit;
}

function handleRemoveModel() {
    header('Content-Type: application/json');
    $model = $_POST['model'] ?? '';

    if (empty($model)) {
        echo json_encode(['error' => 'Model name is required']);
        exit;
    }

    // Use Ollama API to remove model (same approach as other functions)
    $deleteUrl = 'http://localhost:11434/api/delete';
    $postData = json_encode(['name' => $model]);
    
    $ch = curl_init($deleteUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($postData)
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 200) {
        echo json_encode(['success' => true, 'message' => "Model '$model' removed successfully"]);
    } else {
        $errorMessage = 'Failed to remove model';
        if ($error) {
            $errorMessage .= ': ' . $error;
        } else {
            $errorMessage .= ' (HTTP ' . $httpCode . ')';
        }
        
        // If API method fails, fallback to command line for Windows
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            $cmd = "ollama rm " . escapeshellarg($model) . " 2>&1";
            $output = shell_exec($cmd);
            
            if ($output && (strpos($output, 'error') === false && strpos($output, 'Error') === false && strpos($output, 'failed') === false && strpos($output, 'not found') === false)) {
                echo json_encode(['success' => true, 'message' => "Model '$model' removed successfully", 'method' => 'command']);
            } else {
                echo json_encode(['error' => $errorMessage . '. Command output: ' . trim($output)]);
            }
        } else {
            echo json_encode(['error' => $errorMessage]);
        }
    }
    exit;
}

function handleCheckStatus() {
    header('Content-Type: application/json');
    
    // Check if Ollama service is running (same method as index.php)
    $apiUrl = 'http://localhost:11434/api/tags';
    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($httpCode === 200) {
        // Ollama is running - try to get version info
        $version = 'Unknown';
        $ollamaPath = 'Unknown';
        
        // Try to get version (optional, don't fail if this doesn't work)
        $cmd = "ollama --version 2>&1";
        $output = @shell_exec($cmd);
        if ($output && strpos($output, 'ollama version') !== false) {
            $version = trim(str_replace('ollama version', '', $output));
        }
        
        // Try to get path (optional, don't fail if this doesn't work)
        $pathCmd = "where ollama 2>nul";
        if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
            $pathCmd = "which ollama 2>/dev/null";
        }
        $pathOutput = @shell_exec($pathCmd);
        if ($pathOutput && !empty(trim($pathOutput))) {
            $ollamaPath = trim($pathOutput);
        }
        
        echo json_encode([
            'running' => true,
            'installed' => true,
            'version' => $version,
            'path' => $ollamaPath
        ]);
    } else {
        // Ollama API is not accessible
        echo json_encode([
            'running' => false,
            'installed' => false, // We can't determine if it's installed if API is not accessible
            'error' => 'Ollama service is not running or not accessible.',
            'instructions' => [
                'Make sure Ollama is installed from https://ollama.ai',
                'Open a command prompt or terminal',
                'Run the command: ollama serve',
                'Keep the terminal open while using this interface',
                'Alternatively, set up Ollama as a system service'
            ],
            'curl_error' => $error ? $error : "HTTP $httpCode"
        ]);
    }
    exit;
}

function showInterface() {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ollama Model Manager - Jada OLLAMA</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <link rel="stylesheet" href="styles.css?v=1.0">
</head>
<body>
    <div class="header">
        <div class="header-left">
            <a href="index.php" class="header-btn" title="Back to Chat">
                <i class="fas fa-arrow-left"></i>
            </a>
            <div class="logo">
                <i class="fas fa-download"></i>
                Model Manager
            </div>
        </div>
        <div class="header-right">
            <div class="status">
                <div id="statusDot" class="status-dot"></div>
                <div id="statusText">Checking Ollama status...</div>
            </div>
            <div class="header-buttons">
                <a href="index.php" class="header-btn" title="Back to Chat">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <button class="header-btn" onclick="toggleTheme()" title="Toggle Theme">
                    <i id="themeIcon" class="fas fa-moon"></i>
                </button>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div id="alerts"></div>
        
        <!-- Ollama Status Message Container -->
        <div id="ollamaStatusContainer" style="display: none;">
            <div class="ollama-status-card">
                <div class="status-icon">
                    <i id="ollamaStatusIcon" class="fas fa-exclamation-circle"></i>
                </div>
                <div class="status-content">
                    <h3 id="ollamaStatusTitle">Checking Ollama...</h3>
                    <p id="ollamaStatusMessage">Please wait while we check your Ollama installation.</p>
                    <div id="ollamaInstructions" style="display: none;">
                        <h4>To get started:</h4>
                        <ol id="instructionsList"></ol>
                        <div class="command-section" id="commandSection" style="display: none;">
                            <h4>Quick Command:</h4>
                            <div class="command-box">
                                <code id="ollamaCommand">ollama serve</code>
                                <button class="copy-btn" onclick="copyCommand()" title="Copy command">
                                    <i class="fas fa-copy"></i>
                                </button>
                            </div>
                        </div>
                        <button class="primary-btn" onclick="checkOllamaStatus()" style="margin-top: 15px;">
                            <i class="fas fa-sync-alt"></i> Check Again
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('installed')">
                <i class="fas fa-box"></i> Installed Models
            </button>
            <button class="tab" onclick="switchTab('available')">
                <i class="fas fa-globe"></i> Available Models
            </button>
        </div>

        <div class="tab-content">
            <div id="installed" class="tab-pane active">
                <h2 style="margin-top: 0; color: var(--text-primary);">Installed Models</h2>
                <div id="installedModels" class="loading">
                    <div class="spinner"></div>
                    Loading installed models...
                </div>
            </div>

            <div id="available" class="tab-pane">
                <h2 style="margin-top: 0; color: var(--text-primary);">Available Models</h2>
                <div class="search-filters">
                    <div class="filter-group">
                        <input type="text" id="searchBox" placeholder="Search models..." onkeyup="filterModels()">
                        <select id="categoryFilter" onchange="filterModels()">
                            <option value="">All Categories</option>
                        </select>
                        <select id="sizeFilter" onchange="filterModels()">
                            <option value="">All Sizes</option>
                        </select>
                    </div>
                </div>
                <div id="availableModels" class="available-models"></div>
                <div class="model-stats" id="modelStats"></div>
            </div>
        </div>
    </div>

    <script>
        let installedModels = [];
        let downloadingModels = new Map();
        let ollamaRunning = false;

        const availableModels = [
            // Ultra-lightweight models (< 1GB)
            {
                name: "tinyllama",
                description: "TinyLlama - Ultra-lightweight model for basic tasks and testing",
                size: "~637MB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "fast", "basic", "testing"]
            },
            {
                name: "phi3:mini",
                description: "Microsoft Phi-3 Mini - Extremely efficient 3.8B parameter model",
                size: "~2.3GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "microsoft", "efficient", "reasoning"]
            },
            
            // Small models (1-5GB)
            {
                name: "deepseek-r1:1.5b",
                description: "DeepSeek R1 1.5B - Compact reasoning model with thinking capabilities",
                size: "~1.5GB",
                sizeCategory: "small",
                tags: ["chat", "reasoning", "thinking", "deepseek", "small"]
            },
            {
                name: "deepseek-r1:7b",
                description: "DeepSeek R1 7B - Advanced reasoning model with thinking capabilities",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "reasoning", "thinking", "deepseek", "popular"]
            },
            {
                name: "gemma2:2b",
                description: "Google Gemma 2 2B - Compact but capable model from Google",
                size: "~1.6GB",
                sizeCategory: "small",
                tags: ["chat", "small", "google", "efficient"]
            },
            {
                name: "qwen2.5:0.5b",
                description: "Qwen 2.5 0.5B - Ultra-compact Chinese-English bilingual model",
                size: "~394MB",
                sizeCategory: "tiny",
                tags: ["chat", "multilingual", "chinese", "tiny"]
            },
            {
                name: "qwen2.5:1.5b",
                description: "Qwen 2.5 1.5B - Small but powerful multilingual model",
                size: "~934MB",
                sizeCategory: "small",
                tags: ["chat", "multilingual", "chinese", "small"]
            },
            {
                name: "qwen2.5:3b",
                description: "Qwen 2.5 3B - Balanced performance and efficiency",
                size: "~1.9GB",
                sizeCategory: "small",
                tags: ["chat", "multilingual", "chinese", "balanced"]
            },
            {
                name: "qwen2.5:7b",
                description: "Qwen 2.5 7B - Strong multilingual capabilities",
                size: "~4.4GB",
                sizeCategory: "small",
                tags: ["chat", "multilingual", "chinese", "powerful"]
            },
            {
                name: "mistral",
                description: "Mistral 7B - Fast and efficient general-purpose model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "general", "fast", "popular"]
            },
            {
                name: "llama3.2:1b",
                description: "Meta Llama 3.2 1B - Compact version of latest Llama",
                size: "~1.3GB",
                sizeCategory: "small",
                tags: ["chat", "meta", "latest", "small"]
            },
            {
                name: "llama3.2:3b",
                description: "Meta Llama 3.2 3B - Balanced performance and size",
                size: "~2.0GB",
                sizeCategory: "small",
                tags: ["chat", "meta", "latest", "balanced"]
            },
            {
                name: "llama3.2",
                description: "Meta Llama 3.2 - Latest model with vision capabilities",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["chat", "vision", "meta", "latest", "multimodal"]
            },
            {
                name: "llama3:8b",
                description: "Meta Llama 3 8B - Excellent general-purpose model",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["chat", "general", "meta", "popular"]
            },
            {
                name: "llama3.1:8b",
                description: "Meta Llama 3.1 8B - Improved version with longer context",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["chat", "general", "meta", "long-context"]
            },
            {
                name: "gemma:7b",
                description: "Google Gemma 7B - Open model from Google",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "google", "open-source"]
            },
            {
                name: "gemma2:9b",
                description: "Google Gemma 2 9B - Enhanced version with better performance",
                size: "~5.4GB",
                sizeCategory: "small",
                tags: ["chat", "google", "enhanced"]
            },
            
            // Code models
            {
                name: "codellama:7b",
                description: "Code Llama 7B - Specialized for code generation and understanding",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["code", "programming", "meta", "development"]
            },
            {
                name: "codellama:13b",
                description: "Code Llama 13B - More capable code generation model",
                size: "~7.3GB",
                sizeCategory: "medium",
                tags: ["code", "programming", "meta", "powerful"]
            },
            {
                name: "codeqwen:7b",
                description: "CodeQwen 7B - Code-focused version of Qwen",
                size: "~4.2GB",
                sizeCategory: "small",
                tags: ["code", "programming", "multilingual"]
            },
            {
                name: "deepseek-coder:6.7b",
                description: "DeepSeek Coder 6.7B - Advanced coding assistant",
                size: "~3.7GB",
                sizeCategory: "small",
                tags: ["code", "programming", "deepseek"]
            },
            {
                name: "starcoder2:3b",
                description: "StarCoder2 3B - Compact code generation model",
                size: "~1.7GB",
                sizeCategory: "small",
                tags: ["code", "programming", "small"]
            },
            {
                name: "starcoder2:7b",
                description: "StarCoder2 7B - Powerful code generation model",
                size: "~4.0GB",
                sizeCategory: "small",
                tags: ["code", "programming", "powerful"]
            },
            
            // Medium models (5-15GB)
            {
                name: "llama3:70b",
                description: "Meta Llama 3 70B - Large, highly capable model",
                size: "~40GB",
                sizeCategory: "large",
                tags: ["chat", "large", "meta", "powerful"]
            },
            {
                name: "llama3.1:70b",
                description: "Meta Llama 3.1 70B - Enhanced large model with extended context",
                size: "~40GB",
                sizeCategory: "large",
                tags: ["chat", "large", "meta", "long-context"]
            },
            {
                name: "qwen2.5:14b",
                description: "Qwen 2.5 14B - Medium-sized multilingual model",
                size: "~8.7GB",
                sizeCategory: "medium",
                tags: ["chat", "multilingual", "chinese", "medium"]
            },
            {
                name: "qwen2.5:32b",
                description: "Qwen 2.5 32B - Large multilingual model",
                size: "~19GB",
                sizeCategory: "large",
                tags: ["chat", "multilingual", "chinese", "large"]
            },
            {
                name: "mixtral:8x7b",
                description: "Mistral 8x7B MoE - Mixture of experts model with excellent performance",
                size: "~26GB",
                sizeCategory: "large",
                tags: ["chat", "general", "mixture-of-experts", "large"]
            },
            {
                name: "mixtral:8x22b",
                description: "Mistral 8x22B MoE - Larger mixture of experts model",
                size: "~87GB",
                sizeCategory: "large",
                tags: ["chat", "general", "mixture-of-experts", "huge"]
            },
            {
                name: "command-r:35b",
                description: "Cohere Command R 35B - Enterprise-grade conversational AI",
                size: "~20GB",
                sizeCategory: "large",
                tags: ["chat", "enterprise", "cohere", "rag"]
            },
            {
                name: "yi:34b",
                description: "01.AI Yi 34B - Bilingual English-Chinese model",
                size: "~19GB",
                sizeCategory: "large",
                tags: ["chat", "multilingual", "chinese", "large"]
            },
            {
                name: "solar:10.7b",
                description: "Solar 10.7B - Efficient mid-size model",
                size: "~6.1GB",
                sizeCategory: "medium",
                tags: ["chat", "efficient", "medium"]
            },
            
            // Specialized models
            {
                name: "nomic-embed-text",
                description: "Nomic Embed Text - Text embedding model for semantic search",
                size: "~274MB",
                sizeCategory: "tiny",
                tags: ["embedding", "search", "utility", "nomic"]
            },
            {
                name: "mxbai-embed-large",
                description: "MixedBread AI Embed Large - High-quality embedding model",
                size: "~669MB",
                sizeCategory: "tiny",
                tags: ["embedding", "search", "utility"]
            },
            {
                name: "llava:7b",
                description: "LLaVA 7B - Large Language and Vision Assistant",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["vision", "multimodal", "images", "chat"]
            },
            {
                name: "llava:13b",
                description: "LLaVA 13B - Larger vision-language model",
                size: "~7.3GB",
                sizeCategory: "medium",
                tags: ["vision", "multimodal", "images", "chat"]
            },
            {
                name: "bakllava",
                description: "BakLLaVA - Efficient vision-language model",
                size: "~4.4GB",
                sizeCategory: "small",
                tags: ["vision", "multimodal", "images", "efficient"]
            },
            {
                name: "dolphin-mistral",
                description: "Dolphin Mistral - Uncensored and helpful assistant",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "uncensored", "helpful", "dolphin"]
            },
            {
                name: "dolphin-llama3:8b",
                description: "Dolphin Llama 3 8B - Uncensored Llama 3 variant",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["chat", "uncensored", "meta", "dolphin"]
            },
            {
                name: "neural-chat",
                description: "Neural Chat 7B - Fine-tuned for conversations",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "conversation", "intel"]
            },
            {
                name: "orca-mini",
                description: "Orca Mini 3B - Small but capable reasoning model",
                size: "~1.9GB",
                sizeCategory: "small",
                tags: ["chat", "reasoning", "small", "microsoft"]
            },
            {
                name: "vicuna:7b",
                description: "Vicuna 7B - ChatGPT-like conversational model",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "conversation", "vicuna"]
            },
            {
                name: "vicuna:13b",
                description: "Vicuna 13B - Larger conversational model",
                size: "~7.3GB",
                sizeCategory: "medium",
                tags: ["chat", "conversation", "vicuna"]
            },
            {
                name: "wizardcoder:7b",
                description: "WizardCoder 7B - Code generation specialist",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["code", "programming", "wizard"]
            },
            {
                name: "wizardcoder:13b",
                description: "WizardCoder 13B - More capable code generation",
                size: "~7.3GB",
                sizeCategory: "medium",
                tags: ["code", "programming", "wizard"]
            },
            {
                name: "openchat",
                description: "OpenChat 7B - Open-source conversational AI",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "open-source", "conversation"]
            },
            {
                name: "zephyr",
                description: "Zephyr 7B - Helpful and harmless assistant",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "helpful", "assistant"]
            },
            {
                name: "stablelm2",
                description: "StableLM 2 1.6B - Efficient small language model",
                size: "~1.6GB",
                sizeCategory: "small",
                tags: ["chat", "small", "stable", "efficient"]
            },
            {
                name: "falcon:7b",
                description: "Falcon 7B - High-performance language model",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "performance", "falcon"]
            },
            {
                name: "nous-hermes2",
                description: "Nous Hermes 2 - Fine-tuned for instruction following",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "instruction", "nous", "hermes"]
            },
            {
                name: "sqlcoder:7b",
                description: "SQLCoder 7B - Specialized for SQL generation",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["sql", "database", "code", "specialized"]
            },
            {
                name: "magicoder:6.7b",
                description: "MagiCoder 6.7B - Code generation with reasoning",
                size: "~3.7GB",
                sizeCategory: "small",
                tags: ["code", "programming", "reasoning"]
            },
            {
                name: "phind-codellama:34b",
                description: "Phind CodeLlama 34B - Advanced code assistant",
                size: "~19GB",
                sizeCategory: "large",
                tags: ["code", "programming", "large", "phind"]
            },
            {
                name: "deepseek-llm:7b",
                description: "DeepSeek LLM 7B - General purpose model from DeepSeek",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "general", "deepseek"]
            },
            {
                name: "deepseek-llm:67b",
                description: "DeepSeek LLM 67B - Large scale model from DeepSeek",
                size: "~37GB",
                sizeCategory: "large",
                tags: ["chat", "large", "deepseek", "powerful"]
            },
            {
                name: "aya:8b",
                description: "Aya 8B - Multilingual model covering 101 languages",
                size: "~4.8GB",
                sizeCategory: "small",
                tags: ["multilingual", "global", "cohere"]
            },
            {
                name: "samantha-mistral",
                description: "Samantha Mistral - Companion AI with personality",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "personality", "companion"]
            },
            {
                name: "starling-lm:7b",
                description: "Starling LM 7B - Reinforcement learning trained model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "rlhf", "berkeley"]
            },
            {
                name: "yarn-mistral:7b",
                description: "Yarn Mistral 7B - Extended context length model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "long-context", "extended"]
            },
            {
                name: "medllama2:7b",
                description: "MedLlama2 7B - Medical domain specialist",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["medical", "healthcare", "specialized"]
            },
            {
                name: "meditron:7b",
                description: "Meditron 7B - Medical AI assistant",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["medical", "healthcare", "assistant"]
            }
        ];

        function loadTheme() {
            const savedTheme = localStorage.getItem('ollama_theme') || 'dark';
            document.documentElement.setAttribute('data-theme', savedTheme);
            const themeIcon = document.getElementById('themeIcon');
            if (themeIcon) {
                themeIcon.className = savedTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

        function toggleTheme() {
            const currentTheme = document.documentElement.getAttribute('data-theme');
            const newTheme = currentTheme === 'light' ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
            localStorage.setItem('ollama_theme', newTheme);
            const themeIcon = document.getElementById('themeIcon');
            if (themeIcon) {
                themeIcon.className = newTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

        function checkOllamaStatus() {
            const statusDot = document.getElementById('statusDot');
            const statusText = document.getElementById('statusText');
            const statusContainer = document.getElementById('ollamaStatusContainer');
            const statusIcon = document.getElementById('ollamaStatusIcon');
            const statusTitle = document.getElementById('ollamaStatusTitle');
            const statusMessage = document.getElementById('ollamaStatusMessage');
            const instructions = document.getElementById('ollamaInstructions');
            const instructionsList = document.getElementById('instructionsList');
            const commandSection = document.getElementById('commandSection');

            statusText.textContent = 'Checking...';
            statusDot.classList.remove('online');

            fetch('?action=check_status')
                .then(response => response.json())
                .then(data => {
                    if (data.running) {
                        // Ollama is running
                        statusDot.classList.add('online');
                        statusText.textContent = `Ollama Online (${data.version || 'Unknown version'})`;
                        statusContainer.style.display = 'none';
                        ollamaRunning = true;
                        loadInstalledModels();
                    } else if (data.installed) {
                        // Ollama is installed but not running
                        statusDot.classList.remove('online');
                        statusText.textContent = 'Ollama Offline';
                        statusContainer.style.display = 'block';
                        statusIcon.className = 'fas fa-play-circle';
                        statusTitle.textContent = 'Ollama is installed but not running';
                        statusMessage.textContent = 'Please start the Ollama service to continue.';
                        
                        instructions.style.display = 'block';
                        instructionsList.innerHTML = data.instructions.map(inst => `<li>${inst}</li>`).join('');
                        commandSection.style.display = 'block';
                        ollamaRunning = false;
                    } else {
                        // Ollama is not installed
                        statusDot.classList.remove('online');
                        statusText.textContent = 'Ollama Not Found';
                        statusContainer.style.display = 'block';
                        statusIcon.className = 'fas fa-download';
                        statusTitle.textContent = 'Ollama is not installed';
                        statusMessage.textContent = 'To use this model manager, you need to install Ollama first.';
                        
                        instructions.style.display = 'block';
                        instructionsList.innerHTML = data.instructions.map(inst => `<li>${inst}</li>`).join('');
                        commandSection.style.display = 'none';
                        ollamaRunning = false;
                    }
                })
                .catch(error => {
                    statusDot.classList.remove('online');
                    statusText.textContent = 'Connection Error';
                    statusContainer.style.display = 'block';
                    statusIcon.className = 'fas fa-exclamation-triangle';
                    statusTitle.textContent = 'Connection Error';
                    statusMessage.textContent = 'Unable to check Ollama status. Please check your connection.';
                    instructions.style.display = 'none';
                    ollamaRunning = false;
                });
        }

        function copyCommand() {
            const command = document.getElementById('ollamaCommand').textContent;
            navigator.clipboard.writeText(command).then(() => {
                const copyBtn = document.querySelector('.copy-btn');
                const originalHTML = copyBtn.innerHTML;
                copyBtn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(() => {
                    copyBtn.innerHTML = originalHTML;
                }, 2000);
                showAlert('success', 'Command copied to clipboard!');
            }).catch(error => {
                console.error('Failed to copy command:', error);
                showAlert('error', 'Failed to copy command. Please copy manually.');
            });
        }

        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
            
            if (tabName === 'installed' && ollamaRunning) {
                loadInstalledModels();
            } else if (tabName === 'available') {
                displayAvailableModels();
            }
        }

        function loadInstalledModels() {
            if (!ollamaRunning) {
                document.getElementById('installedModels').innerHTML = `
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        <span>Ollama is not running. Please start Ollama to view installed models.</span>
                    </div>
                `;
                return;
            }

            const container = document.getElementById('installedModels');
            container.innerHTML = '<div class="loading"><div class="spinner"></div>Loading installed models...</div>';

            fetch('?action=list')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        container.innerHTML = `
                            <div class="alert alert-error">
                                <i class="fas fa-exclamation-circle"></i>
                                <span>${data.error}</span>
                            </div>
                        `;
                        return;
                    }

                    installedModels = data.models || [];
                    if (installedModels.length === 0) {
                        container.innerHTML = `
                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i>
                                <span>No models installed yet. Visit the "Available Models" tab to download some!</span>
                            </div>
                        `;
                        return;
                    }

                    let html = '<div class="model-grid">';
                    installedModels.forEach(model => {
                        html += createInstalledModelCard(model);
                    });
                    html += '</div>';
                    container.innerHTML = html;
                })
                .catch(error => {
                    container.innerHTML = `
                        <div class="alert alert-error">
                            <i class="fas fa-exclamation-circle"></i>
                            <span>Failed to load installed models: ${error.message}</span>
                        </div>
                    `;
                });
        }

        function createInstalledModelCard(model) {
            return `
                <div class="installed-model-card">
                    <div class="model-header">
                        <h3>${model.name}</h3>
                        <span class="model-size">${model.size}</span>
                    </div>
                    <div class="model-info">
                        <div class="info-item">
                            <i class="fas fa-fingerprint"></i>
                            <span>ID: ${model.id.substring(0, 12)}...</span>
                        </div>
                        <div class="info-item">
                            <i class="fas fa-clock"></i>
                            <span>Modified: ${model.modified}</span>
                        </div>
                    </div>
                    <div class="model-actions">
                        <button class="btn btn-primary" onclick="useModel('${model.name}')">
                            <i class="fas fa-comments"></i> Chat with this model
                        </button>
                        <button class="btn btn-danger" onclick="confirmRemoveModel('${model.name}')">
                            <i class="fas fa-trash"></i> Remove
                        </button>
                    </div>
                </div>
            `;
        }

        function useModel(modelName) {
            window.location.href = `index.php?model=${encodeURIComponent(modelName)}`;
        }

        function confirmRemoveModel(modelName) {
            if (confirm(`Are you sure you want to remove the model "${modelName}"? This will delete all downloaded files for this model.`)) {
                removeModel(modelName);
            }
        }

        function removeModel(modelName) {
            const formData = new FormData();
            formData.append('model', modelName);

            fetch('?action=remove', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    showAlert('error', `Failed to remove model: ${data.error}`);
                } else {
                    showAlert('success', `Model "${modelName}" removed successfully!`);
                    loadInstalledModels();
                }
            })
            .catch(error => {
                showAlert('error', `Failed to remove model: ${error.message}`);
            });
        }

        function populateFilters() {
            const categoryFilter = document.getElementById('categoryFilter');
            const sizeFilter = document.getElementById('sizeFilter');

            const categories = [...new Set(availableModels.flatMap(model => model.tags))].sort();
            categories.forEach(category => {
                const option = document.createElement('option');
                option.value = category;
                option.textContent = category.charAt(0).toUpperCase() + category.slice(1);
                categoryFilter.appendChild(option);
            });

            const sizes = [...new Set(availableModels.map(model => model.sizeCategory))].sort();
            const sizeLabels = {
                'tiny': 'Tiny (< 2GB)',
                'small': 'Small (2-5GB)',
                'medium': 'Medium (5-15GB)',
                'large': 'Large (> 15GB)'
            };
            sizes.forEach(size => {
                const option = document.createElement('option');
                option.value = size;
                option.textContent = sizeLabels[size] || size;
                sizeFilter.appendChild(option);
            });
        }

        function filterModels() {
            const searchTerm = document.getElementById('searchBox').value.toLowerCase();
            const categoryFilter = document.getElementById('categoryFilter').value;
            const sizeFilter = document.getElementById('sizeFilter').value;

            const filteredModels = availableModels.filter(model => {
                const matchesSearch = !searchTerm || 
                    model.name.toLowerCase().includes(searchTerm) ||
                    model.description.toLowerCase().includes(searchTerm) ||
                    model.tags.some(tag => tag.toLowerCase().includes(searchTerm));

                const matchesCategory = !categoryFilter || model.tags.includes(categoryFilter);
                const matchesSize = !sizeFilter || model.sizeCategory === sizeFilter;

                return matchesSearch && matchesCategory && matchesSize;
            });

            displayAvailableModels(filteredModels);
        }

        function displayAvailableModels(models = availableModels) {
            const container = document.getElementById('availableModels');
            
            if (models.length === 0) {
                container.innerHTML = `
                    <div class="alert alert-info">
                        <i class="fas fa-search"></i>
                        <span>No models found matching your criteria. Try adjusting your filters.</span>
                    </div>
                `;
                return;
            }

            let html = '';
            models.forEach(model => {
                html += createAvailableModelCard(model);
            });
            container.innerHTML = html;
        }

        function createAvailableModelCard(model) {
            const isDownloading = downloadingModels.has(model.name);
            const isInstalled = installedModels.some(installed => installed.name.includes(model.name.split(':')[0]));

            return `
                <div class="available-model ${isInstalled ? 'installed' : ''} ${isDownloading ? 'downloading' : ''}" data-model="${model.name}">
                    <div class="model-header">
                        <h3>${model.name}</h3>
                        <span class="model-size">${model.size}</span>
                        ${isInstalled ? '<span class="installed-badge"><i class="fas fa-check"></i> Installed</span>' : ''}
                    </div>
                    <div class="model-description">
                        ${model.description}
                    </div>
                    <div class="model-tags">
                        ${model.tags.map(tag => `<span class="tag">${tag}</span>`).join('')}
                    </div>
                    <div class="model-actions">
                        ${isDownloading ? `
                            <div class="download-progress">
                                <div class="progress-bar">
                                    <div class="progress-fill" id="progress-${model.name.replace(/[^a-zA-Z0-9]/g, '-')}"></div>
                                </div>
                                <div class="progress-text" id="progress-text-${model.name.replace(/[^a-zA-Z0-9]/g, '-')}">Downloading...</div>
                                <button class="btn btn-secondary" onclick="cancelDownload('${model.name}')">
                                    <i class="fas fa-times"></i> Cancel
                                </button>
                            </div>
                        ` : isInstalled ? `
                            <button class="btn btn-success" onclick="useModel('${model.name}')">
                                <i class="fas fa-comments"></i> Use Model
                            </button>
                            <button class="btn btn-danger" onclick="confirmRemoveModel('${model.name}')">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        ` : ollamaRunning ? `
                            <button class="btn btn-primary" onclick="downloadModel('${model.name}')">
                                <i class="fas fa-download"></i> Download
                            </button>
                        ` : `
                            <button class="btn btn-secondary" disabled title="Start Ollama to download models">
                                <i class="fas fa-download"></i> Download
                            </button>
                        `}
                    </div>
                </div>
            `;
        }

        function downloadModel(modelName) {
            if (!ollamaRunning) {
                showAlert('error', 'Please start Ollama first before downloading models.');
                return;
            }

            if (downloadingModels.has(modelName)) {
                showAlert('warning', 'This model is already being downloaded.');
                return;
            }

            const modelCard = document.querySelector(`[data-model="${modelName}"]`);
            if (modelCard) {
                modelCard.classList.add('downloading');
                const model = availableModels.find(m => m.name === modelName);
                if (model) {
                    downloadingModels.set(modelName, null);
                    modelCard.outerHTML = createAvailableModelCard(model);
                }
            }

            const eventSource = new EventSource(`?action=download&model=${encodeURIComponent(modelName)}`);
            downloadingModels.set(modelName, eventSource);

            const progressFill = document.getElementById(`progress-${modelName.replace(/[^a-zA-Z0-9]/g, '-')}`);
            const progressText = document.getElementById(`progress-text-${modelName.replace(/[^a-zA-Z0-9]/g, '-')}`);

            eventSource.addEventListener('progress', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    if (progressFill && progressText) {
                        progressFill.style.width = `${data.percentage || 0}%`;
                        progressText.textContent = data.message || `${data.percentage || 0}%`;
                    }
                } catch (e) {
                    console.error('Failed to parse progress event:', e);
                }
            });

            eventSource.addEventListener('success', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    showAlert('success', data.message || `Model "${modelName}" downloaded successfully!`);
                    eventSource.close();
                    downloadingModels.delete(modelName);
                    loadInstalledModels();
                    displayAvailableModels();
                } catch (e) {
                    console.error('Failed to parse success event:', e);
                }
            });

            eventSource.addEventListener('error', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    showAlert('error', data.error || `Failed to download "${modelName}"`);
                } catch (e) {
                    showAlert('error', `Failed to download "${modelName}": Connection error`);
                }
                eventSource.close();
                downloadingModels.delete(modelName);
                displayAvailableModels();
            });

            eventSource.onerror = function(event) {
                console.error('Download stream error:', event);
                showAlert('error', `Download stream error for "${modelName}"`);
                eventSource.close();
                downloadingModels.delete(modelName);
                displayAvailableModels();
            };
        }

        function cancelDownload(modelName) {
            const eventSource = downloadingModels.get(modelName);
            if (eventSource) {
                eventSource.close();
                downloadingModels.delete(modelName);
                showAlert('info', `Download of "${modelName}" cancelled.`);
                displayAvailableModels();
            }
        }

        function showAlert(type, message) {
            const alertsContainer = document.getElementById('alerts');
            const alertId = 'alert-' + Date.now();
            
            const iconMap = {
                'success': 'fas fa-check-circle',
                'error': 'fas fa-exclamation-circle',
                'warning': 'fas fa-exclamation-triangle',
                'info': 'fas fa-info-circle'
            };

            const alert = document.createElement('div');
            alert.className = `alert alert-${type}`;
            alert.id = alertId;
            alert.innerHTML = `
                <i class="${iconMap[type] || 'fas fa-info-circle'}"></i>
                <span>${message}</span>
                <button class="close" onclick="closeAlert('${alertId}')">&times;</button>
            `;

            alertsContainer.appendChild(alert);
            setTimeout(() => closeAlert(alertId), 5000);
        }

        function closeAlert(alertId) {
            const alert = document.getElementById(alertId);
            if (alert) {
                alert.remove();
            }
        }

        // Initialize the interface
        document.addEventListener('DOMContentLoaded', function() {
            loadTheme();
            checkOllamaStatus();
            populateFilters();
        });
    </script>

    <style>
        .btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 14px;
            transition: all 0.2s ease;
            text-decoration: none;
        }

        .btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
        }

        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }

        .btn-primary { background: var(--accent); }
        .btn-secondary { background: var(--bg-tertiary); color: var(--text-primary); border: 1px solid var(--border); }
        .btn-success { background: var(--success); }
        .btn-danger { background: var(--error); }

        .ollama-status-card {
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 24px;
            margin: 20px 0;
            display: flex;
            align-items: flex-start;
            gap: 20px;
        }

        .status-icon {
            font-size: 48px;
            color: var(--accent);
            flex-shrink: 0;
        }

        .status-content h3 {
            margin: 0 0 8px 0;
            color: var(--text-primary);
            font-size: 1.4em;
        }

        .status-content p {
            margin: 0 0 16px 0;
            color: var(--text-secondary);
            line-height: 1.5;
        }

        .command-box {
            background: var(--bg-tertiary);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Courier New', monospace;
            margin: 10px 0;
        }

        .copy-btn {
            background: transparent;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            transition: all 0.2s ease;
        }

        .copy-btn:hover {
            background: var(--bg-primary);
            color: var(--accent);
        }

        .primary-btn {
            background: var(--accent);
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            transition: all 0.2s ease;
        }

        .primary-btn:hover {
            background: var(--accent-hover);
            transform: translateY(-1px);
        }

        /* Model Grid Layout for Installed Models */
        .model-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }

        @media (max-width: 768px) {
            .model-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
        }

        .installed-model-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .installed-model-card:hover {
            transform: translateY(-4px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
            border-color: var(--accent);
        }

        .installed-model-card .model-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 16px;
            gap: 12px;
        }

        .installed-model-card .model-header h3 {
            margin: 0;
            color: var(--text-primary);
            font-size: 1.3em;
            font-weight: 600;
            line-height: 1.2;
            flex: 1;
            word-break: break-word;
        }

        .installed-model-card .model-size {
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            color: white;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8em;
            font-weight: 600;
            white-space: nowrap;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .installed-model-card .model-info {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin-bottom: 20px;
            padding: 12px;
            background: var(--bg-tertiary);
            border-radius: 8px;
            border: 1px solid var(--border);
        }

        .installed-model-card .info-item {
            display: flex;
            align-items: center;
            gap: 10px;
            color: var(--text-secondary);
            font-size: 0.9em;
        }

        .installed-model-card .info-item i {
            width: 18px;
            color: var(--accent);
            font-size: 0.9em;
        }

        .installed-model-card .model-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .installed-model-card .btn {
            flex: 1;
            min-width: 140px;
            justify-content: center;
            padding: 10px 16px;
            font-weight: 500;
            border-radius: 8px;
            transition: all 0.2s ease;
        }

        .installed-model-card .btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        }

        .installed-model-card .btn-primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-hover));
            border: none;
        }

        .installed-model-card .btn-danger {
            background: linear-gradient(135deg, var(--error), #dc2626);
            border: none;
        }

        .installed-model-card .btn-danger:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
        }

        /* Available Models Grid */
        .available-models {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
            gap: 20px;
        }

        @media (max-width: 768px) {
            .available-models {
                grid-template-columns: 1fr;
            }
        }

        .available-model {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .available-model:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .search-filters {
            margin-bottom: 20px;
        }

        .filter-group {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }

        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            background: var(--bg-secondary);
            color: var(--text-primary);
            font-size: 14px;
        }

        .filter-group input {
            flex: 1;
            min-width: 200px;
        }

        .progress-bar {
            height: 8px;
            background: var(--bg-tertiary);
            border-radius: 4px;
            overflow: hidden;
            margin-bottom: 8px;
        }

        .progress-fill {
            height: 100%;
            background: var(--accent);
            width: 0%;
            border-radius: 4px;
            transition: width 0.3s ease;
        }

        .progress-text {
            font-size: 0.85em;
            color: var(--text-secondary);
            margin-bottom: 8px;
        }

        .download-progress {
            width: 100%;
        }

        .alert {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .alert-success {
            background: rgba(56, 161, 105, 0.1);
            border: 1px solid var(--success);
            color: var(--success);
        }

        .alert-error {
            background: rgba(229, 62, 62, 0.1);
            border: 1px solid var(--error);
            color: var(--error);
        }

        .alert-warning {
            background: rgba(237, 137, 54, 0.1);
            border: 1px solid #ed8936;
            color: #ed8936;
        }

        .alert-info {
            background: rgba(49, 130, 206, 0.1);
            border: 1px solid #3182ce;
            color: #3182ce;
        }

        .alert .close {
            background: none;
            border: none;
            color: inherit;
            cursor: pointer;
            font-size: 16px;
            margin-left: auto;
        }

        .spinner {
            border: 2px solid var(--bg-tertiary);
            border-top: 2px solid var(--accent);
            border-radius: 50%;
            width: 20px;
            height: 20px;
            animation: spin 1s linear infinite;
            display: inline-block;
            margin-right: 10px;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
            color: var(--text-secondary);
        }

        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 20px;
        }

        .tab {
            background: none;
            border: none;
            padding: 12px 24px;
            cursor: pointer;
            color: var(--text-secondary);
            border-bottom: 2px solid transparent;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .tab:hover {
            color: var(--text-primary);
        }

        .tab.active {
            color: var(--accent);
            border-bottom-color: var(--accent);
        }

        .tab-content {
            min-height: 400px;
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
        }
    </style>
</body>
</html>
<?php
}
?>