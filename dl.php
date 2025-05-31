<?php
// Ollama Model Management Interface - Jada OLLAMA
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Handle different API endpoints
$action = $_GET['action'] ?? '';
switch ($action) {
    case 'list':
        // Keeping shell_exec version as provided, but API is more robust
        handleListModels();
        break;
    case 'download':
        // *** Using API for proper download progression ***
        handleDownloadModel();
        break;
    case 'remove':
        // Keeping shell_exec version as provided, but API is more robust
        handleRemoveModel();
        break;
    case 'check_status':
        // Keeping shell_exec version as provided, but API is more robust
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
    // Ensure output is sent immediately
    ob_flush();
    flush();
}

// Helper function to format bytes into human-readable size (used in the curl callback and potentially list)
function formatBytes($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $bytes = max(0, $bytes);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= (1 << ($pow * 10));
    return round($bytes, $precision) . ' ' . $units[$pow];
}


// --- handleListModels (Using shell_exec as in provided code) ---
function handleListModels() {
    header('Content-Type: application/json');
    $cmd = "ollama list 2>&1";
    $output = shell_exec($cmd);

    // Check for common errors indicating Ollama isn't running or command failed
    if ($output === null || strpos($output, 'Error: ') !== false || strpos($output, 'connection refused') !== false || strpos($output, 'command not found') !== false) {
         $error_message = 'Ollama is not running or not accessible.';
         if (strpos($output, 'connection refused') !== false) {
             $error_message = 'Ollama connection refused. Is it running?';
         } elseif (strpos($output, 'command not found') !== false) {
              $error_message = 'Ollama command not found. Is Ollama installed and in your PATH?';
         } elseif ($output !== null && !empty(trim($output))) {
              $error_message = 'Ollama command failed: ' . trim($output);
         }
        echo json_encode(['error' => $error_message]);
        exit;
    }

    $models = [];
    $lines = explode("\n", trim($output));

    // Check if output contains the expected header line "NAME"
    if (count($lines) > 0 && strpos(trim($lines[0]), 'NAME') === 0) {
        // Skip header line and parse model list
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            // Use a more robust regex to split based on multiple spaces, but handle potential single spaces in names
            // This regex looks for 2 or more spaces as delimiters
            $parts = preg_split('/\s{2,}/', $line);

            if (count($parts) >= 4) { // Expecting NAME, ID, SIZE, MODIFIED
                 // Extract parts and trim whitespace
                $name = trim($parts[0]);
                $id = trim($parts[1]);
                $size = trim($parts[2]);
                $modified = trim($parts[3]);

                $models[] = [
                    'name' => $name,
                    'id' => $id,
                    'size' => $size,
                    'modified' => $modified
                ];
            } else {
                 // Handle lines that don't match expected format, maybe log or skip
                 // sendSSEEvent('debug', ['message' => 'Skipping malformed line in list output', 'line' => $line]); // Cannot send SSE here
            }
        }
    } else {
        // If the header is missing, it might be an error or unexpected output
        echo json_encode(['error' => 'Unexpected output format from ollama list. Output: ' . trim($output)]);
        exit;
    }

    echo json_encode(['models' => $models]);
    exit;
}


