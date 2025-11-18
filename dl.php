<?php
error_reporting(E_ALL);
ini_set("display_errors", 1);

$action = $_GET["action"] ?? "";
switch ($action) {
    case "list": listModels(); break;
    case "download": downloadModel(); break;
    case "remove": removeModel(); break;
    case "status": checkStatus(); break;
    default: showInterface();
}

function formatBytes($bytes) {
    $units = ["B", "KB", "MB", "GB", "TB"];
    $pow = min(floor(($bytes ? log($bytes) : 0) / log(1024)), count($units) - 1);
    return round($bytes / pow(1024, $pow), 2) . " " . $units[$pow];
}

function sendEvent($name, $data) {
    echo "event: $name\ndata: " . json_encode($data) . "\n\n";
    ob_flush(); flush();
}

function listModels() {
    header("Content-Type: application/json");
    $ch = curl_init("http://localhost:11434/api/tags");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($code !== 200) {
        echo json_encode(["error" => "Ollama not accessible"]);
        exit;
    }
    
    $data = json_decode($response, true);
    $models = [];
    foreach ($data["models"] ?? [] as $m) {
        $models[] = [
            "name" => $m["name"], 
            "size" => formatBytes($m["size"]), 
            "modified" => date("Y-m-d H:i", strtotime($m["modified_at"]))
        ];
    }
    echo json_encode(["models" => $models]);
    exit;
}

function downloadModel() {
    header("Content-Type: text/event-stream");
    header("Cache-Control: no-cache");
    header("X-Accel-Buffering: no");
    $model = $_GET["model"] ?? "";
    if (empty($model)) { sendEvent("error", ["error" => "Model name required"]); exit; }
    
    sendEvent("status", ["message" => "Starting download..."]);
    $ch = curl_init("http://localhost:11434/api/pull");
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["name" => $model]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1800);
    curl_setopt($ch, CURLOPT_WRITEFUNCTION, function($c, $d) {
        static $buf = "";
        $buf .= $d;
        while (($p = strpos($buf, "\n")) !== false) {
            $line = substr($buf, 0, $p);
            $buf = substr($buf, $p + 1);
            $j = json_decode($line, true);
            if ($j && isset($j["error"])) { sendEvent("error", ["error" => $j["error"]]); return 0; }
            if ($j && isset($j["status"])) {
                $pct = (isset($j["total"]) && $j["total"] > 0) ? round(($j["completed"] / $j["total"]) * 100, 1) : 0;
                sendEvent("progress", ["message" => $j["status"], "percentage" => $pct]);
                if ($j["status"] === "success") sendEvent("success", ["message" => "Download complete!"]);
            }
        }
        return strlen($d);
    });
    curl_exec($ch);
    curl_close($ch);
    exit;
}

function removeModel() {
    header("Content-Type: application/json");
    $model = $_POST["model"] ?? "";
    if (empty($model)) { echo json_encode(["error" => "Model required"]); exit; }
    
    $ch = curl_init("http://localhost:11434/api/delete");
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "DELETE");
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(["name" => $model]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    echo json_encode($code === 200 ? ["success" => true, "message" => "Model removed successfully"] : ["error" => "Failed to remove model"]);
    exit;
}

function checkStatus() {
    header("Content-Type: application/json");
    $ch = curl_init("http://localhost:11434/api/tags");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    echo json_encode([
        "running" => $code === 200, 
        "version" => trim(@shell_exec("ollama --version 2>&1") ?: "Unknown")
    ]);
    exit;
}

