<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Enhanced Jada Ollama Interface with AI Models">
    <title>Jada Ollama - Enhanced AI Interface</title>
    
    <!-- Preconnect for performance -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    
    <!-- Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Prism.js for syntax highlighting -->
    <link id="prism-theme" rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css">
    
    <!-- KaTeX for math rendering -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css">
    
    <!-- Main Stylesheet with cache busting -->
    <link rel="stylesheet" href="styles.css?v=<?php echo date('YmdHis'); ?>">
    
    <style>
        /* Loading Screen Styles */
        .app-loader {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: var(--bg-primary);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            transition: opacity 0.3s ease;
        }
        
        .loader-content {
            text-align: center;
        }
        
        .loader-spinner {
            width: 60px;
            height: 60px;
            border: 4px solid var(--border);
            border-top-color: var(--accent);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .loader-text {
            font-size: 16px;
            color: var(--text-secondary);
            font-weight: 600;
        }
        
        /* Notification Styles */
        .notification {
            position: fixed;
            top: 90px;
            right: 24px;
            min-width: 320px;
            max-width: 420px;
            background: var(--bg-secondary);
            border: 2px solid var(--border);
            border-radius: 12px;
            padding: 16px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            box-shadow: var(--shadow-lg);
            z-index: 1200;
            animation: slideInRight 0.3s ease-out;
            transition: opacity 0.3s ease;
        }
        
        @keyframes slideInRight {
            from {
                opacity: 0;
                transform: translateX(100%);
            }
            to {
                opacity: 1;
                transform: translateX(0);
            }
        }
        
        .notification-icon {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            flex-shrink: 0;
        }
        
        .notification-success .notification-icon {
            background: rgba(38, 222, 129, 0.1);
            color: var(--success);
        }
        
        .notification-error .notification-icon {
            background: rgba(255, 71, 87, 0.1);
            color: var(--error);
        }
        
        .notification-info .notification-icon {
            background: rgba(75, 123, 236, 0.1);
            color: var(--info);
        }
        
        .notification-content {
            flex: 1;
        }
        
        .notification-title {
            font-weight: 700;
            font-size: 14px;
            margin-bottom: 4px;
            color: var(--text-primary);
        }
        
        .notification-message {
            font-size: 13px;
            color: var(--text-secondary);
            line-height: 1.4;
        }
        
        .notification-close {
            background: none;
            border: none;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 4px;
            font-size: 14px;
            transition: color 0.2s ease;
        }
        
        .notification-close:hover {
            color: var(--text-primary);
        }
        
        /* Empty State Improvements */
        .empty-state {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100%;
            padding: 40px;
            text-align: center;
        }
        
        .empty-state-icon {
            font-size: 80px;
            margin-bottom: 24px;
            background: linear-gradient(135deg, var(--accent) 0%, #00bfa5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }
        
        .empty-state-title {
            font-size: 28px;
            font-weight: 700;
            margin-bottom: 12px;
            background: linear-gradient(135deg, var(--accent) 0%, #00bfa5 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        
        .empty-state-subtitle {
            font-size: 16px;
            color: var(--text-secondary);
            max-width: 500px;
            line-height: 1.6;
        }
        
        .empty-history {
            padding: 40px;
            text-align: center;
            color: var(--text-secondary);
            font-style: italic;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-left">
            <a href="index" class="logo" style="text-decoration: none; cursor: pointer;">
                <i class="fas fa-robot"></i>
                <span>Jada Ollama</span>
            </a>
            <select id="model" class="model-selector" aria-label="Select AI Model">
                <option value="">Loading models...</option>
            </select>
            <a href="dl" class="header-btn download-models-btn" title="Download Models">
                <i class="fas fa-download"></i>
            </a>
        </div>
        
        <div class="header-right">
            <div class="status">
                <div id="dot" class="status-dot"></div>
                <span id="statustext">Connecting...</span>
            </div>
            
            <div class="header-buttons">
                <button id="memoryBtn" class="header-btn" onclick="toggleMemory()" aria-label="Toggle Memory" title="Toggle conversation memory">
                    <i class="fas fa-brain"></i>
                    <span>Memory: <span id="memoryStatus">ON</span></span>
                </button>
                
                <button class="header-btn" onclick="toggleTheme()" aria-label="Toggle Theme" title="Switch theme">
                    <i id="themeIcon" class="fas fa-moon"></i>
                </button>
                
                <button class="header-btn" onclick="toggleSidebar()" aria-label="Open Sidebar" title="Chat history">
                    <i class="fas fa-history"></i>
                    <span>History</span>
                </button>
                
                <button class="header-btn" onclick="newChat()" aria-label="New Chat" title="Start new chat">
                    <i class="fas fa-plus"></i>
                    <span>New</span>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Chat Area -->
    <div class="chat">
        <div id="msgs" class="messages">
            <div class="empty-state">
                <div class="empty-state-icon">
                    <i class="fas fa-comments"></i>
                </div>
                <div class="empty-state-title">Welcome to Jada Ollama</div>
                <div class="empty-state-subtitle">
                    Select a model and start chatting with AI. Your conversations are saved automatically.
                </div>
            </div>
        </div>
        
        <div class="input-area">
            <div class="input-wrapper">
                <textarea 
                    id="inp" 
                    class="input" 
                    placeholder="Type your message... (Shift+Enter for new line)"
                    rows="1"
                    aria-label="Message input"
                ></textarea>
                <div class="input-actions">
                    <button id="stopBtn" class="action-btn stop-btn" style="display: none;" aria-label="Stop Generation" title="Stop">
                        <i class="fas fa-stop"></i>
                    </button>
                    <button id="continueBtn" class="action-btn continue-btn" style="display: none;" aria-label="Continue Generation" title="Continue">
                        <i class="fas fa-play"></i>
                    </button>
                    <button id="btn" class="action-btn" disabled aria-label="Send Message" title="Send (Enter)">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Sidebar -->
    <div id="sidebar" class="sidebar">
        <div class="sidebar-header">
            <h3>Chat History</h3>
            <button class="header-btn" onclick="toggleSidebar()" aria-label="Close Sidebar">
                <i class="fas fa-times"></i>
            </button>
        </div>
        
        <div class="sidebar-content" id="chatHistoryList">
            <!-- Chat history will be populated here -->
        </div>
        
        <div class="sidebar-footer">
            <button class="sidebar-btn" onclick="newChat()">
                <i class="fas fa-plus"></i>
                New Chat
            </button>
            <button class="sidebar-btn danger" onclick="clearAllChatHistory()">
                <i class="fas fa-trash"></i>
                Clear All
            </button>
        </div>
    </div>
    
    <!-- Scroll to Bottom Button -->
    <button id="scrollToBottom" class="scroll-to-bottom" aria-label="Scroll to bottom" title="Scroll to bottom">
        <i class="fas fa-arrow-down"></i>
    </button>
    
    <!-- Overlay -->
    <div id="overlay" class="overlay"></div>

    <!-- Custom Modal for Confirmations -->
    <div id="customModal" class="custom-modal">
        <div class="modal-content">
            <div class="modal-header">
                <i id="modalIcon" class="fas fa-question-circle"></i>
                <h3 id="modalTitle">Confirm Action</h3>
            </div>
            <div class="modal-body">
                <p id="modalMessage">Are you sure?</p>
            </div>
            <div class="modal-footer">
                <button id="modalCancel" class="modal-btn modal-btn-secondary">Cancel</button>
                <button id="modalConfirm" class="modal-btn modal-btn-primary">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Model Selection Modal -->
    <div id="modelSelectionModal" class="custom-modal">
        <div class="modal-content model-selector-content">
            <div class="modal-header">
                <i class="fas fa-brain"></i>
                <h3>Select a Model to Chat</h3>
            </div>
            <div class="modal-body">
                <p style="margin-bottom: 20px; color: var(--text-secondary);">Choose an AI model to start your conversation:</p>
                <div id="modelSelectionList" class="model-selection-list">
                    <!-- Models will be populated here -->
                </div>
            </div>
            <div class="modal-footer">
                <button id="modelSelectionCancel" class="modal-btn modal-btn-secondary">Cancel</button>
                <a href="dl" class="modal-btn modal-btn-accent" style="text-decoration: none; display: inline-flex; align-items: center; gap: 8px;">
                    <i class="fas fa-download"></i> Download Models
                </a>
            </div>
        </div>
    </div>

    <!-- Preview Window -->
    <div id="previewWindow" class="preview-window">
        <div class="preview-header">
            <div class="preview-title">Code Preview</div>
            <div class="preview-controls">
                <button id="maximizePreview" class="preview-btn" title="Maximize">
                    <i class="fas fa-expand"></i>
                </button>
                <button class="preview-btn" onclick="closePreview()" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
        <div id="previewContent" class="preview-content">
            <div style="display: flex; justify-content: center; align-items: center; height: 100%;">
                <div style="text-align: center;">
                    <i class="fas fa-code" style="font-size: 48px; color: var(--text-secondary); margin-bottom: 16px;"></i>
                    <div style="color: var(--text-secondary);">Preview will appear here</div>
                </div>
            </div>
        </div>
        <!-- Resize handles -->
        <div class="resize-handle top"></div>
        <div class="resize-handle right"></div>
        <div class="resize-handle bottom"></div>
        <div class="resize-handle left"></div>
        <div class="resize-handle top-right"></div>
        <div class="resize-handle bottom-right"></div>
        <div class="resize-handle bottom-left"></div>
        <div class="resize-handle top-left"></div>
    </div>

    <!-- Scripts -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/components/prism-markup.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="main.js?v=<?php echo date('YmdHis'); ?>"></script>
</body>
</html>