// --- handleDownloadModel (Refactored to use API) ---
function handleDownloadModel() {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no'); // Disable buffering for Nginx
    header('Access-Control-Allow-Origin: *'); // Allow CORS if needed

    $model = $_GET['model'] ?? '';
    if (empty($model)) {
        sendSSEEvent('error', ['error' => 'Model name is required']);
        exit;
    }

    $ollama_url = 'http://localhost:11434/api/pull';
    $post_data = json_encode(['name' => $model]);

    sendSSEEvent('status', ['message' => "Connecting to Ollama API to download $model..."]);

    $ch = curl_init($ollama_url);

    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($post_data)
    ]);

    // Use a write function to process the streamed response
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($curl, $data) use ($model) {
        static $buffer = '';
        $buffer .= $data;

        // Process complete lines (Ollama API streams newline-delimited JSON)
        while (($newline_pos = strpos($buffer, "\n")) !== false) {
            $line = substr($buffer, 0, $newline_pos);
            $buffer = substr($buffer, $newline_pos + 1);

            if (empty(trim($line))) continue; // Skip empty lines

            $json_data = json_decode($line, true);

            if ($json_data === null && json_last_error() !== JSON_ERROR_NONE) {
                // Failed to decode JSON, might be an error message or unexpected output
                // sendSSEEvent('debug', ['message' => 'Failed to decode JSON line', 'line' => $line, 'error' => json_last_error_msg()]);
                // Treat as a generic info message if it's not JSON
                sendSSEEvent('info', ['message' => trim($line)]);
                continue;
            }

            // Handle potential errors returned in the stream
            if (isset($json_data['error'])) {
                // If the API returns an error JSON, send it as an error event
                sendSSEEvent('error', ['error' => $json_data['error']]);
                // Returning 0 from the write function will stop curl
                return 0; // Stop processing on error
            }

            // Handle progress updates
            if (isset($json_data['status'])) {
                $status = $json_data['status'];
                $percentage = $json_data['percent'] ?? null; // API provides percent directly sometimes
                $total_bytes = $json_data['total'] ?? null;
                $completed_bytes = $json_data['completed'] ?? null;
                $digest = $json_data['digest'] ?? null; // Layer digest

                $message = $status;
                $stage = 'unknown'; // Default stage

                // Map API status to a simplified stage and message
                if (strpos($status, 'pulling manifest') !== false) {
                    $stage = 'manifest';
                    $message = 'Downloading manifest...';
                    // API might provide percent for manifest/config, use it if available
                    $percentage = $percentage ?? 5; // Estimate if no percent
                } elseif (strpos($status, 'pulling config') !== false) {
                    $stage = 'config';
                    $message = 'Downloading configuration...';
                    $percentage = $percentage ?? 10; // Estimate if no percent
                } elseif (strpos($status, 'pulling') !== false && $digest) {
                    $stage = 'download';
                    // If percentage is provided by API, use it
                    if ($percentage !== null) {
                         $message = "Downloading layer " . substr($digest, 0, 8) . "... {$percentage}%";
                    } elseif ($completed_bytes !== null && $total_bytes !== null && $total_bytes > 0) {
                         // Calculate percentage from bytes if percent is missing
                         $percentage = round(($completed_bytes / $total_bytes) * 100);
                         $message = "Downloading layer " . substr($digest, 0, 8) . "... " . formatBytes($completed_bytes) . " / " . formatBytes($total_bytes) . " ({$percentage}%)";
                    } else {
                         // Fallback message if no percentage or byte info for a layer
                         $message = "Downloading layer " . substr($digest, 0, 8) . "...";
                         $percentage = null; // Don't update percentage if we can't calculate it for this specific line
                    }
                } elseif (strpos($status, 'verifying') !== false) {
                     $stage = 'verify';
                     $message = 'Verifying download integrity...';
                     $percentage = $percentage ?? 90; // Estimate
                } elseif (strpos($status, 'extracting') !== false) {
                     $stage = 'extracting';
                     $message = 'Extracting model layers...';
                     $percentage = $percentage ?? 92; // Estimate
                } elseif (strpos($status, 'writing manifest') !== false) {
                     $stage = 'writing';
                     $message = 'Writing manifest...';
                     $percentage = $percentage ?? 95; // Estimate
                } elseif (strpos($status, 'tagging') !== false) {
                     $stage = 'tagging';
                     $message = 'Tagging model...';
                     $percentage = $percentage ?? 98; // Estimate
                } elseif ($status === 'success') {
                    $stage = 'complete';
                    $message = "Model $model downloaded successfully!";
                    $percentage = 100;
                    sendSSEEvent('success', ['message' => $message]);
                } elseif (strpos($status, 'already exists') !== false) {
                     $stage = 'complete'; // Treat as complete if already exists
                     $message = "Model $model is already installed.";
                     $percentage = 100;
                     sendSSEEvent('success', ['message' => $message]); // Send success for already exists
                }
                // else it's a generic status message, handled below

                // Send progress event if we have a percentage or it's a specific stage
                if ($percentage !== null || $stage !== 'unknown') {
                     // Ensure percentage is between 0 and 100
                     $percentage = max(0, min(100, $percentage ?? 0));

                     sendSSEEvent('progress', [
                         'stage' => $stage,
                         'percentage' => $percentage,
                         'message' => $message,
                         // Include raw data for debugging if needed
                         // 'raw_data' => $json_data
                     ]);
                } else {
                     // Send generic status/info if no specific stage or percentage
                     sendSSEEvent('status', ['message' => $message]);
                }
            } else {
                // If no status field, it might be other JSON data or unexpected output
                // sendSSEEvent('debug', ['message' => 'Received unexpected JSON', 'data' => $json_data]);
                 // Or just ignore JSON that doesn't fit the expected progress format
            }
        }

        // Return the number of bytes consumed (all of them in this case)
        return strlen($data);
    });

    // Set a timeout for the curl operation (e.g., 30 minutes)
    curl_setopt($ch, CURLOPT_TIMEOUT, 1800); // 30 minutes

    // Execute the curl request
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    curl_close($ch);

    // Check for curl execution errors or non-200 HTTP status codes
    // If curl failed *before* the API stream could complete, send an error.
    // If the API stream sent an error JSON, the write function would have handled it.
    if ($response === false || ($http_code !== 200 && $http_code !== 0)) { // http_code 0 often means connection refused
        $error_message = $error ? "cURL Error: " . $error : "HTTP Error: " . $http_code;
        if ($http_code === 0 || strpos($error, 'connection refused') !== false) {
             $error_message = 'Ollama API connection refused. Is Ollama running?';
        } elseif ($response) { // Include API response if available
            $api_response = json_decode($response, true);
            if (isset($api_response['error'])) {
                 $error_message .= " API Error: " . $api_response['error'];
            } else {
                 $error_message .= " API Response: " . trim($response);
            }
        }
        sendSSEEvent('error', ['error' => 'Download failed: ' . $error_message]);
        sendSSEEvent('complete', ['status' => 'error', 'message' => 'Download failed: ' . $error_message]);
    } else {
         // If curl finished without a connection/HTTP error, assume the API stream
         // either sent a 'success' or 'error' event via the write function,
         // or it completed normally. Send a final 'complete' event.
         sendSSEEvent('complete', ['status' => 'finished_processing', 'message' => 'Server finished processing download request.']);
    }

    exit; // Terminate the script
}

// --- handleRemoveModel (Using shell_exec as in provided code) ---
function handleRemoveModel() {
    header('Content-Type: application/json');
    $model = $_POST['model'] ?? ''; // Using POST for remove as it modifies state

    if (empty($model)) {
        echo json_encode(['error' => 'Model name is required']);
        exit;
    }

    $cmd = "ollama rm " . escapeshellarg($model) . " 2>&1"; // Capture both stdout and stderr
    $output = shell_exec($cmd);

    // Check for common error indicators in shell output
    // This is a heuristic and less reliable than checking exit code or API response
    if (strpos($output, 'error') !== false || strpos($output, 'Error') !== false || strpos($output, 'failed') !== false || strpos($output, 'not found') !== false) {
        // If output contains error keywords, assume failure
        echo json_encode(['error' => trim($output)]);
    } else {
        // If no error keywords, assume success based on typical ollama rm output
        // shell_exec doesn't give exit code easily, so this is a heuristic.
        echo json_encode(['success' => true, 'message' => "Model $model removed successfully", 'output' => trim($output)]);
    }
    exit;
}

// --- handleCheckStatus (Using shell_exec as in provided code) ---
function handleCheckStatus() {
    header('Content-Type: application/json');
    $cmd = "ollama --version 2>&1"; // Use 2>&1 to capture version info even if it's on stderr sometimes
    $output = shell_exec($cmd);

    if ($output === null) {
        echo json_encode(['running' => false, 'error' => 'Ollama command not found. Is Ollama installed and in your PATH?']);
    } elseif (strpos($output, 'ollama version') !== false) {
        // Extract version number
        $version = trim(str_replace('ollama version', '', $output));
        echo json_encode(['running' => true, 'version' => $version]);
    } else {
         // Check for specific error messages from Ollama itself
         if (strpos($output, 'Error: ') !== false || strpos($output, 'connection refused') !== false) {
              echo json_encode(['running' => false, 'error' => trim($output)]);
         } else {
            // Unexpected output
            echo json_encode(['running' => false, 'error' => 'Ollama command returned unexpected output: ' . trim($output)]);
         }
    }
    exit;
}