function showInterface() { 
    $models = [
        ["name"=>"tinyllama","desc"=>"Ultra-lightweight 1.1B model for testing and simple tasks","size"=>"~637MB","category"=>"tiny"],
        ["name"=>"tinydolphin","desc"=>"Tiny but capable instruction-tuned model","size"=>"~636MB","category"=>"tiny"],
        
        ["name"=>"phi3:mini","desc"=>"Microsoft Phi-3 Mini - efficient and capable 3.8B model","size"=>"~2.3GB","category"=>"small"],
        ["name"=>"phi3:medium","desc"=>"Microsoft Phi-3 Medium - balanced performance","size"=>"~7.9GB","category"=>"small"],
        ["name"=>"gemma:2b","desc"=>"Google Gemma 2B - compact but powerful","size"=>"~1.4GB","category"=>"small"],
        ["name"=>"stablelm2","desc"=>"StabilityAI's efficient language model","size"=>"~1.6GB","category"=>"small"],
        
        ["name"=>"llama3.2:3b","desc"=>"Meta Llama 3.2 3B - latest compact model","size"=>"~2.0GB","category"=>"medium"],
        ["name"=>"llama3.1:8b","desc"=>"Meta Llama 3.1 8B - excellent general purpose model","size"=>"~4.7GB","category"=>"medium"],
        ["name"=>"llama3:8b","desc"=>"Meta Llama 3 8B - proven reliable performance","size"=>"~4.7GB","category"=>"medium"],
        ["name"=>"mistral:7b","desc"=>"Mistral 7B v0.3 - fast and capable general-purpose","size"=>"~4.1GB","category"=>"medium"],
        ["name"=>"mistral-nemo","desc"=>"Mistral Nemo 12B - enhanced capabilities","size"=>"~7.1GB","category"=>"medium"],
        ["name"=>"qwen2.5:7b","desc"=>"Alibaba Qwen 2.5 7B - excellent multilingual support","size"=>"~4.4GB","category"=>"medium"],
        ["name"=>"gemma2:9b","desc"=>"Google Gemma 2 9B - enhanced version with better reasoning","size"=>"~5.4GB","category"=>"medium"],
        ["name"=>"aya:8b","desc"=>"Cohere Aya 8B - multilingual specialist (101 languages)","size"=>"~4.8GB","category"=>"medium"],
        ["name"=>"nous-hermes2:10.7b","desc"=>"Nous Hermes 2 - fine-tuned for instruction following","size"=>"~6.2GB","category"=>"medium"],
        
        ["name"=>"deepseek-r1:7b","desc"=>"DeepSeek R1 7B - specialized reasoning model","size"=>"~4.1GB","category"=>"reasoning"],
        ["name"=>"deepseek-r1:8b","desc"=>"DeepSeek R1 8B - advanced reasoning capabilities","size"=>"~4.9GB","category"=>"reasoning"],
        ["name"=>"deepseek-r1:14b","desc"=>"DeepSeek R1 14B - superior reasoning performance","size"=>"~9.0GB","category"=>"reasoning"],
        ["name"=>"deepseek-r1:32b","desc"=>"DeepSeek R1 32B - top-tier reasoning model","size"=>"~19GB","category"=>"reasoning"],
        ["name"=>"qwen2.5-coder:7b","desc"=>"Qwen 2.5 Coder - optimized for programming","size"=>"~4.4GB","category"=>"reasoning"],
        
        ["name"=>"codellama:7b","desc"=>"Meta Code Llama 7B - code generation specialist","size"=>"~3.8GB","category"=>"code"],
        ["name"=>"codellama:13b","desc"=>"Meta Code Llama 13B - enhanced coding capabilities","size"=>"~7.4GB","category"=>"code"],
        ["name"=>"codegemma:7b","desc"=>"Google CodeGemma 7B - code-focused model","size"=>"~5.0GB","category"=>"code"],
        ["name"=>"deepseek-coder:6.7b","desc"=>"DeepSeek Coder - excellent for programming tasks","size"=>"~3.8GB","category"=>"code"],
        ["name"=>"starcoder2:7b","desc"=>"StarCoder2 7B - trained on 80+ programming languages","size"=>"~4.0GB","category"=>"code"],
        ["name"=>"wizard-vicuna-uncensored","desc"=>"Wizard Vicuna 13B - versatile coding assistant","size"=>"~7.4GB","category"=>"code"],
        
        ["name"=>"llama3.1:70b","desc"=>"Meta Llama 3.1 70B - highly capable flagship model","size"=>"~40GB","category"=>"large"],
        ["name"=>"llama3:70b","desc"=>"Meta Llama 3 70B - proven high performance","size"=>"~40GB","category"=>"large"],
        ["name"=>"mixtral:8x7b","desc"=>"Mistral MoE 8x7B - excellent performance-to-size ratio","size"=>"~26GB","category"=>"large"],
        ["name"=>"mixtral:8x22b","desc"=>"Mistral MoE 8x22B - enhanced mixture of experts","size"=>"~80GB","category"=>"large"],
        ["name"=>"qwen2.5:72b","desc"=>"Alibaba Qwen 2.5 72B - top-tier multilingual model","size"=>"~41GB","category"=>"large"],
        ["name"=>"command-r-plus","desc"=>"Cohere Command R+ 104B - enterprise-grade performance","size"=>"~59GB","category"=>"large"],
        ["name"=>"wizardlm2:8x22b","desc"=>"WizardLM2 8x22B - powerful mixture of experts","size"=>"~80GB","category"=>"large"],
        
        ["name"=>"llava:7b","desc"=>"LLaVA 7B - image understanding and description","size"=>"~4.7GB","category"=>"vision"],
        ["name"=>"llava:13b","desc"=>"LLaVA 13B - enhanced vision capabilities","size"=>"~8.0GB","category"=>"vision"],
        ["name"=>"bakllava","desc"=>"BakLLaVA - efficient vision-language model","size"=>"~4.7GB","category"=>"vision"],
        ["name"=>"llava-phi3","desc"=>"LLaVA Phi3 - compact vision model","size"=>"~2.9GB","category"=>"vision"],
        
        ["name"=>"solar","desc"=>"Upstage Solar 10.7B - high-performance fine-tuned model","size"=>"~6.1GB","category"=>"specialized"],
        ["name"=>"openchat","desc"=>"OpenChat 7B - optimized for conversational AI","size"=>"~4.1GB","category"=>"specialized"],
        ["name"=>"starling-lm","desc"=>"Starling LM 7B - RLHF-tuned for helpfulness","size"=>"~4.1GB","category"=>"specialized"],
        ["name"=>"vicuna:7b","desc"=>"Vicuna 7B - fine-tuned for chat applications","size"=>"~3.8GB","category"=>"specialized"],
        ["name"=>"vicuna:13b","desc"=>"Vicuna 13B - enhanced conversational capabilities","size"=>"~7.4GB","category"=>"specialized"],
        ["name"=>"orca-mini","desc"=>"Orca Mini 3B - efficient instruction-following","size"=>"~1.9GB","category"=>"specialized"],
        ["name"=>"neural-chat","desc"=>"Intel Neural Chat 7B - optimized for dialogue","size"=>"~4.1GB","category"=>"specialized"],
        ["name"=>"falcon:7b","desc"=>"TII Falcon 7B - powerful open-source model","size"=>"~3.8GB","category"=>"specialized"],
        ["name"=>"zephyr","desc"=>"Zephyr 7B - fine-tuned Mistral for chat","size"=>"~4.1GB","category"=>"specialized"],
        ["name"=>"dolphin-mistral","desc"=>"Dolphin Mistral 7B - uncensored and versatile","size"=>"~4.1GB","category"=>"specialized"],
        ["name"=>"dolphin-mixtral","desc"=>"Dolphin Mixtral 8x7B - uncensored MoE model","size"=>"~26GB","category"=>"specialized"],
        ["name"=>"yi:6b","desc"=>"01.AI Yi 6B - efficient bilingual model (EN/CN)","size"=>"~3.5GB","category"=>"specialized"],
        ["name"=>"yi:34b","desc"=>"01.AI Yi 34B - powerful bilingual capabilities","size"=>"~19GB","category"=>"specialized"],
    ]; 
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1.0">
    <title>Ollama Model Manager</title>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="styles.css?v=<?=time()?>">
    <style>
        body{margin:0;min-height:100vh;display:flex;flex-direction:column}
        .dl-main-content{flex:1;padding:24px;max-width:1400px;margin:0 auto;width:100%}
        .dl-filter-bar{display:flex;gap:12px;margin-bottom:24px;align-items:center;flex-wrap:wrap}
        .dl-search-bar{flex:1;min-width:250px}
        .dl-search-bar input{width:100%;padding:12px 16px;border:1px solid var(--border);background:var(--bg-secondary);border-radius:8px;color:var(--text-primary);font-size:1em}
        .dl-filter-toggle{display:flex;gap:8px;flex-wrap:wrap}
        .dl-filter-btn{padding:10px 20px;border:1px solid var(--border);background:var(--bg-secondary);border-radius:8px;cursor:pointer;transition:all .2s;color:var(--text-secondary);font-size:.95em}
        .dl-filter-btn:hover{background:var(--bg-tertiary)}
        .dl-filter-btn.active{background:var(--accent);color:white;border-color:var(--accent)}
        .dl-category-filters{display:flex;gap:8px;flex-wrap:wrap;width:100%}
        .dl-model-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(380px,1fr));gap:20px}
        .dl-model-card{background:var(--bg-secondary);border:1px solid var(--border);border-radius:12px;padding:20px;transition:all .3s;position:relative}
        .dl-model-card.installed{border-color:var(--success);box-shadow:0 0 0 2px rgba(76,175,80,.2)}
        .dl-model-card:hover{transform:translateY(-4px);box-shadow:0 8px 25px rgba(0,0,0,.15)}
        .dl-installed-badge{position:absolute;top:12px;right:12px;background:var(--success);color:white;padding:4px 12px;border-radius:12px;font-size:.8em;font-weight:600;display:flex;align-items:center;gap:4px}
        .dl-category-badge{position:absolute;top:12px;left:12px;background:var(--accent);color:white;padding:4px 10px;border-radius:12px;font-size:.75em;font-weight:600;text-transform:uppercase}
        .dl-model-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:12px;gap:12px;padding:0 90px 0 80px}
        .dl-model-header h3{margin:0;color:var(--text-primary);font-size:1.3em;flex:1}
        .dl-model-size{background:var(--accent);color:white;padding:6px 12px;border-radius:20px;font-size:.8em;font-weight:600;white-space:nowrap}
        .dl-model-desc{color:var(--text-secondary);margin-bottom:16px;font-size:.95em;line-height:1.5;min-height:42px}
        .dl-model-actions{display:flex;gap:10px;flex-wrap:wrap}
        .dl-model-actions .dl-btn{flex:1;min-width:140px;padding:10px 16px;border:none;border-radius:8px;cursor:pointer;transition:all .2s;display:flex;align-items:center;justify-content:center;gap:6px;font-size:.95em;font-weight:500}
        .dl-btn-primary{background:var(--accent);color:white}
        .dl-btn-primary:hover:not(:disabled){background:var(--accent-hover);transform:translateY(-2px)}
        .dl-btn-danger{background:var(--error);color:white}
        .dl-btn-danger:hover:not(:disabled){background:#dc2626;transform:translateY(-2px)}
        .dl-btn-success{background:var(--success);color:white}
        .dl-btn-success:hover:not(:disabled){transform:translateY(-2px)}
        .dl-btn:disabled{opacity:.6;cursor:not-allowed}
        .dl-status{display:flex;align-items:center;gap:8px}
        .dl-status-dot{width:10px;height:10px;border-radius:50%;background:var(--error)}
        .dl-status-dot.online{background:var(--success)}
        .dl-progress-bar{height:8px;background:var(--bg-tertiary);border-radius:4px;overflow:hidden;margin:8px 0}
        .dl-progress-fill{height:100%;background:var(--accent);transition:width .3s}
        .dl-progress-text{text-align:center;font-size:.9em;color:var(--text-secondary);margin-bottom:8px}
        .dl-alert{padding:16px;border-radius:8px;margin-bottom:16px;display:flex;align-items:center;gap:12px}
        .dl-alert-warning{background:rgba(255,193,7,.1);border:1px solid #ffc107;color:var(--text-primary)}
        .dl-alert-info{background:rgba(33,150,243,.1);border:1px solid #2196f3;color:var(--text-primary)}
        .dl-alert-error{background:rgba(244,67,54,.1);border:1px solid #f44336;color:var(--text-primary)}
        .dl-alert-success{background:rgba(76,175,80,.1);border:1px solid #4caf50;color:var(--text-primary)}
        .dl-loading{text-align:center;padding:40px;color:var(--text-secondary)}
        .dl-spinner{border:3px solid var(--border);border-top-color:var(--accent);border-radius:50%;width:40px;height:40px;animation:dl-spin 1s linear infinite;margin:0 auto 16px}
        .dl-stats-bar{display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap}
        .dl-stat-card{background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;padding:16px 20px;flex:1;min-width:200px}
        .dl-stat-card h4{margin:0 0 8px 0;color:var(--text-secondary);font-size:.9em;font-weight:500}
        .dl-stat-card .value{font-size:1.8em;font-weight:700;color:var(--text-primary)}
        @keyframes dl-spin{to{transform:rotate(360deg)}}
        @media(max-width:768px){
            .dl-model-grid{grid-template-columns:1fr}
            .dl-main-content{padding:16px}
            .dl-filter-bar{flex-direction:column}
            .dl-filter-toggle,.dl-category-filters{width:100%;justify-content:stretch}
            .dl-filter-btn{flex:1}
            .dl-model-header{padding:0 90px 0 0}
            .dl-category-badge{display:none}
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-left">
            <a href="index.php" class="header-btn" title="Back to Chat"><i class="fas fa-arrow-left"></i></a>
            <div class="logo">
                <i class="fas fa-download"></i>
                <span>Model Manager</span>
            </div>
        </div>
        <div class="header-right">
            <div class="dl-status">
                <div id="statusDot" class="dl-status-dot"></div>
                <div id="statusText">Checking...</div>
            </div>
            <button class="header-btn" onclick="toggleTheme()" title="Toggle Theme">
                <i id="themeIcon" class="fas fa-moon"></i>
            </button>
        </div>
    </div>

    <div class="dl-main-content">
        <div id="alerts"></div>
        
        <div class="dl-stats-bar" id="statsBar" style="display:none">
            <div class="dl-stat-card">
                <h4><i class="fas fa-database"></i> Installed Models</h4>
                <div class="value" id="installedCount">0</div>
            </div>
            <div class="dl-stat-card">
                <h4><i class="fas fa-download"></i> Available Models</h4>
                <div class="value" id="availableCount">0</div>
            </div>
            <div class="dl-stat-card">
                <h4><i class="fas fa-hdd"></i> Total Size</h4>
                <div class="value" id="totalSize">0 GB</div>
            </div>
        </div>

        <div class="dl-filter-bar">
            <div class="dl-search-bar">
                <input type="text" id="searchBox" placeholder="Search models by name or description..." onkeyup="filterModels()">
            </div>
            <div class="dl-filter-toggle">
                <button class="dl-filter-btn active" onclick="setStatusFilter('all')" data-filter="all">
                    <i class="fas fa-globe"></i> All Models
                </button>
                <button class="dl-filter-btn" onclick="setStatusFilter('installed')" data-filter="installed">
                    <i class="fas fa-check-circle"></i> Installed
                </button>
                <button class="dl-filter-btn" onclick="setStatusFilter('available')" data-filter="available">
                    <i class="fas fa-download"></i> Available
                </button>
            </div>
        </div>

        <div class="dl-category-filters">
            <button class="dl-filter-btn active" onclick="setCategoryFilter('all')" data-category="all">All Categories</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('tiny')" data-category="tiny">Tiny</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('small')" data-category="small">Small</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('medium')" data-category="medium">Medium</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('large')" data-category="large">Large</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('code')" data-category="code">Code</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('reasoning')" data-category="reasoning">Reasoning</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('vision')" data-category="vision">Vision</button>
            <button class="dl-filter-btn" onclick="setCategoryFilter('specialized')" data-category="specialized">Specialized</button>
        </div>

        <div id="modelsContainer" class="dl-loading">
            <div class="dl-spinner"></div>
            Loading models...
        </div>
    </div>

    <script>
        const availableModels = <?=json_encode($models)?>;
        let installedModels = [];
        let downloads = new Map();
        let ollamaRunning = false;
        let currentStatusFilter = "all";
        let currentCategoryFilter = "all";

        function loadTheme() {
            const theme = localStorage.getItem("ollama_theme") || "dark";
            document.documentElement.setAttribute("data-theme", theme);
            document.getElementById("themeIcon").className = theme === "light" ? "fas fa-sun" : "fas fa-moon";
        }

        function toggleTheme() {
            const current = document.documentElement.getAttribute("data-theme");
            const newTheme = current === "light" ? "dark" : "light";
            document.documentElement.setAttribute("data-theme", newTheme);
            localStorage.setItem("ollama_theme", newTheme);
            document.getElementById("themeIcon").className = newTheme === "light" ? "fas fa-sun" : "fas fa-moon";
        }

        function checkStatus() {
            fetch("?action=status")
                .then(r => r.json())
                .then(data => {
                    ollamaRunning = data.running;
                    document.getElementById("statusDot").classList.toggle("online", data.running);
                    document.getElementById("statusText").textContent = data.running 
                        ? "Ollama Online (" + data.version + ")" 
                        : "Ollama Offline";
                    loadModels();
                })
                .catch(() => {
                    ollamaRunning = false;
                    document.getElementById("statusText").textContent = "Connection Error";
                    showModels();
                });
        }

        function loadModels() {
            if (!ollamaRunning) {
                showModels();
                return;
            }

            fetch("?action=list")
                .then(r => r.json())
                .then(data => {
                    if (data.error) {
                        installedModels = [];
                    } else {
                        installedModels = data.models || [];
                    }
                    updateStats();
                    showModels();
                })
                .catch(() => {
                    installedModels = [];
                    showModels();
                });
        }

        function updateStats() {
            const statsBar = document.getElementById("statsBar");
            const installedCount = installedModels.length;
            const availableCount = availableModels.length;
            
            document.getElementById("installedCount").textContent = installedCount;
            document.getElementById("availableCount").textContent = availableCount;
            
            let totalGB = 0;
            installedModels.forEach(m => {
                const sizeStr = m.size;
                const match = sizeStr.match(/([\d.]+)\s*(GB|MB)/);
                if (match) {
                    const num = parseFloat(match[1]);
                    totalGB += match[2] === 'GB' ? num : num / 1024;
                }
            });
            document.getElementById("totalSize").textContent = totalGB.toFixed(1) + " GB";
            
            statsBar.style.display = installedCount > 0 ? "flex" : "none";
        }

        function isInstalled(modelName) {
            return installedModels.some(m => {
                const baseName = modelName.split(":")[0];
                const installedBaseName = m.name.split(":")[0];
                return installedBaseName === baseName || m.name === modelName;
            });
        }

        function setStatusFilter(filter) {
            currentStatusFilter = filter;
            document.querySelectorAll('.dl-filter-toggle .dl-filter-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.filter === filter);
            });
            filterModels();
        }

        function setCategoryFilter(category) {
            currentCategoryFilter = category;
            document.querySelectorAll('.dl-category-filters .dl-filter-btn').forEach(btn => {
                btn.classList.toggle('active', btn.dataset.category === category);
            });
            filterModels();
        }

        function filterModels() {
            const searchTerm = document.getElementById("searchBox").value.toLowerCase();
            document.querySelectorAll(".dl-model-card").forEach(card => {
                const name = card.querySelector("h3").textContent.toLowerCase();
                const desc = card.querySelector(".dl-model-desc").textContent.toLowerCase();
                const category = card.dataset.category;
                const isInstalled = card.classList.contains("installed");

                const matchesSearch = name.includes(searchTerm) || desc.includes(searchTerm);

                let matchesStatus = false;
                if (currentStatusFilter === "all") matchesStatus = true;
                else if (currentStatusFilter === "installed") matchesStatus = isInstalled;
                else if (currentStatusFilter === "available") matchesStatus = !isInstalled;

                const matchesCategory = currentCategoryFilter === "all" || category === currentCategoryFilter;

                card.style.display = (matchesSearch && matchesStatus && matchesCategory) ? "" : "none";
            });
        }

        function showModels() {
            const container = document.getElementById("modelsContainer");

            if (!ollamaRunning) {
                container.innerHTML = '<div class="dl-alert dl-alert-warning"><i class="fas fa-exclamation-triangle"></i><span>Ollama is not running. Start Ollama to download and manage models.</span></div>';
                let html = '<div class="dl-model-grid">';
                availableModels.forEach(model => {
                    html += createModelCard(model, false, true);
                });
                html += '</div>';
                container.innerHTML += html;
                return;
            }

            let html = '<div class="dl-model-grid">';
            availableModels.forEach(model => {
                const installed = isInstalled(model.name);
                const downloading = downloads.has(model.name);
                html += createModelCard(model, installed, false, downloading);
            });
            html += '</div>';
            container.innerHTML = html;
        }

        function createModelCard(model, installed, disabled, downloading) {
            const safeId = model.name.replace(/[^a-z0-9]/gi, "-");
            const categoryColors = {
                tiny: "#9c27b0", small: "#2196f3", medium: "#4caf50", 
                large: "#ff9800", code: "#00bcd4", reasoning: "#f44336",
                vision: "#e91e63", specialized: "#795548"
            };
            const categoryColor = categoryColors[model.category] || "#607d8b";

            let card = `<div class="dl-model-card ${installed ? 'installed' : ''}" data-model="${model.name}" data-category="${model.category}">`;
            
            if (installed) {
                card += '<div class="dl-installed-badge"><i class="fas fa-check"></i> Installed</div>';
            }
            
            card += `<div class="dl-category-badge" style="background:${categoryColor}">${model.category}</div>`;
            card += `<div class="dl-model-header">
                <h3>${model.name}</h3>
                <span class="dl-model-size">${model.size}</span>
            </div>
            <div class="dl-model-desc">${model.desc}</div>
            <div class="dl-model-actions">`;

            if (downloading) {
                card += `<div style="width:100%">
                    <div class="dl-progress-bar">
                        <div class="dl-progress-fill" id="progress-${safeId}"></div>
                    </div>
                    <div class="dl-progress-text" id="text-${safeId}">Downloading...</div>
                </div>`;
            } else if (disabled) {
                card += '<button class="dl-btn dl-btn-primary" disabled><i class="fas fa-exclamation-circle"></i> Start Ollama First</button>';
            } else if (installed) {
                card += `<button class="dl-btn dl-btn-success" onclick="useModel('${model.name}')"><i class="fas fa-comments"></i> Use Model</button>
                         <button class="dl-btn dl-btn-danger" onclick="removeModel('${model.name}')"><i class="fas fa-trash"></i> Remove</button>`;
            } else {
                card += `<button class="dl-btn dl-btn-primary" onclick="downloadModel('${model.name}')"><i class="fas fa-download"></i> Download</button>`;
            }

            card += '</div></div>';
            return card;
        }

        function downloadModel(modelName) {
            if (!ollamaRunning) {
                showAlert("error", "Please start Ollama first");
                return;
            }

            const safeId = modelName.replace(/[^a-z0-9]/gi, "-");
            const eventSource = new EventSource("?action=download&model=" + encodeURIComponent(modelName));
            
            downloads.set(modelName, eventSource);
            showModels();

            eventSource.addEventListener("progress", (e) => {
                const data = JSON.parse(e.data);
                const progressBar = document.getElementById("progress-" + safeId);
                const progressText = document.getElementById("text-" + safeId);
                
                if (progressBar) {
                    progressBar.style.width = (data.percentage || 0) + "%";
                }
                if (progressText) {
                    progressText.textContent = data.message || (data.percentage || 0) + "%";
                }
            });

            eventSource.addEventListener("success", (e) => {
                const data = JSON.parse(e.data);
                showAlert("success", data.message);
                eventSource.close();
                downloads.delete(modelName);
                setTimeout(() => loadModels(), 1000);
            });

            eventSource.addEventListener("error", (e) => {
                const data = JSON.parse(e.data);
                showAlert("error", data.error);
                eventSource.close();
                downloads.delete(modelName);
                showModels();
            });

            eventSource.onerror = () => {
                showAlert("error", "Download failed for " + modelName);
                eventSource.close();
                downloads.delete(modelName);
                showModels();
            };
        }

        function removeModel(modelName) {
            if (!confirm(`Are you sure you want to remove "${modelName}"?\n\nThis will delete the model files from your system.`)) {
                return;
            }

            const formData = new FormData();
            formData.append("model", modelName);

            fetch("?action=remove", {
                method: "POST",
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.error) {
                    showAlert("error", data.error);
                } else {
                    showAlert("success", data.message);
                    loadModels();
                }
            })
            .catch(err => {
                showAlert("error", "Error: " + err.message);
            });
        }

        function useModel(modelName) {
            window.location.href = "index.php?model=" + encodeURIComponent(modelName);
        }

        function showAlert(type, message) {
            const alertsContainer = document.getElementById("alerts");
            const alert = document.createElement("div");
            alert.className = "dl-alert dl-alert-" + type;
            
            const icon = type === "success" ? "check" : type === "error" ? "exclamation" : "info";
            alert.innerHTML = `<i class="fas fa-${icon}-circle"></i><span>${message}</span>`;
            
            alertsContainer.appendChild(alert);
            
            setTimeout(() => alert.remove(), 5000);
        }

        loadTheme();
        checkStatus();
        
        setInterval(checkStatus, 30000);
    </script>
</body>
</html>
<?php } ?>
