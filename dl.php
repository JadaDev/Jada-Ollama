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
    $cmd = "ollama list 2>&1";
    $output = shell_exec($cmd);

    // Check for common errors indicating Ollama isn't running or command failed
    if ($output === null || strpos($output, 'Error: ') !== false || strpos($output, 'connection refused') !== false || strpos($output, 'not recognized') !== false || strpos($output, 'command not found') !== false) {
        $error_message = 'Ollama is not running or not accessible.';
        if (strpos($output, 'connection refused') !== false) {
            $error_message = 'Ollama connection refused. Is it running?';
        } elseif (strpos($output, 'not recognized') !== false || strpos($output, 'command not found') !== false) {
            $error_message = 'Ollama command not found. Is Ollama installed and in your PATH?';
        } elseif ($output !== null && !empty(trim($output))) {
            $error_message = 'Ollama command returned unexpected output: ' . trim($output);
        }
        echo json_encode(['error' => $error_message]);
        exit;
    }

    $models = [];
    $lines = explode("\n", trim($output));

    if (count($lines) > 0 && strpos(trim($lines[0]), 'NAME') === 0) {
        for ($i = 1; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line)) continue;

            $parts = preg_split('/\s{2,}/', $line);

            if (count($parts) >= 4) {
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
            }
        }
    } else {
        echo json_encode(['error' => 'Unexpected output format from ollama list. Output: ' . trim($output)]);
        exit;
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

    $cmd = "ollama rm " . escapeshellarg($model) . " 2>&1";
    $output = shell_exec($cmd);

    if (strpos($output, 'error') !== false || strpos($output, 'Error') !== false || strpos($output, 'failed') !== false || strpos($output, 'not found') !== false) {
        echo json_encode(['error' => trim($output)]);
    } else {
        echo json_encode(['success' => true, 'message' => "Model $model removed successfully", 'output' => trim($output)]);
    }
    exit;
}

function handleCheckStatus() {
    header('Content-Type: application/json');
    
    // Check if ollama command exists
    $cmd = "where ollama 2>nul";
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        $cmd = "which ollama 2>/dev/null";
    }
    
    $ollamaPath = shell_exec($cmd);
    
    if (empty(trim($ollamaPath))) {
        echo json_encode([
            'running' => false,
            'installed' => false,
            'error' => 'Ollama is not installed or not in your system PATH.',
            'instructions' => [
                'Download and install Ollama from https://ollama.ai',
                'Make sure Ollama is added to your system PATH',
                'Restart your command prompt/terminal after installation',
                'Run "ollama serve" to start the Ollama service'
            ]
        ]);
        exit;
    }
    
    // Check if Ollama service is running
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
        $cmd = "ollama --version 2>&1";
        $output = shell_exec($cmd);
        $version = 'Unknown';
        
        if (strpos($output, 'ollama version') !== false) {
            $version = trim(str_replace('ollama version', '', $output));
        }
        
        echo json_encode([
            'running' => true,
            'installed' => true,
            'version' => $version,
            'path' => trim($ollamaPath)
        ]);
    } else {
        echo json_encode([
            'running' => false,
            'installed' => true,
            'error' => 'Ollama is installed but not running.',
            'path' => trim($ollamaPath),
            'instructions' => [
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
            {
                name: "deepseek-r1",
                description: "DeepSeek R1 - Advanced reasoning model with thinking capabilities",
                size: "~1.5GB",
                sizeCategory: "tiny",
                tags: ["chat", "reasoning", "thinking", "deepseek"]
            },
            {
                name: "tinyllama",
                description: "TinyLlama - Ultra-lightweight model for basic tasks",
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
                name: "gemma2:2b",
                description: "Google Gemma 2 2B - Compact but capable model",
                size: "~1.6GB",
                sizeCategory: "tiny",
                tags: ["chat", "small", "google", "efficient"]
            },
            {
                name: "mistral",
                description: "Mistral 7B - Fast and efficient general-purpose model",
                size: "~4.1GB",
                sizeCategory: "small",
                tags: ["chat", "general", "fast", "small"]
            },
            {
                name: "llama3.2",
                description: "Meta Llama 3.2 - Latest compact model with vision capabilities",
                size: "~4.7GB",
                sizeCategory: "small",
                tags: ["chat", "vision", "meta", "latest", "multimodal"]
            },
            {
                name: "codellama:7b",
                description: "Code Llama 7B - Specialized for code generation and understanding",
                size: "~3.8GB",
                sizeCategory: "small",
                tags: ["code", "programming", "small"]
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
                tags: ["chat", "general", "meta", "latest"]
            },
            {
                name: "mixtral:8x7b",
                description: "Mistral 8x7B MoE - Mixture of experts model with excellent performance",
                size: "~26GB",
                sizeCategory: "large",
                tags: ["chat", "general", "mixture-of-experts", "large"]
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

        .available-model {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .available-model:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }

        .available-model.installed {
            border-color: var(--success);
            background: linear-gradient(135deg, var(--bg-secondary), rgba(56, 161, 105, 0.05));
        }

        .available-model.downloading {
            border-color: var(--accent);
            background: linear-gradient(135deg, var(--bg-secondary), rgba(0, 212, 170, 0.05));
        }

        .installed-model-card {
            background: var(--bg-secondary);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            transition: all 0.3s ease;
        }

        .model-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 12px;
        }

        .model-header h3 {
            margin: 0;
            color: var(--text-primary);
            font-size: 1.2em;
        }

        .model-size {
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8em;
            font-weight: 500;
        }

        .installed-badge {
            background: var(--success);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            font-weight: 500;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .model-description {
            color: var(--text-secondary);
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .model-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-bottom: 16px;
        }

        .tag {
            background: var(--bg-tertiary);
            color: var(--text-secondary);
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.75em;
            border: 1px solid var(--border);
        }

        .model-actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }

        .model-info {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 16px;
        }

        .info-item {
            display: flex;
            align-items: center;
            gap: 8px;
            color: var(--text-secondary);
            font-size: 0.9em;
        }

        .info-item i {
            width: 16px;
            color: var(--accent);
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