// --- showInterface (HTML/JavaScript with provided CSS) ---
function showInterface() {
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ollama Model Manager - Jada OLLAMA</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
    <!-- Assuming styles.css contains your main theme variables and layout -->
    <link rel="stylesheet" href="styles.css?v=1.0">

</head>
<body>
    <!-- Header matching the main site -->
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
                    <i class="fas fa-comments"></i>
                </a>
                <button class="header-btn" onclick="toggleTheme()" title="Toggle Theme">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
            </div>
        </div>
    </div>

    <!-- Main content -->
    <div class="main-content">
        <div class="tabs">
            <button class="tab active" onclick="switchTab('installed')">
                <i class="fas fa-box"></i> Installed Models
            </button>
            <button class="tab" onclick="switchTab('available')">
                <i class="fas fa-globe"></i> Available Models
            </button>
        </div>

        <div class="tab-content">
            <div id="alerts"></div>

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
                    <input type="text" class="search-box" id="searchBox" placeholder="Search models by name, description, or tags..." onkeyup="filterModels()">
                    <select class="filter-select" id="categoryFilter" onchange="filterModels()">
                        <option value="">All Categories</option>
                        <!-- Options populated by JS -->
                    </select>
                    <select class="filter-select" id="sizeFilter" onchange="filterModels()">
                        <option value="">All Sizes</option>
                        <!-- Options populated by JS -->
                    </select>
                </div>
                <div id="availableModels" class="available-models"></div>
                <div class="model-stats" id="modelStats"></div>
            </div>
        </div>
    </div>
    <script>
        // Theme management
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

        let installedModels = [];
        let downloadingModels = new Map(); // Maps modelName to EventSource instance

        // Comprehensive list of Ollama models with enhanced metadata
        const availableModels = [
            // Tiny Models (< 2GB)
            {
                name: "tinyllama",
                description: "Microsoft's TinyLlama - Ultra-lightweight model for basic tasks",
                size: "~637MB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "fast", "basic"]
            },
            {
                name: "phi3:mini",
                description: "Microsoft Phi-3 Mini - Extremely efficient small model",
                size: "~1.2GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "microsoft", "efficient"]
            },
            {
                name: "tinydolphin",
                description: "Tiny Dolphin - Small uncensored model for basic conversations",
                size: "~1.6GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "uncensored"]
            },
             {
                name: "gemma:2b",
                description: "Google Gemma 2B - Ultra-efficient model from Google",
                size: "~1.4GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "google", "efficient"]
            },
             {
                name: "llama3.2:1b",
                description: "Meta Llama 3.2 1B - Ultra-compact latest generation",
                size: "~1.3GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "meta", "latest"]
            },
             {
                name: "qwen2:0.5b",
                description: "Alibaba Qwen2 0.5B - Tiny multilingual model",
                size: "~0.4GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "multilingual", "alibaba"]
            },
             {
                name: "qwen2:1.5b",
                description: "Alibaba Qwen2 1.5B - Small multilingual with good performance",
                size: "~0.9GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "multilingual", "alibaba"]
            },
             {
                name: "moondream",
                description: "Moondream - Compact vision language model",
                size: "~829MB",
                sizeCategory: "tiny",
                tags: ["vision", "multimodal", "small", "efficient"]
            },

            // Small Models (2-5GB)
            {
                name: "phi3",
                description: "Microsoft Phi-3 - Compact but capable model",
                size: "~2.3GB",
                sizeCategory: "small",
                tags: ["chat", "small", "microsoft", "efficient"]
            },
            {
                name: "phi3:medium",
                description: "Microsoft Phi-3 Medium - Balanced performance and size",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "small", "microsoft"]
            },
            {
                name: "gemma",
                description: "Google Gemma 7B - Open model from Google",
                size: "~4.8GB",
                sizeCategory: "small",
                tags: ["chat", "small", "google"]
            },
            {
                name: "mistral",
                description: "Mistral 7B - Fast and efficient general-purpose model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "general", "fast", "small"]
            },
             {
                name: "llama3.2:3b",
                description: "Meta Llama 3.2 3B - Compact with good performance",
                size: "~2.0GB",
                sizeCategory: "small",
                tags: ["chat", "small", "meta", "latest"]
            },
            {
                name: "llama3.2",
                description: "Meta Llama 3.2 - Latest compact model with vision capabilities",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["chat", "vision", "meta", "latest", "multimodal"]
            },
             {
                name: "qwen2",
                description: "Alibaba Qwen2 7B - Multilingual capabilities with strong performance",
                size: "~4.4GB",
                sizeCategory: "small",
                tags: ["chat", "multilingual", "alibaba"]
            },
            {
                name: "orca-mini",
                description: "Orca Mini - Small model trained on high-quality data",
                size: "~1.9GB", // This is actually < 2GB, maybe move to tiny? Re-evaluating size categories. Let's keep it here for now.
                sizeCategory: "small",
                tags: ["chat", "small", "efficient"]
            },
            {
                name: "zephyr",
                description: "Zephyr 7B - Fine-tuned for helpful, harmless conversations",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "helpful", "small"]
            },
            {
                name: "neural-chat",
                description: "Intel Neural Chat - Optimized for conversational AI",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "intel", "conversation"]
            },
            {
                name: "starling-lm",
                description: "Starling LM 7B - High-quality conversational model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "conversation", "quality"]
            },
            {
                name: "stablelm2",
                description: "Stability AI StableLM 2 - Stable and reliable small model",
                size: "~2.7GB",
                sizeCategory: "small",
                tags: ["chat", "stable", "small"]
            },
            {
                name: "stablelm-zephyr",
                description: "StableLM Zephyr - Stability AI's conversational model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "stable", "conversation"]
            },
            // Code Models (many fit in small/medium categories)
            {
                name: "codellama:7b",
                description: "Code Llama 7B - Specialized for code generation and understanding",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["code", "programming", "small"]
            },
             {
                name: "codegemma",
                description: "Google CodeGemma - Specialized coding model from Google",
                size: "~4.8GB",
                sizeCategory: "small",
                tags: ["code", "programming", "google"]
            },
             {
                name: "deepseek-coder",
                description: "DeepSeek Coder - Advanced code generation and understanding",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["code", "programming", "deepseek"]
            },
             {
                name: "starcoder2:3b",
                description: "StarCoder2 3B - Compact code generation model",
                size: "~1.7GB", // Technically tiny, but often grouped with code models
                sizeCategory: "small", // Grouping with code models for filter simplicity
                tags: ["code", "programming", "small"]
            },
            {
                name: "starcoder2:7b",
                description: "StarCoder2 7B - Balanced code model for most tasks",
                size: "~4.0GB",
                sizeCategory: "small",
                tags: ["code", "programming"]
            },
             {
                name: "phind-codellama",
                description: "Phind CodeLlama - Optimized for coding assistance",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["code", "programming", "phind"]
            },
            {
                name: "wizardcoder",
                description: "WizardCoder - Enhanced code generation capabilities",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["code", "programming", "wizard"]
            },
            {
                name: "magicoder",
                description: "MagiCoder - Advanced code understanding and generation",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["code", "programming", "magic"]
            },
            {
                name: "everythinglm",
                description: "EverythingLM - General purpose model for various tasks",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "general", "versatile"]
            },
            {
                name: "samantha-mistral",
                description: "Samantha Mistral - Companion AI with personality",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "companion", "personality"]
            },
             {
                name: "yi:6b",
                description: "Yi 6B - Chinese AI model with multilingual capabilities",
                size: "~3.4GB",
                sizeCategory: "small",
                tags: ["chat", "multilingual", "chinese", "yi"]
            },
            {
                name: "deepseek-llm",
                description: "DeepSeek LLM - Advanced reasoning and problem-solving",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "reasoning", "deepseek"]
            },
            {
                name: "vicuna",
                description: "Vicuna - Fine-tuned for instruction following",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "instruction", "vicuna"]
            },
            {
                name: "orca2",
                description: "Microsoft Orca 2 - Reasoning and complex tasks",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "reasoning", "microsoft"]
            },
             {
                name: "llava-phi3",
                description: "LLaVA with Phi-3 - Efficient vision model",
                size: "~2.9GB",
                sizeCategory: "small",
                tags: ["vision", "multimodal", "phi3", "efficient"]
            },
            {
                name: "bakllava",
                description: "BakLLaVA - Enhanced vision understanding model",
                size: "~4.4GB",
                sizeCategory: "small",
                tags: ["vision", "multimodal", "enhanced"]
            },
             {
                name: "dolphin-llama3:8b",
                description: "Dolphin Llama 3 - Uncensored version of Llama 3",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["chat", "uncensored", "llama3"]
            },
            {
                name: "wizard-vicuna-uncensored",
                description: "Wizard Vicuna Uncensored - Helpful without restrictions",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["chat", "uncensored", "wizard"]
            },
            {
                name: "nous-hermes2",
                description: "Nous Hermes 2 - Advanced uncensored conversational model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "uncensored", "nous", "advanced"]
            },
            {
                name: "openchat",
                description: "OpenChat - Open and uncensored conversational AI",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "uncensored", "open"]
            },


            // Medium Models (5-15GB)
            {
                name: "llama3:8b", // Note: This is listed as ~4.7GB in ollama list, but often considered an 8B class model. Placing it in small based on reported size.
                description: "Meta Llama 3 8B - Excellent general-purpose model",
                size: "~4.7GB",
                sizeCategory: "small", // Based on reported size
                tags: ["chat", "general", "meta", "popular"]
            },
            {
                name: "llama3.1:8b", // Note: This is listed as ~4.7GB in ollama list. Placing it in small based on reported size.
                description: "Meta Llama 3.1 8B - Improved version with longer context",
                size: "~4.7GB",
                sizeCategory: "small", // Based on reported size
                tags: ["chat", "general", "meta", "latest"]
            },
            {
                name: "codellama:13b",
                description: "Code Llama 13B - Better code understanding and generation",
                size: "~7.3GB",
                sizeCategory: "medium",
                tags: ["code", "programming"]
            },
             {
                name: "starcoder2:15b",
                description: "StarCoder2 15B - Advanced code generation capabilities",
                size: "~8.5GB",
                sizeCategory: "medium",
                tags: ["code", "programming"]
            },
             {
                name: "solar",
                description: "Solar 10.7B - High-performance model from Upstage",
                size: "~6.1GB",
                sizeCategory: "medium",
                tags: ["chat", "specialized", "upstage"]
            },
             {
                name: "llava",
                description: "LLaVA - Large Language and Vision Assistant",
                size: "~4.7GB", // Listed as ~4.7GB, but often considered medium/large capability due to vision
                sizeCategory: "small", // Based on reported size
                tags: ["vision", "multimodal", "image-understanding"]
            },
            {
                name: "llava:13b",
                description: "LLaVA 13B - Better vision and language understanding",
                size: "~7.3GB",
                sizeCategory: "medium",
                tags: ["vision", "multimodal", "image-understanding"]
            },
             {
                name: "llava-llama3",
                description: "LLaVA with Llama 3 - Latest vision model with Llama 3 base",
                size: "~5.5GB",
                sizeCategory: "medium",
                tags: ["vision", "multimodal", "llama3", "latest"]
            },


            // Large Models (> 15GB)
            {
                name: "llama3:70b",
                description: "Meta Llama 3 70B - High-performance large model",
                size: "~39GB",
                sizeCategory: "large",
                tags: ["chat", "general", "meta", "large", "high-performance"]
            },
            {
                name: "llama3.1:70b",
                description: "Meta Llama 3.1 70B - Latest large model with extended context",
                size: "~39GB",
                sizeCategory: "large",
                tags: ["chat", "general", "meta", "latest", "large"]
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
                size: "~77GB",
                sizeCategory: "large",
                tags: ["chat", "general", "mixture-of-experts", "large"]
            },
             {
                name: "qwen2:72b",
                description: "Alibaba Qwen2 72B - Large multilingual model",
                size: "~41GB",
                sizeCategory: "large",
                tags: ["chat", "multilingual", "alibaba", "large"]
            },
            {
                name: "command-r",
                description: "Cohere Command R - Enterprise-grade conversational AI",
                size: "~20GB",
                sizeCategory: "large",
                tags: ["chat", "enterprise", "cohere", "large"]
            },
            {
                name: "command-r-plus",
                description: "Cohere Command R+ - Advanced enterprise model",
                size: "~104GB",
                sizeCategory: "large",
                tags: ["chat", "enterprise", "cohere", "large", "premium"]
            },
             {
                name: "codellama:34b",
                description: "Code Llama 34B - Top-tier code model for complex tasks",
                size: "~19GB",
                sizeCategory: "large",
                tags: ["code", "programming", "large"]
            },
             {
                name: "llava:34b",
                description: "LLaVA 34B - Advanced multimodal capabilities",
                size: "~19GB",
                sizeCategory: "large",
                tags: ["vision", "multimodal", "image-understanding", "large"]
            },
             {
                name: "dolphin-mixtral:8x7b",
                description: "Dolphin Mixtral - Uncensored mixture of experts model",
                size: "~26GB",
                sizeCategory: "large",
                tags: ["chat", "uncensored", "mixture-of-experts", "large"]
            },
            {
                name: "dolphin-llama3:70b",
                description: "Dolphin Llama 3 70B - Large uncensored model",
                size: "~39GB",
                sizeCategory: "large",
                tags: ["chat", "uncensored", "llama3", "large"]
            },
             {
                name: "yi:34b",
                description: "Yi 34B - Large multilingual model from 01.AI",
                size: "~19GB",
                sizeCategory: "large",
                tags: ["chat", "multilingual", "chinese", "yi", "large"]
            },
            {
                name: "goliath",
                description: "Goliath - Large merged model with enhanced capabilities",
                size: "~39GB",
                sizeCategory: "large",
                tags: ["chat", "merged", "enhanced", "large"]
            }
        ];

        // Initialize theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            loadTheme();
            checkOllamaStatus();
            populateFilters(); // Populate filters once
            displayAvailableModels(); // Initial display
        });

        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(tab => tab.classList.remove('active'));
            document.querySelectorAll('.tab-pane').forEach(pane => pane.classList.remove('active'));
            event.target.classList.add('active');
            document.getElementById(tabName).classList.add('active');
            if (tabName === 'installed') {
                loadInstalledModels();
            } else if (tabName === 'available') {
                 filterModels(); // Refresh available list visibility
            }
        }

        function checkOllamaStatus() {
            fetch('?action=check_status')
                .then(response => response.json())
                .then(data => {
                    const statusText = document.getElementById('statusText');
                    const statusDot = document.getElementById('statusDot');
                    if (data.running) {
                        statusText.textContent = 'OLLAMA Online';
                        statusDot.classList.add('online');
                        loadInstalledModels();
                    } else {
                        statusText.textContent = `Ollama Offline - ${data.error || 'Not running or accessible'}`;
                        statusDot.classList.remove('online');
                        const installedContainer = document.getElementById('installedModels');
                        installedContainer.innerHTML = `<div class="alert alert-error">${data.error || 'Ollama is not running or not accessible.'}</div>`;
                         // Also clear available models if offline
                         document.getElementById('availableModels').innerHTML = '<div class="alert alert-info">Ollama is offline. Cannot list or download models.</div>';
                         document.getElementById('modelStats').innerHTML = ''; // Clear stats
                    }
                })
                .catch(error => {
                    console.error('Status check failed:', error);
                    document.getElementById('statusText').textContent = 'Status check failed';
                    document.getElementById('statusDot').classList.remove('online');
                    const installedContainer = document.getElementById('installedModels');
                    installedContainer.innerHTML = '<div class="alert alert-error">Failed to communicate with the server to check Ollama status.</div>';
                     document.getElementById('availableModels').innerHTML = '<div class="alert alert-error">Failed to load available models due to server communication error.</div>';
                     document.getElementById('modelStats').innerHTML = '';
                });
        }

        function loadInstalledModels() {
            const container = document.getElementById('installedModels');
            // Only show loading spinner if Ollama is potentially running and no error is already shown
            if (document.getElementById('statusDot').classList.contains('online') && !container.querySelector('.alert-error')) {
                container.innerHTML = '<div class="loading"><div class="spinner"></div>Loading installed models...</div>';
            } else {
                // If Ollama is offline or an error exists, keep the existing message
                return;
            }

            fetch('?action=list')
                .then(response => response.json())
                .then(data => {
                    if (data.error) {
                        container.innerHTML = `<div class="alert alert-error">${data.error}</div>`;
                        // Also update available models to show error if list fails
                         document.getElementById('availableModels').innerHTML = `<div class="alert alert-error">${data.error}</div>`;
                         document.getElementById('modelStats').innerHTML = '';
                        return;
                    }
                    installedModels = data.models || [];
                    updateInstalledModelsDisplay();
                    filterModels(); // Refresh available list to update buttons
                })
                .catch(error => {
                    console.error('Failed to load models:', error);
                    container.innerHTML = '<div class="alert alert-error">Failed to load installed models from server.</div>';
                     document.getElementById('availableModels').innerHTML = '<div class="alert alert-error">Failed to load available models from server.</div>';
                     document.getElementById('modelStats').innerHTML = '';
                });
        }

        function updateInstalledModelsDisplay() {
            const container = document.getElementById('installedModels');
            if (installedModels.length === 0) {
                container.innerHTML = '<div class="alert alert-info"><i class="fas fa-info-circle"></i> No models installed. Go to Available Models tab to download some!</div>';
            } else {
                let html = '<div class="model-grid">';
                installedModels.forEach(model => {
                    html += `
                        <div class="installed-model-card">
                            <h3>${model.name}</h3>
                            <div class="model-info">
                                <div><strong>Size:</strong> ${model.size}</div>
                                <div><strong>Modified:</strong> ${model.modified || 'N/A'}</div>
                                <div><strong>ID:</strong> ${model.id || 'N/A'}</div>
                            </div>
                            <div class="model-actions">
                                <button class="btn btn-danger" onclick="removeModel('${model.name}')">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </div>
                        </div>
                    `;
                });
                html += '</div>';
                container.innerHTML = html;
            }
        }

        function displayAvailableModels() {
             // Only display if Ollama is online
             if (document.getElementById('statusDot').classList.contains('online')) {
                 filterModels(); // This will display models based on filters
                 updateModelStats();
             } else {
                 // Error message is already placed by checkOllamaStatus
             }
        }

        function filterModels() {
            const searchBox = document.getElementById('searchBox');
            const categoryFilter = document.getElementById('categoryFilter');
            const sizeFilter = document.getElementById('sizeFilter');
            const availableModelsContainer = document.getElementById('availableModels');

            if (!searchBox || !categoryFilter || !sizeFilter || !availableModelsContainer) return;

             // Clear previous content if Ollama is offline
             if (!document.getElementById('statusDot').classList.contains('online')) {
                  // Error message is already placed by checkOllamaStatus
                  return;
             }

            const searchTerm = searchBox.value.toLowerCase();
            const category = categoryFilter.value;
            const size = sizeFilter.value;

            let filteredModels = availableModels.filter(model => {
                const matchesSearch = model.name.toLowerCase().includes(searchTerm) ||
                                    model.description.toLowerCase().includes(searchTerm) ||
                                    model.tags.some(tag => tag.toLowerCase().includes(searchTerm));
                const matchesCategory = !category || model.tags.includes(category);
                const matchesSize = !size || model.sizeCategory === size;
                return matchesSearch && matchesCategory && matchesSize;
            });

            if (filteredModels.length === 0) {
                availableModelsContainer.innerHTML = '<div class="alert alert-info">No models match your filters</div>';
                return;
            }

            let html = '';
            filteredModels.forEach(model => {
                // Improved model detection logic
                // Check multiple possible matches for better detection
                const isInstalled = installedModels.some(installed => {
                    // Exact match
                    if (model.name === installed.name) return true;
                    
                    // Handle cases where installed name might have :latest tag
                    if (model.name === installed.name.replace(':latest', '')) return true;
                    
                    // Handle cases where available model has default tag but installed doesn't
                    if (model.name.includes(':') && model.name.split(':')[0] === installed.name) return true;
                    
                    // Handle cases where installed has tag but available doesn't
                    if (!model.name.includes(':') && installed.name.startsWith(model.name + ':')) return true;
                    
                    // Handle base name matching (remove version tags for comparison)
                    const modelBaseName = model.name.split(':')[0];
                    const installedBaseName = installed.name.split(':')[0];
                    if (modelBaseName === installedBaseName) return true;
                    
                    return false;
                });
                
                const isDownloading = downloadingModels.has(model.name);

                let sizeIndicatorClass = 'size-small';
                if (model.sizeCategory === 'tiny') sizeIndicatorClass = 'size-tiny';
                if (model.sizeCategory === 'medium') sizeIndicatorClass = 'size-medium';
                if (model.sizeCategory === 'large') sizeIndicatorClass = 'size-large';

                 // Get the progress container and update its state if the model is currently downloading
                 const modelId = model.name.replace(/[^a-zA-Z0-9]/g, '-');
                 let progressHtml = '';
                 if (isDownloading) {
                      // If the model is downloading, render the progress container
                     progressHtml = `
                         <div class="progress-container" id="progress-${modelId}" style="display: block;">
                             <div class="progress-bar">
                                 <div class="progress-fill" style="width: 0%;">
                                     <div class="progress-percentage">0%</div>
                                 </div>
                             </div>
                             <div class="progress-text">Starting download...</div>
                         </div>
                     `;
                 } else {
                     // Otherwise, render a hidden progress container
                      progressHtml = `
                         <div class="progress-container" id="progress-${modelId}" style="display: none;">
                             <div class="progress-bar">
                                 <div class="progress-fill" style="width: 0%;">
                                     <div class="progress-percentage">0%</div>
                                 </div>
                             </div>
                             <div class="progress-text">Ready to download</div>
                         </div>
                     `;
                 }

                html += `
                    <div class="available-model" data-model-name="${modelId}">
                        <h4>
                            <span class="size-indicator ${sizeIndicatorClass}"></span>
                            ${model.name}
                        </h4>
                        <p>${model.description}</p>
                        <div class="model-info">
                            <strong>Size:</strong> ${model.size}
                        </div>
                        <div class="model-tags">
                            ${model.tags.map(tag => `<span class="model-tag ${tag}">${tag}</span>`).join('')}
                        </div>
                        <div class="model-actions">
                            ${isInstalled ?
                                '<button class="btn btn-success" disabled><i class="fas fa-check"></i> Downloaded</button>' :
                                `<button class="btn btn-primary download-btn"
                                    data-model-name="${model.name}"
                                    onclick="downloadModel('${model.name}')"
                                    ${isDownloading ? 'disabled' : ''}>
                                    ${isDownloading ? '<i class="fas fa-clock"></i> Downloading...' : '<i class="fas fa-download"></i> Download'}
                                </button>`
                            }
                        </div>
                         ${progressHtml} <!-- Include the progress container -->
                    </div>
                `;
            });
            availableModelsContainer.innerHTML = html;

             // If there are active downloads when filterModels is called,
             // re-attach the EventSource listeners to the elements.
             // This is a simplified approach; a more robust solution would
             // store the EventSource instances and their state globally.
             // However, since the PHP process might die, relying on the initial
             // EventSource from `downloadModel` is best. The key is ensuring
             // the HTML elements exist when updates arrive. The `filterModels`
             // call ensures the HTML structure for downloading models is present.
        }

         function populateFilters() {
            const categoryFilter = document.getElementById('categoryFilter');
            const sizeFilter = document.getElementById('sizeFilter');

            if (!categoryFilter || !sizeFilter) return;

            // Populate Category Filter
            if (categoryFilter.children.length <= 1) { // Check if only "All Categories" is present
                 const categories = [...new Set(availableModels.flatMap(model => model.tags))].sort(); // Get unique sorted tags
                 categories.forEach(cat => {
                    const option = document.createElement('option');
                    option.value = cat;
                    option.textContent = cat.charAt(0).toUpperCase() + cat.slice(1);
                    categoryFilter.appendChild(option);
                });
            }

            // Populate Size Filter
            if (sizeFilter.children.length <= 1) { // Check if only "All Sizes" is present
                const sizes = [
                    {value: 'tiny', label: 'Tiny (<2GB)'},
                    {value: 'small', label: 'Small (2-5GB)'},
                    {value: 'medium', label: 'Medium (5-15GB)'},
                    {value: 'large', label: 'Large (>15GB)'}
                ];
                sizes.forEach(size => {
                    const option = document.createElement('option');
                    option.value = size.value;
                    option.textContent = size.label;
                    sizeFilter.appendChild(option);
                });
            }
        }


        function updateModelStats() {
             const modelStatsDiv = document.getElementById('modelStats');
             if (!modelStatsDiv) return;

             // Only display stats if Ollama is online and models are loaded
             if (!document.getElementById('statusDot').classList.contains('online') || installedModels.length === 0 && !document.getElementById('installedModels').querySelector('.alert-info')) {
                  modelStatsDiv.innerHTML = ''; // Clear stats if not applicable
                  return;
             }


            const totalModels = availableModels.length;
            const tinyModels = availableModels.filter(m => m.sizeCategory === 'tiny').length;
            const smallModels = availableModels.filter(m => m.sizeCategory === 'small').length;
            const mediumModels = availableModels.filter(m => m.sizeCategory === 'medium').length;
            const largeModels = availableModels.filter(m => m.sizeCategory === 'large').length;
            const codeModels = availableModels.filter(m => m.tags.includes('code')).length;
            const visionModels = availableModels.filter(m => m.tags.includes('vision')).length;

            modelStatsDiv.innerHTML = `
                <div><strong>Total Available:</strong> ${totalModels}</div>
                <div><strong>Tiny:</strong> ${tinyModels}</div>
                <div><strong>Small:</strong> ${smallModels}</div>
                <div><strong>Medium:</strong> ${mediumModels}</div>
                <div><strong>Large:</strong> ${largeModels}</div>
                <div><strong>Code:</strong> ${codeModels}</div>
                <div><strong>Vision:</strong> ${visionModels}</div>
            `;
        }


        function downloadModel(modelName) {
            if (downloadingModels.has(modelName)) {
                console.log(`Download already in progress for ${modelName}`);
                return;
            }

             // Find the model card and elements
            const modelId = modelName.replace(/[^a-zA-Z0-9]/g, '-');
            const modelCard = document.querySelector(`.available-model[data-model-name="${modelId}"]`);
            const downloadButton = modelCard ? modelCard.querySelector('.download-btn') : null;
            const progressContainer = document.getElementById(`progress-${modelId}`);
            const progressFill = progressContainer ? progressContainer.querySelector('.progress-fill') : null;
            const progressPercentage = progressContainer ? progressContainer.querySelector('.progress-percentage') : null;
            const progressText = progressContainer ? progressContainer.querySelector('.progress-text') : null;


            if (!progressContainer || !progressFill || !progressPercentage || !progressText) {
                 console.error(`Could not find progress elements for model ${modelName}`);
                 showAlert('error', `UI error: Could not find progress elements for ${modelName}.`);
                 if (downloadButton) {
                      downloadButton.disabled = false;
                      downloadButton.innerHTML = '<i class="fas fa-download"></i> Download';
                 }
                 return;
            }


             // Disable the download button and show progress container
            if (downloadButton) {
                downloadButton.disabled = true;
                downloadButton.innerHTML = '<i class="fas fa-clock"></i> Connecting...';
            }
            progressContainer.style.display = 'block';
            progressText.textContent = 'Connecting...';
            progressFill.style.width = '0%';
            progressPercentage.textContent = '0%';
            progressFill.style.background = 'linear-gradient(45deg, var(--accent), #0056b3)'; // Reset color


            const eventSource = new EventSource(`?action=download&model=${encodeURIComponent(modelName)}`);
            downloadingModels.set(modelName, eventSource);

            console.log(`Starting SSE for download: ${modelName}`);

            // Listen for different event types sent by the PHP script
            eventSource.addEventListener('status', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    console.log('Status event:', data);
                    if (data.message && progressText) {
                         progressText.textContent = data.message;
                         if (downloadButton) {
                             // Update button text with status message if it's not a percentage
                             if (!data.message.includes('%')) {
                                 downloadButton.innerHTML = `<i class="fas fa-clock"></i> ${data.message}`;
                             } else {
                                  // Keep percentage if status message contains it (less common from API stream)
                                   const percentMatch = data.message.match(/(\d+)%/);
                                   if (percentMatch) {
                                        downloadButton.innerHTML = `<i class="fas fa-clock"></i> ${percentMatch[1]}%`;
                                   } else {
                                        // Fallback to generic downloading text
                                        downloadButton.innerHTML = '<i class="fas fa-clock"></i> Downloading...';
                                   }
                             }
                         }
                    }
                } catch (e) {
                    console.error('Failed to parse status message:', e, event.data);
                }
            });

            eventSource.addEventListener('info', function(event) {
                 try {
                    const data = JSON.parse(event.data);
                    console.log('Info event:', data);
                    if (data.message && progressText) {
                        // Update progressText with info messages, but don't overwrite progress
                         if (!progressText.textContent.includes('%') || data.message.includes('already exists')) {
                             progressText.textContent = data.message;
                         }
                    }
                 } catch (e) {
                    console.error('Failed to parse info message:', e, event.data);
                 }
            });

            eventSource.addEventListener('progress', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    console.log('Progress event:', data);
                    const percentage = data.percentage || 0;
                    const message = data.message || 'Processing...';
                    const stage = data.stage || 'unknown';

                    if (progressFill && progressPercentage && progressText) {
                        // Update progress bar
                        progressFill.style.width = percentage + '%';
                        progressPercentage.textContent = percentage + '%';
                        progressText.textContent = message;

                        // Change progress bar color based on stage
                        if (stage === 'complete' || stage === 'success') { // Explicitly check for success stage
                            progressFill.style.background = 'linear-gradient(45deg, var(--success), #20c997)'; // Green
                        } else if (stage === 'verify' || stage === 'verify_digest') {
                            progressFill.style.background = 'linear-gradient(45deg, #fd7e14, #ffc107)'; // Yellow/Orange (Using error color for distinct stage)
                        } else if (stage === 'extracting' || stage === 'finalizing' || stage === 'tagging' || stage === 'writing') {
                             progressFill.style.background = 'linear-gradient(45deg, #17a2b8, #138496)'; // Cyan/Teal
                        } else { // manifest, config, download, layer_pulling, unknown
                             progressFill.style.background = 'linear-gradient(45deg, var(--accent), #0056b3)'; // Blue (Download stage)
                        }

                        // Update button text during progress
                        if (downloadButton) {
                             if (percentage < 100) {
                                 downloadButton.innerHTML = `<i class="fas fa-clock"></i> ${percentage}%`;
                             } else {
                                  // Button will be disabled on success/complete
                                  downloadButton.innerHTML = '<i class="fas fa-check"></i> Installed';
                             }
                        }
                    }


                } catch (e) {
                    console.error('Failed to parse progress message:', e, event.data);
                }
            });

            eventSource.addEventListener('success', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    console.log('Success event:', data);
                    showAlert('success', data.message);

                    // Ensure progress bar is at 100% and green
                    if (progressFill && progressPercentage && progressText) {
                        progressFill.style.width = '100%';
                        progressPercentage.textContent = '100%';
                        progressText.textContent = data.message || 'Download completed!';
                        progressFill.style.background = 'linear-gradient(45deg, var(--success), #20c997)'; // Green
                    }


                } catch (e) {
                    console.error('Failed to parse success message:', e, event.data);
                     showAlert('success', 'Download completed successfully.'); // Show a generic success if message parsing fails
                } finally {
                     // Clean up and refresh regardless of message parsing
                     if (downloadingModels.has(modelName)) {
                         downloadingModels.get(modelName).close();
                         downloadingModels.delete(modelName);
                     }
                    // Refresh installed list and available models display
                    loadInstalledModels(); // This will also call filterModels()
                }
            });

            eventSource.addEventListener('complete', function(event) {
                try {
                    const data = JSON.parse(event.data);
                    console.log('Complete event:', data);

                    // This event signals the server-side process finished.
                    // If status was 'success', the 'success' event already handled it.
                    // If status was 'error', the 'error' event should handle it.
                    // This 'complete' event is mainly for knowing the SSE stream is ending.

                    if (data.status !== 'success' && downloadingModels.has(modelName)) {
                         // Handle cases where 'success' or 'error' might have been missed or the process ended unexpectedly
                         console.warn(`Download process completed with status "${data.status}" but no specific success/error event was processed.`);
                         let finalMessage = data.message || `Download process finished with status: ${data.status}`;
                         if (data.exit_code !== undefined) {
                             finalMessage += ` (code ${data.exit_code})`;
                         }

                         if (progressFill && progressPercentage && progressText) {
                             progressText.textContent = finalMessage;
                             progressFill.style.background = 'linear-gradient(45deg, #c82333)'; // Red
                             progressFill.style.width = '100%'; // Indicate finished state
                             progressPercentage.textContent = 'Error';
                         }
                         showAlert('error', finalMessage);
                    }

                } catch (e) {
                    console.error('Failed to parse complete message:', e, event.data);
                } finally {
                     // Clean up the EventSource regardless
                     if (downloadingModels.has(modelName)) {
                         downloadingModels.get(modelName).close();
                         downloadingModels.delete(modelName);
                     }
                    // Always refresh lists after completion (success or error)
                    loadInstalledModels(); // This will also call filterModels()
                }
            });


            eventSource.addEventListener('error', function(event) {
                console.error('Error event:', event);
                 let errorMessage = 'Download error occurred';
                try {
                    // Check if event.data is available and parseable JSON
                    if (event.data) {
                         const data = JSON.parse(event.data);
                         errorMessage = data.error || data.message || errorMessage;
                         console.log('Error data:', data);
                    } else {
                         // Generic EventSource error without specific data
                         errorMessage = 'Connection error during download.';
                         console.error('EventSource connection error:', event);
                    }

                } catch (e) {
                    console.error('Failed to parse error message:', e, event.data);
                     errorMessage = 'Download error: Invalid response from server.';
                }

                showAlert('error', errorMessage);
                if (progressText && progressFill && progressPercentage) {
                    progressText.textContent = errorMessage;
                    progressFill.style.background = 'linear-gradient(45deg, #c82333)'; // Red
                     // Set width to 100% on error to show completion state
                     progressFill.style.width = '100%';
                     progressPercentage.textContent = 'Error';
                }


            });

            // The generic onerror is a fallback for connection issues before any events are received
            eventSource.onerror = function(event) {
                console.error('EventSource general onerror:', event);
                 // Check if the EventSource is still open before reporting connection error
                 // If it's already closed, a specific error event likely occurred and was handled.
                 if (eventSource.readyState === EventSource.CLOSED) {
                      console.log("EventSource was already closed by a specific handler.");
                      return;
                 }

                let errorMessage = 'Connection error during download. Is Ollama running and accessible on http://localhost:11434?';

                 // Attempt to get more specific error if available
                 if (event.message) {
                     errorMessage += ` (${event.message})`;
                 }


                showAlert('error', errorMessage);
                if (progressText && progressFill && progressPercentage) {
                    progressText.textContent = 'Connection error!';
                    progressFill.style.background = 'linear-gradient(45deg, #c82333)'; // Red
                     progressFill.style.width = '100%';
                     progressPercentage.textContent = 'Error';
                }


            };

             // Add a timeout for the initial connection (e.g., 15 seconds)
            const initialTimeout = setTimeout(() => {
                 if (eventSource.readyState === EventSource.CONNECTING) {
                     console.warn('EventSource connection timed out.');
                     // Close the event source to prevent further issues
                     if (downloadingModels.has(modelName)) {
                          downloadingModels.get(modelName).close();
                          downloadingModels.delete(modelName);
                     }
                     showAlert('error', 'Connection timeout: Could not connect to download stream.');
                     if (progressText && progressFill && progressPercentage) {
                         progressText.textContent = 'Connection timeout!';
                         progressFill.style.background = 'linear-gradient(45deg, #c82333)';
                         progressFill.style.width = '100%';
                         progressPercentage.textContent = 'Error';
                     }
                     filterModels(); // Update button state
                 }
            }, 15000); // 15 seconds

             // Add a timeout for overall download process (e.g., 30 minutes)
            const downloadTimeout = setTimeout(() => {
                 if (downloadingModels.has(modelName)) {
                     console.warn('Download process timed out.');
                     // Close the event source
                     downloadingModels.get(modelName).close();
                     downloadingModels.delete(modelName);

                     showAlert('error', 'Download process timed out. Please check Ollama logs or try again.');
                     if (progressText && progressFill && progressPercentage) {
                         progressText.textContent = 'Download timed out!';
                         progressFill.style.background = 'linear-gradient(45deg, #c82333)';
                         progressFill.style.width = '100%';
                         progressPercentage.textContent = 'Error';
                     }
                     filterModels(); // Update button state
                 }
            }, 1800000); // 30 minutes (1800 * 1000 ms)

             // Clear timeouts when the download finishes (success, error, or complete events)
             const clearAllTimeouts = () => {
                 clearTimeout(initialTimeout);
                 clearTimeout(downloadTimeout);
             };
             eventSource.addEventListener('success', clearAllTimeouts);
             eventSource.addEventListener('complete', clearAllTimeouts);
             eventSource.addEventListener('error', clearAllTimeouts);
             eventSource.onerror = clearAllTimeouts; // Also clear on generic error
        }

        function removeModel(modelName) {
            if (!confirm(`Are you sure you want to remove the model "${modelName}"?`)) {
                return;
            }
             // Find the model card to potentially show a removing status
            // Note: This finds the *first* installed model card. If multiple exist with the same name (unlikely for installed), this might be ambiguous.
            const installedCards = document.querySelectorAll('.installed-model-card');
            let modelCard = null;
            for(const card of installedCards) {
                 if (card.querySelector('h3')?.textContent.trim() === modelName) {
                      modelCard = card;
                      break;
                 }
            }

             let removeButton = null;
             if (modelCard) {
                 removeButton = modelCard.querySelector('.btn-danger');
                 if (removeButton) {
                      removeButton.disabled = true;
                      removeButton.innerHTML = '<i class="fas fa-clock"></i> Removing...';
                 }
             }


            fetch('?action=remove', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `model=${encodeURIComponent(modelName)}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.error) {
                    showAlert('error', data.error);
                     // Restore button state if removal failed
                     if (removeButton) {
                          removeButton.disabled = false;
                          removeButton.innerHTML = '<i class="fas fa-trash"></i> Remove';
                     }
                } else {
                    showAlert('success', data.message);
                    loadInstalledModels(); // Refresh the list
                }
            })
            .catch(error => {
                console.error('Remove failed:', error);
                showAlert('error', 'Failed to remove model.');
                 // Restore button state if removal failed
                if (removeButton) {
                     removeButton.disabled = false;
                     removeButton.innerHTML = '<i class="fas fa-trash"></i> Remove';
                }
            });
        }

        function showAlert(type, message) {
            const alertsDiv = document.getElementById('alerts');
            // Clear previous alerts (optional, but good for single-alert display)
            // alertsDiv.innerHTML = '';
             // Append new alert
            const alertElement = document.createElement('div');
            alertElement.classList.add('alert', `alert-${type}`);
            alertElement.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
                <button type="button" class="close" onclick="this.parentElement.remove()">
                    &times;
                </button>
            `;
             alertsDiv.appendChild(alertElement);


            // Optional: Auto-dismiss after a few seconds
            setTimeout(() => {
                if (alertElement && alertElement.parentElement === alertsDiv) {
                    alertElement.remove();
                }
            }, 7000); // 7 seconds
        }
    </script>
</body>
</html>
<?php
}
?>