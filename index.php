<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Jada OLLAMA Simple Chat Platform</title>
  <link href="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css" rel="stylesheet" id="prism-theme" />
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.css">
  <link rel="stylesheet" href="styles.css">
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/katex.min.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/katex@0.16.8/dist/contrib/auto-render.min.js"></script>
</head>
<body>
  <div class="overlay" id="overlay"></div>
  <div class="sidebar" id="sidebar">
    <div class="sidebar-header">
      <h3>Chat History</h3>
      <button class="header-btn" onclick="toggleSidebar()">
        <i class="fas fa-times"></i>
      </button>
    </div>
    <div class="sidebar-content" id="chatHistory">
    </div>
    <div class="sidebar-footer">
      <button class="sidebar-btn danger" onclick="clearAllChatHistory()">
        <i class="fas fa-trash-alt"></i> Clear All History
      </button>
    </div>
  </div>
  <div class="header">
    <div class="header-left">
      <button class="header-btn" onclick="toggleSidebar()" title="Chat History">
        <i class="fas fa-history"></i>
      </button>
      <button class="header-btn" onclick="newChat()" title="New Chat">
        <i class="fas fa-plus"></i>
      </button>
      <button id="memoryBtn" class="header-btn" onclick="toggleMemory()" title="Memory">
        <i class="fas fa-brain"></i>
        <span class="memory-status" id="memoryStatus">ON</span>
      </button>
      <div class="logo">
        <i class="fas fa-robot"></i>
        Jada OLLAMA
      </div>
      <select id="model" class="model-selector" disabled>
        <option>Loading models…</option>
      </select>
    </div>
    <div class="header-right">
      <div class="status">
        <div id="dot" class="status-dot"></div>
        <div id="statustext">Checking…</div>
      </div>
      <div class="header-buttons">
        <button class="header-btn" onclick="clearChat()" title="Clear Chat">
          <i class="fas fa-trash"></i>
        </button>
        <button class="header-btn" onclick="exportChat()" title="Export Chat">
          <i class="fas fa-download"></i>
        </button>
        <button class="header-btn" onclick="toggleTheme()" title="Toggle Theme">
          <i class="fas fa-moon" id="themeIcon"></i>
        </button>
      </div>
    </div>
  </div>
  <div class="chat">
    <div id="msgs" class="messages"></div>
    <div class="input-area">
      <div class="input-wrapper">
        <textarea id="inp" class="input" placeholder="Select a model above to start chatting..." disabled rows="1"></textarea>
        <div class="input-actions">
          <button id="btn" class="action-btn" title="Send Message" disabled>
            <i class="fas fa-paper-plane"></i>
          </button>
        </div>
      </div>
    </div>
  </div>
  <div id="previewWindow" class="preview-window">
    <div class="preview-header">
      <h3 class="preview-title">Code Preview</h3>
      <div class="preview-controls">
        <button class="header-btn preview-btn" onclick="closePreview()">
          <i class="fas fa-times"></i>
        </button>
        <button class="header-btn preview-btn" id="maximizePreview">
          <i class="fas fa-expand"></i>
        </button>
      </div>
    </div>
    <div class="preview-content" id="previewContent">
    </div>
    <div class="resize-handle top"></div>
    <div class="resize-handle right"></div>
    <div class="resize-handle bottom"></div>
    <div class="resize-handle left"></div>
    <div class="resize-handle top-left"></div>
    <div class="resize-handle top-right"></div>
    <div class="resize-handle bottom-left"></div>
    <div class="resize-handle bottom-right"></div>
  </div>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/prism.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/plugins/autoloader/prism-autoloader.min.js"></script>
  <script src="main.js"></script>
</body>
</html>