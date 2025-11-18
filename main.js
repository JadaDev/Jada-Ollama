const modelSel = document.getElementById('model');
const dot = document.getElementById('dot');
const stat = document.getElementById('statustext');
const msgs = document.getElementById('msgs');
const inp = document.getElementById('inp');
const btn = document.getElementById('btn');
const stopBtn = document.getElementById('stopBtn');
const continueBtn = document.getElementById('continueBtn');
const scrollToBottomBtn = document.getElementById('scrollToBottom');
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('overlay');
const themeIcon = document.getElementById('themeIcon');
const memoryStatus = document.getElementById('memoryStatus');
let currentModel = '';
let currentChat = [];
let chatHistory = JSON.parse(localStorage.getItem('ollama_chat_history') || '[]');
let isTyping = false;
let memoryEnabled = JSON.parse(localStorage.getItem('ollama_memory_enabled') || 'true');
let currentChatId = null;
let currentStreamingMessage = null; // Track current streaming message
let streamingContent = ''; // Store streaming content
let streamingThought = ''; // Store streaming thought content
let streamReader = null; // Store current stream reader for stopping
let abortController = null; // Store abort controller for cancelling fetch
let isStreamStopped = false; // Track if user stopped the stream
let autoScroll = true; // Auto-scroll behavior
let availableModels = []; // Store available models for modal
function generateChatId() {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789';
  let id = '';
  for (let i = 0; i < 32; i++) {
    id += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return id;
}
function getChatIdFromUrl() {
  const params = new URLSearchParams(window.location.search);
  return params.get('chat');
}
function setChatIdInUrl(chatId) {
  const url = new URL(window.location);
  if (chatId) {
    url.searchParams.set('chat', chatId);
  } else {
    url.searchParams.delete('chat');
  }
  window.history.pushState({}, '', url);
}
function showModal(title, message, type = 'question', confirmText = 'Confirm', cancelText = 'Cancel') {
  return new Promise((resolve) => {
    const modal = document.getElementById('customModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalIcon = document.getElementById('modalIcon');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalCancel = document.getElementById('modalCancel');
    const modalContent = modal.querySelector('.modal-content');
    modalTitle.textContent = title;
    modalMessage.textContent = message;
    modalConfirm.textContent = confirmText;
    modalCancel.textContent = cancelText;
    const iconMap = {
      'question': 'fa-question-circle',
      'warning': 'fa-exclamation-triangle',
      'error': 'fa-exclamation-circle',
      'success': 'fa-check-circle',
      'info': 'fa-info-circle'
    };
    modalIcon.className = `fas ${iconMap[type] || iconMap.question}`;
    modalContent.classList.remove('modal-icon-warning', 'modal-icon-error', 'modal-icon-success', 'modal-icon-info');
    if (type !== 'question') {
      modalContent.classList.add(`modal-icon-${type}`);
    }
    modal.classList.add('show');
    const handleConfirm = () => {
      modal.classList.remove('show');
      cleanup();
      resolve(true);
    };
    const handleCancel = () => {
      modal.classList.remove('show');
      cleanup();
      resolve(false);
    };
    const cleanup = () => {
      modalConfirm.removeEventListener('click', handleConfirm);
      modalCancel.removeEventListener('click', handleCancel);
    };
    modalConfirm.addEventListener('click', handleConfirm);
    modalCancel.addEventListener('click', handleCancel);
  });
}
function showAlert(title, message, type = 'info') {
  return new Promise((resolve) => {
    const modal = document.getElementById('customModal');
    const modalTitle = document.getElementById('modalTitle');
    const modalMessage = document.getElementById('modalMessage');
    const modalIcon = document.getElementById('modalIcon');
    const modalConfirm = document.getElementById('modalConfirm');
    const modalCancel = document.getElementById('modalCancel');
    const modalContent = modal.querySelector('.modal-content');
    modalTitle.textContent = title;
    modalMessage.textContent = message;
    modalConfirm.textContent = 'OK';
    modalCancel.style.display = 'none'; // Hide cancel button for alerts
    const iconMap = {
      'warning': 'fa-exclamation-triangle',
      'error': 'fa-exclamation-circle',
      'success': 'fa-check-circle',
      'info': 'fa-info-circle'
    };
    modalIcon.className = `fas ${iconMap[type] || iconMap.info}`;
    modalContent.classList.remove('modal-icon-warning', 'modal-icon-error', 'modal-icon-success', 'modal-icon-info');
    modalContent.classList.add(`modal-icon-${type}`);
    modal.classList.add('show');
    const handleConfirm = () => {
      modal.classList.remove('show');
      modalCancel.style.display = ''; // Reset cancel button
      cleanup();
      resolve(true);
    };
    const cleanup = () => {
      modalConfirm.removeEventListener('click', handleConfirm);
    };
    modalConfirm.addEventListener('click', handleConfirm);
  });
}
function showModelSelectionModal() {
  return new Promise((resolve) => {
    const modal = document.getElementById('modelSelectionModal');
    const modelList = document.getElementById('modelSelectionList');
    const cancelBtn = document.getElementById('modelSelectionCancel');
    modelList.innerHTML = '';
    if (availableModels.length === 0) {
      modelList.innerHTML = `
        <div style="text-align: center; padding: 20px; color: var(--text-secondary);">
          <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
          <p>No models available. Download models to get started!</p>
        </div>
      `;
    } else {
      availableModels.forEach(model => {
        const item = document.createElement('div');
        item.className = 'model-selection-item';
        item.innerHTML = `
          <i class="fas fa-brain"></i>
          <div class="model-selection-info">
            <div class="model-selection-name">${model.name}</div>
            <div class="model-selection-size">${model.size}</div>
          </div>
          <i class="fas fa-chevron-right" style="color: var(--text-muted); font-size: 16px;"></i>
        `;
        item.addEventListener('click', () => {
          modal.classList.remove('show');
          cleanup();
          resolve(model.name);
        });
        modelList.appendChild(item);
      });
    }
    modal.classList.add('show');
    const handleCancel = () => {
      modal.classList.remove('show');
      cleanup();
      resolve(null);
    };
    const cleanup = () => {
      cancelBtn.removeEventListener('click', handleCancel);
    };
    cancelBtn.addEventListener('click', handleCancel);
  });
}
function initAutoScroll() {
  msgs.addEventListener('scroll', () => {
    const distanceFromBottom = msgs.scrollHeight - msgs.scrollTop - msgs.clientHeight;
    const isNearBottom = distanceFromBottom < 50;
    const isAtAbsoluteBottom = distanceFromBottom <= 1; // Must be at the very bottom
    if (!isNearBottom) {
      autoScroll = false;
    }
    if (isAtAbsoluteBottom) {
      autoScroll = true;
    }
    if (isNearBottom) {
      scrollToBottomBtn.classList.remove('show');
    } else {
      scrollToBottomBtn.classList.add('show');
    }
  });
  scrollToBottomBtn.addEventListener('click', () => {
    scrollToBottom();
    autoScroll = true;
  });
}
function scrollToBottom() {
  msgs.scrollTo({
    top: msgs.scrollHeight,
    behavior: 'smooth'
  });
}
function scrollToBottomIfNeeded() {
  if (autoScroll) {
    msgs.scrollTop = msgs.scrollHeight;
  }
}
(async () => {
  loadTheme();
  updateMemoryStatus();
  initAutoScroll();
  await checkStatus();
  await loadModels();
  loadChatHistory();
  setupInputHandlers();
  setInterval(checkStatus, 30000);
  const urlParams = new URLSearchParams(window.location.search);
  const modelParam = urlParams.get('model');
  if (modelParam) {
    setTimeout(() => {
      const decodedModel = decodeURIComponent(modelParam);
      modelSel.value = decodedModel;
      if (modelSel.value === decodedModel) {
        currentModel = decodedModel;
        setChatEnabled(true);
        inp.placeholder = `Chat with ${currentModel}...`;
        window.history.replaceState({}, document.title, window.location.pathname);
      }
    }, 500);
  }
})();
function toggleMemory() {
  memoryEnabled = !memoryEnabled;
  localStorage.setItem('ollama_memory_enabled', memoryEnabled);
  updateMemoryStatus();
}
function updateMemoryStatus() {
  memoryStatus.textContent = memoryEnabled ? 'ON' : 'OFF';
  const memoryBtn = document.getElementById('memoryBtn');
  if (memoryEnabled) {
    memoryBtn.classList.add('active');
  } else {
    memoryBtn.classList.remove('active');
  }
}
function setupInputHandlers() {
  inp.addEventListener('input', function() {
    this.style.height = 'auto';
    this.style.height = Math.min(this.scrollHeight, 200) + 'px';
    const hasText = inp.value.trim().length > 0;
    btn.disabled = !currentModel || !hasText || isTyping;
  });
  inp.addEventListener('keydown', async function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      console.log('[UI] Enter pressed, currentModel:', currentModel, 'text:', inp.value.trim().substring(0, 20));
      if (!currentModel && inp.value.trim()) {
        console.log('[UI] No model selected, showing modal');
        const selectedModel = await showModelSelectionModal();
        if (selectedModel) {
          modelSel.value = selectedModel;
          currentModel = selectedModel;
          inp.placeholder = `Chat with ${currentModel}...`;
          btn.disabled = false;
          console.log('[UI] Model selected from modal, triggering send');
          sendMessage();
        }
      } else if (currentModel && inp.value.trim()) {
        console.log('[UI] Model exists and has text, triggering send');
        sendMessage();
      } else {
        console.log('[UI] Cannot send - model:', !!currentModel, 'hasText:', !!inp.value.trim());
      }
    }
  });
  btn.addEventListener('click', () => {
    console.log('[UI] Send button clicked');
    sendMessage();
  });
  stopBtn.addEventListener('click', stopGeneration);
  continueBtn.addEventListener('click', continueGeneration);
}
function stopGeneration() {
  console.log('[Stop] Stop button clicked, streamReader:', !!streamReader, 'isTyping:', isTyping);
  isStreamStopped = true;
  if (abortController) {
    try {
      abortController.abort();
      console.log('[Stop] Fetch request aborted');
    } catch (error) {
      console.error('[Stop] Error aborting fetch:', error);
    }
    abortController = null;
  }
  if (streamReader) {
    try {
      streamReader.cancel();
      console.log('[Stop] Stream cancelled successfully');
    } catch (error) {
      console.error('[Stop] Error cancelling stream:', error);
    }
    streamReader = null;
  }
  if (currentStreamingMessage) {
    finishStreamingMessage();
  }
  stopBtn.style.display = 'none';
  continueBtn.style.display = 'flex';
  btn.style.display = 'flex';
  isTyping = false;
  console.log('[Stop] UI updated, continue button shown');
}
async function continueGeneration() {
  console.log('[Continue] Continue button clicked, currentModel:', currentModel, 'isTyping:', isTyping);
  if (!currentModel || isTyping) {
    console.warn('[Continue] Cannot continue - no model or already typing');
    return;
  }
  continueBtn.style.display = 'none';
  isStreamStopped = false;
  console.log('[Continue] Calling sendMessage(true)');
  sendMessage(true);
}
function toggleTheme() {
  const currentTheme = document.documentElement.getAttribute('data-theme');
  const newTheme = currentTheme === 'light' ? 'dark' : 'light';
  document.documentElement.setAttribute('data-theme', newTheme);
  localStorage.setItem('ollama_theme', newTheme);
  themeIcon.className = newTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
  const prismTheme = document.getElementById('prism-theme');
  prismTheme.href = newTheme === 'light'
    ? 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism.min.css'
    : 'https://cdnjs.cloudflare.com/ajax/libs/prism/1.29.0/themes/prism-tomorrow.min.css';
}
function loadTheme() {
  const savedTheme = localStorage.getItem('ollama_theme') || 'dark';
  document.documentElement.setAttribute('data-theme', savedTheme);
  themeIcon.className = savedTheme === 'light' ? 'fas fa-sun' : 'fas fa-moon';
}
function toggleSidebar() {
  sidebar.classList.toggle('open');
  overlay.classList.toggle('show');
}
overlay.addEventListener('click', () => {
  sidebar.classList.remove('open');
  overlay.classList.remove('show');
});
function showConnectionErrorPrompt() {
  const existingPrompt = document.getElementById('connection-error-prompt');
  if (existingPrompt) {
    existingPrompt.remove();
  }
  const promptOverlay = document.createElement('div');
  promptOverlay.id = 'connection-error-prompt';
  promptOverlay.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
  `;
  const promptBox = document.createElement('div');
  promptBox.style.cssText = `
    background: var(--bg-primary, #1a1a1a);
    border: 1px solid var(--border-color, #333);
    border-radius: 12px;
    padding: 30px;
    max-width: 500px;
    width: 90%;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  `;
  promptBox.innerHTML = `
    <div style="margin-bottom: 20px;">
      <i class="fas fa-wifi" style="font-size: 48px; color: #dc3545; margin-bottom: 15px;"></i>
      <h2 style="color: var(--text-primary, #fff); margin: 0 0 10px 0;">Connection Error</h2>
      <p style="color: var(--text-secondary, #ccc); margin: 0; line-height: 1.5;">
        Unable to connect to Ollama. This could be a temporary network issue or Ollama might have stopped running.
      </p>
    </div>
    <div style="display: flex; gap: 15px; justify-content: center;">
      <button id="retry-connection-btn" style="
        background: #28a745;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        transition: background-color 0.3s;
      ">
        <i class="fas fa-refresh" style="margin-right: 8px;"></i>
        Retry Connection
      </button>
      <button id="download-ollama-btn" style="
        background: transparent;
        color: var(--text-secondary, #ccc);
        border: 1px solid var(--border-color, #333);
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.3s;
      ">
        <i class="fas fa-download" style="margin-right: 8px;"></i>
        Download Ollama
      </button>
    </div>
  `;
  const retryBtn = promptBox.querySelector('#retry-connection-btn');
  const downloadBtn = promptBox.querySelector('#download-ollama-btn');
  retryBtn.addEventListener('mouseenter', () => {
    retryBtn.style.backgroundColor = '#218838';
  });
  retryBtn.addEventListener('mouseleave', () => {
    retryBtn.style.backgroundColor = '#28a745';
  });
  downloadBtn.addEventListener('mouseenter', () => {
    downloadBtn.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
  });
  downloadBtn.addEventListener('mouseleave', () => {
    downloadBtn.style.backgroundColor = 'transparent';
  });
  retryBtn.addEventListener('click', async () => {
    promptOverlay.remove();
    await checkStatus();
  });
  downloadBtn.addEventListener('click', () => {
    window.location.href = 'dl';
  });
  promptOverlay.appendChild(promptBox);
  document.body.appendChild(promptOverlay);
}
function showOllamaNotFoundPrompt() {
  const existingPrompt = document.getElementById('ollama-not-found-prompt');
  if (existingPrompt) {
    existingPrompt.remove();
  }
  const promptOverlay = document.createElement('div');
  promptOverlay.id = 'ollama-not-found-prompt';
  promptOverlay.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
  `;
  const promptBox = document.createElement('div');
  promptBox.style.cssText = `
    background: var(--bg-primary, #1a1a1a);
    border: 1px solid var(--border-color, #333);
    border-radius: 12px;
    padding: 30px;
    max-width: 500px;
    width: 90%;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  `;
  promptBox.innerHTML = `
    <div style="margin-bottom: 20px;">
      <i class="fas fa-exclamation-triangle" style="font-size: 48px; color: #ffa500; margin-bottom: 15px;"></i>
      <h2 style="color: var(--text-primary, #fff); margin: 0 0 10px 0;">Ollama Not Found</h2>
      <p style="color: var(--text-secondary, #ccc); margin: 0; line-height: 1.5;">
        Ollama is not running or not installed. Please download and install Ollama to use this chat application.
      </p>
    </div>
    <div style="display: flex; gap: 15px; justify-content: center;">
      <button id="download-ollama-btn" style="
        background: #007bff;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        transition: background-color 0.3s;
      ">
        <i class="fas fa-download" style="margin-right: 8px;"></i>
        Download Ollama
      </button>
      <button id="retry-connection-btn" style="
        background: transparent;
        color: var(--text-secondary, #ccc);
        border: 1px solid var(--border-color, #333);
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.3s;
      ">
        <i class="fas fa-refresh" style="margin-right: 8px;"></i>
        Retry
      </button>
    </div>
  `;
  const downloadBtn = promptBox.querySelector('#download-ollama-btn');
  const retryBtn = promptBox.querySelector('#retry-connection-btn');
  downloadBtn.addEventListener('mouseenter', () => {
    downloadBtn.style.backgroundColor = '#0056b3';
  });
  downloadBtn.addEventListener('mouseleave', () => {
    downloadBtn.style.backgroundColor = '#007bff';
  });
  retryBtn.addEventListener('mouseenter', () => {
    retryBtn.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
  });
  retryBtn.addEventListener('mouseleave', () => {
    retryBtn.style.backgroundColor = 'transparent';
  });
  downloadBtn.addEventListener('click', () => {
    window.location.href = 'dl';
  });
  retryBtn.addEventListener('click', async () => {
    promptOverlay.remove();
    await checkStatus();
  });
  promptOverlay.appendChild(promptBox);
  document.body.appendChild(promptOverlay);
}
function showServerErrorPrompt() {
  const existingPrompt = document.getElementById('server-error-prompt');
  if (existingPrompt) {
    existingPrompt.remove();
  }
  const promptOverlay = document.createElement('div');
  promptOverlay.id = 'server-error-prompt';
  promptOverlay.style.cssText = `
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.8);
    display: flex;
    justify-content: center;
    align-items: center;
    z-index: 10000;
  `;
  const promptBox = document.createElement('div');
  promptBox.style.cssText = `
    background: var(--bg-primary, #1a1a1a);
    border: 1px solid var(--border-color, #333);
    border-radius: 12px;
    padding: 30px;
    max-width: 500px;
    width: 90%;
    text-align: center;
    box-shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  `;
  promptBox.innerHTML = `
    <div style="margin-bottom: 20px;">
      <i class="fas fa-server" style="font-size: 48px; color: #dc3545; margin-bottom: 15px;"></i>
      <h2 style="color: var(--text-primary, #fff); margin: 0 0 10px 0;">Server Error</h2>
      <p style="color: var(--text-secondary, #ccc); margin: 0; line-height: 1.5;">
        There was an internal server error. Please check the server logs and try again later.
      </p>
    </div>
    <div style="display: flex; gap: 15px; justify-content: center;">
      <button id="reload-page-btn" style="
        background: #28a745;
        color: white;
        border: none;
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        font-weight: 600;
        transition: background-color 0.3s;
      ">
        <i class="fas fa-refresh" style="margin-right: 8px;"></i>
        Reload Page
      </button>
      <button id="dismiss-error-btn" style="
        background: transparent;
        color: var(--text-secondary, #ccc);
        border: 1px solid var(--border-color, #333);
        padding: 12px 24px;
        border-radius: 8px;
        cursor: pointer;
        font-size: 16px;
        transition: all 0.3s;
      ">
        <i class="fas fa-times" style="margin-right: 8px;"></i>
        Dismiss
      </button>
    </div>
  `;
  const reloadBtn = promptBox.querySelector('#reload-page-btn');
  const dismissBtn = promptBox.querySelector('#dismiss-error-btn');
  reloadBtn.addEventListener('mouseenter', () => {
    reloadBtn.style.backgroundColor = '#218838';
  });
  reloadBtn.addEventListener('mouseleave', () => {
    reloadBtn.style.backgroundColor = '#28a745';
  });
  dismissBtn.addEventListener('mouseenter', () => {
    dismissBtn.style.backgroundColor = 'rgba(255, 255, 255, 0.1)';
  });
  dismissBtn.addEventListener('mouseleave', () => {
    dismissBtn.style.backgroundColor = 'transparent';
  });
  reloadBtn.addEventListener('click', () => {
    window.location.reload();
  });
  dismissBtn.addEventListener('click', () => {
    promptOverlay.remove();
  });
  promptOverlay.appendChild(promptBox);
  document.body.appendChild(promptOverlay);
}
async function checkStatus(retryCount = 0) {
  const maxRetries = 3;
  const retryDelay = 2000; // 2 seconds
  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 10000); // 10 second timeout
    const response = await fetch('ollama_api.php?action=status', {
      signal: controller.signal,
      headers: {
        'Cache-Control': 'no-cache',
        'Pragma': 'no-cache'
      }
    });
    clearTimeout(timeoutId);
    if (!response.ok) {
      throw new Error(`HTTP ${response.status}: ${response.statusText}`);
    }
    const data = await response.json();
    if (data && data.success === false) {
      throw new Error(data.error || 'API returned error status');
    }
    const statusStr = (data && data.status) || (data && data.data && data.data.status) || null;
    if (statusStr && statusStr.toLowerCase() === 'online') {
      dot.classList.add('online');
      stat.textContent = 'OLLAMA Online';
      const existingConnectionPrompt = document.getElementById('connection-error-prompt');
      const existingNotFoundPrompt = document.getElementById('ollama-not-found-prompt');
      if (existingConnectionPrompt) {
        existingConnectionPrompt.remove();
      }
      if (existingNotFoundPrompt) {
        existingNotFoundPrompt.remove();
      }
      await loadModels();
      return true;
    } else {
      dot.classList.remove('online');
      stat.textContent = 'Offline';
      showOllamaNotFoundPrompt();
      return false;
    }
  } catch (error) {
    console.error('Status check failed:', error);
    dot.classList.remove('online');
    if (error.name === 'AbortError') {
      stat.textContent = 'Connection Timeout';
    } else if (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError'))) {
      stat.textContent = 'Network Error';
    } else if (error.message && error.message.includes('HTTP 500')) {
      stat.textContent = 'Server Error';
    } else {
      stat.textContent = 'Connection Error';
    }
    if (retryCount < maxRetries && (
      error.name === 'AbortError' ||
      (error.message && (error.message.includes('Failed to fetch') || error.message.includes('NetworkError')))
    )) {
      console.log(`Retrying status check in ${retryDelay}ms (attempt ${retryCount + 1}/${maxRetries})`);
      setTimeout(() => checkStatus(retryCount + 1), retryDelay);
      return false;
    }
    if (error.message && error.message.includes('HTTP 500')) {
      showServerErrorPrompt();
    } else {
      showConnectionErrorPrompt();
    }
    return false;
  }
}
async function loadModels() {
  try {
    const response = await fetch('ollama_api.php?action=models');
    const data = await response.json();
    if (data.success) {
      const previousModel = currentModel; // Save current selection
      modelSel.innerHTML = '';
      if (data.models.length === 0) {
        const defaultOption = new Option('No models available', '');
        modelSel.add(defaultOption);
        showOllamaNotFoundPrompt();
        availableModels = [];
      } else {
        availableModels = data.models;
        data.models.forEach(model => {
          const option = new Option(`${model.name} (${model.size})`, model.name);
          modelSel.add(option);
        });
        const downloadOption = new Option('🔽 Download more models...', 'download');
        downloadOption.className = 'download-option';
        modelSel.add(downloadOption);
        if (previousModel && data.models.some(m => m.name === previousModel)) {
          modelSel.value = previousModel;
        } else if (!currentModel && data.models.length > 0) {
          currentModel = data.models[0].name;
          modelSel.value = currentModel;
          setChatEnabled(true);
          inp.placeholder = `Chat with ${currentModel}...`;
        }
      }
      modelSel.disabled = false;
    } else {
      modelSel.innerHTML = '<option>Error loading models</option>';
      availableModels = [];
      showConnectionErrorPrompt();
    }
  } catch (error) {
    modelSel.innerHTML = '<option>Error loading models</option>';
    availableModels = [];
    showConnectionErrorPrompt();
  }
}
modelSel.addEventListener('change', function() {
  if (this.value === 'download') {
    const previousValue = currentModel || ''; // Save current model
    this.value = previousValue; // Restore selection before navigating
    window.location.href = `dl.php?t=${Date.now()}`; 
    return;
  }
  currentModel = this.value;
  setChatEnabled(!!currentModel);
  if (currentModel) {
    inp.placeholder = `Chat with ${currentModel}...`;
  } else {
    inp.placeholder = 'Select a model to start chatting...';
  }
});
function setChatEnabled(enabled) {
  const hasText = inp.value.trim().length > 0;
  btn.disabled = !enabled || !hasText || isTyping;
}
async function sendMessage(isContinue = false) {
  console.log('[Send] Starting sendMessage, isContinue:', isContinue, 'currentModel:', currentModel, 'isTyping:', isTyping);
  const text = inp.value.trim();
  if (!currentModel) {
    console.error('[Send] No model selected!');
    showAlert('No Model Selected', 'Please select a model before sending a message.', 'warning');
    return;
  }
  if (!isContinue && !text) {
    console.log('[Send] No text to send');
    return;
  }
  if (isTyping) {
    console.log('[Send] Already typing, ignoring');
    return;
  }
  isTyping = true;
  if (!isContinue) {
    autoScroll = true;
  }
  if (!isContinue) {
    console.log('[Send] Adding user message:', text);
    appendMessage('user', text);
    currentChat.push({ role: 'user', content: text });
    inp.value = '';
    inp.style.height = 'auto';
  }
  stopBtn.style.display = 'flex';
  btn.style.display = 'none';
  continueBtn.style.display = 'none';
  btn.disabled = true;
  isStreamStopped = false;
  if (!isContinue) {
    currentStreamingMessage = createStreamingMessage();
    streamingContent = '';
    streamingThought = '';
  }
  try {
    let messagesToSend = [];
    if (memoryEnabled && currentChat.length > 0) {
      messagesToSend = [...currentChat];
    } else {
      if (isContinue && currentChat.length > 0) {
        messagesToSend = [...currentChat];
      } else if (!isContinue && text) {
        messagesToSend = [{ role: 'user', content: text }];
      } else {
        throw new Error('No messages to send');
      }
    }
    console.log('[Send] Sending request to API, model:', currentModel, 'messages:', messagesToSend.length);
    abortController = new AbortController();
    const response = await fetch('ollama_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'chat_stream',
        model: currentModel,
        messages: messagesToSend,
        useMemory: memoryEnabled
      }),
      signal: abortController.signal
    });
    console.log('[Send] Response status:', response.status, response.statusText);
    if (!response.ok) {
      let bodyText = '';
      try {
        bodyText = await response.text();
        console.error('[Send] Error response body:', bodyText);
      } catch (e) {
        bodyText = `HTTP ${response.status} ${response.statusText}`;
      }
      throw new Error(`HTTP ${response.status}: ${bodyText}`);
    }
    if (!response.body) {
      const textBody = await response.text();
      console.error('[Send] No response body:', textBody);
      try {
        const parsed = JSON.parse(textBody);
        if (parsed.error) throw new Error(parsed.error);
        if (parsed.success === false) throw new Error(parsed.error || 'Server returned failure');
      } catch (e) {
        throw new Error(textBody || 'Empty response from server');
      }
    }
    streamReader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    console.log('[Send] Starting to read stream');
    while (true) {
      if (isStreamStopped) {
        console.log('[Send] Stream stopped by user');
        break;
      }
      const { done, value } = await streamReader.read();
      if (done) {
        console.log('[Send] Stream ended');
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || ''; // Keep incomplete line in buffer
      for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) continue;
        let payload = null;
        if (line.startsWith('data:')) {
          const data = line.slice(5).trim();
          if (data === '[DONE]' || data === ' [DONE]') {
            console.log('[Send] Received [DONE] signal');
            finishStreamingMessage();
            return;
          }
          try {
            payload = JSON.parse(data);
          } catch (err) {
            console.warn('[Send] Non-JSON streaming chunk:', data);
            continue;
          }
        } else {
          try {
            payload = JSON.parse(line);
          } catch (err) {
            continue;
          }
        }
        if (!payload) continue;
        if (payload.error) {
          throw new Error(payload.error);
        }
        if (payload.content) {
          appendToStreamingMessage(payload.content);
          scrollToBottomIfNeeded();
        }
      }
    }
    console.log('[Send] Finalizing message');
    finishStreamingMessage();
  } catch (error) {
    console.error('[Send] Streaming error:', error);
    if (error.name === 'AbortError') {
      console.log('[Send] Request was aborted by user');
      if (currentStreamingMessage) {
        finishStreamingMessage();
      }
    } else {
      if (currentStreamingMessage) {
        removeStreamingMessage();
      }
      const msg = error && error.message ? `❌ ${error.message}` : '❌ Connection error. Please try again.';
      appendMessage('assistant', msg, null, currentModel);
    }
  } finally {
    isTyping = false;
    streamReader = null;
    abortController = null;
    stopBtn.style.display = 'none';
    if (!isStreamStopped) {
      continueBtn.style.display = 'none';
    }
    btn.style.display = 'flex';
    const hasText = inp.value.trim().length > 0;
    btn.disabled = !currentModel || !hasText || isTyping;
    console.log('[Send] Cleanup complete');
  }
}
function createStreamingMessage() {
  isTyping = true;
  setChatEnabled(!!currentModel);
  const message = document.createElement('div');
  message.className = 'message assistant';
  message.id = 'streaming-message';
  const avatar = document.createElement('div');
  avatar.className = 'avatar';
  avatar.innerHTML = '<i class="fas fa-robot"></i>';
  const nameLabel = document.createElement('div');
  nameLabel.className = 'message-name';
  nameLabel.textContent = currentModel || 'AI';
  const avatarWrapper = document.createElement('div');
  avatarWrapper.className = 'avatar-wrapper';
  avatarWrapper.appendChild(avatar);
  avatarWrapper.appendChild(nameLabel);
  const messageContent = document.createElement('div');
  messageContent.className = 'message-content';
  const messageActions = document.createElement('div');
  messageActions.className = 'message-actions';
  messageActions.style.opacity = '1'; // Make it visible right away
  messageActions.innerHTML = `
    <button class="message-action-btn" data-action="copy-response" title="Copy response">
      <i class="fas fa-copy"></i>
    </button>
  `;
  messageContent.appendChild(messageActions);
  const text = document.createElement('div');
  text.className = 'text';
  text.id = 'streaming-text';
  const cursor = document.createElement('span');
  cursor.className = 'streaming-cursor';
  cursor.innerHTML = '▊';
  cursor.style.animation = 'blink 1s infinite';
  text.appendChild(cursor);
  messageContent.appendChild(text);
  const thoughtContainer = document.createElement('div');
  thoughtContainer.className = 'thought-container';
  thoughtContainer.id = 'streaming-thought-container';
  thoughtContainer.style.display = 'none'; // Initially hidden
  const thoughtToggle = document.createElement('button');
  thoughtToggle.className = 'thought-toggle';
  thoughtToggle.innerHTML = '<i class="fas fa-chevron-down"></i> AI Thought';
  const thoughtContent = document.createElement('div');
  thoughtContent.className = 'thought-content';
  thoughtContent.id = 'streaming-thought-content';
  thoughtContainer.appendChild(thoughtToggle);
  thoughtContainer.appendChild(thoughtContent);
  thoughtToggle.addEventListener('click', () => {
    thoughtContainer.classList.toggle('expanded');
  });
  messageContent.appendChild(thoughtContainer);
  messageActions.querySelector('[data-action="copy-response"]').addEventListener('click', () => {
    copyResponseText(message);
  });
  message.appendChild(avatarWrapper);
  message.appendChild(messageContent);
  msgs.appendChild(message);
  msgs.scrollTop = msgs.scrollHeight;
  return message;
}
function appendToStreamingMessage(content) {
  if (!currentStreamingMessage) return;
  const textElement = currentStreamingMessage.querySelector('#streaming-text');
  const cursor = textElement.querySelector('.streaming-cursor');
  const thoughtContainer = currentStreamingMessage.querySelector('#streaming-thought-container');
  const thoughtContent = currentStreamingMessage.querySelector('#streaming-thought-content');
  const thinkMatch = content.match(/<think>([\s\S]*?)<\/think>/);
  if (thinkMatch) {
    streamingThought += thinkMatch[1];
    content = content.replace(/<think>[\s\S]*?<\/think>/g, '');
    if (streamingThought.trim() && thoughtContainer) {
      thoughtContent.textContent = streamingThought.trim();
      thoughtContainer.style.display = 'block';
      thoughtContainer.classList.add('expanded');
    }
  }
  streamingContent += content;
  let displayContent = streamingContent;
  const codeBlockMatches = [...displayContent.matchAll(/```(\w+)?/g)];
  const unclosedBlocks = codeBlockMatches.length % 2;
  if (unclosedBlocks === 1) {
    displayContent += '\n```';
  }
  if (cursor) cursor.remove();
  const parsed = parseMarkdown(displayContent);
  textElement.innerHTML = parsed.content;
  const newCursor = document.createElement('span');
  newCursor.className = 'streaming-cursor';
  newCursor.innerHTML = '▊';
  newCursor.style.animation = 'blink 1s infinite';
  textElement.appendChild(newCursor);
  msgs.scrollTop = msgs.scrollHeight;
  const codeContainers = textElement.querySelectorAll('.code-content');
  codeContainers.forEach(codeContent => {
    codeContent.scrollTop = codeContent.scrollHeight;
  });
}
function finishStreamingMessage() {
  if (!currentStreamingMessage) return;
  isTyping = false;
  setChatEnabled(!!currentModel);
  stopBtn.style.display = 'none';
  btn.style.display = 'flex';
  continueBtn.style.display = 'none';
  const textElement = currentStreamingMessage.querySelector('#streaming-text');
  const cursor = textElement.querySelector('.streaming-cursor');
  const thoughtContainer = currentStreamingMessage.querySelector('#streaming-thought-container');
  const thoughtContent = currentStreamingMessage.querySelector('#streaming-thought-content');
  if (cursor) cursor.remove();
  const codeBlockCount = (streamingContent.match(/```/g) || []).length;
  if (codeBlockCount % 2 !== 0) {
    streamingContent += '\n```';
  }
  const originalContentWithThinks = streamingContent + (streamingThought ? `<think>${streamingThought}</think>` : '');
  const parsed = parseMarkdown(originalContentWithThinks);
  const finalContent = streamingContent || 'No response received';
  const finalThought = streamingThought.trim() || parsed.thinking || null;
  const cleanParsed = parseMarkdown(finalContent);
  textElement.innerHTML = cleanParsed.content;
  currentStreamingMessage.removeAttribute('id');
  textElement.removeAttribute('id');
  if (thoughtContainer) {
    thoughtContainer.removeAttribute('id');
    if (thoughtContent) {
      thoughtContent.removeAttribute('id');
    }
    const hasThoughtContent = finalThought && finalThought.trim();
    if (hasThoughtContent) {
      if (thoughtContent) {
        thoughtContent.textContent = finalThought;
      }
      thoughtContainer.style.display = 'block';
      thoughtContainer.classList.remove('expanded');
    } else {
      thoughtContainer.style.display = 'none';
    }
  }
  addCodeBlockHandlers(currentStreamingMessage);
  currentChat.push({ 
    role: 'assistant', 
    content: finalContent, 
    thought: finalThought,
    wasStopped: isStreamStopped  // Track if user stopped this message
  });
  console.log('[Streaming] Message finished, currentChat length:', currentChat.length, 'currentChatId:', currentChatId, 'wasStopped:', isStreamStopped);
  if (currentChat.length === 2 && !chatHistory.find(c => String(c.id) === String(currentChatId))) {
    console.log('[Streaming] First exchange in new chat, saving with ID:', currentChatId);
    saveChatToHistory();
  } else if (currentChatId) {
    console.log('[Streaming] Updating existing chat:', currentChatId);
    updateChatInHistory();
  }
  const messageActions = currentStreamingMessage.querySelector('.message-actions');
  if (messageActions) {
    messageActions.style.opacity = '1';
    setTimeout(() => {
      messageActions.style.opacity = ''; // Reset to use CSS hover rules after a delay
    }, 2000);
  }
  const finalizedMessage = currentStreamingMessage;
  if (isStreamStopped) {
    const messageContent = finalizedMessage.querySelector('.message-content');
    const responseActions = document.createElement('div');
    responseActions.className = 'response-actions';
    responseActions.innerHTML = `
      <button class="response-action-btn" data-action="regenerate" title="Regenerate response">
        <i class="fas fa-sync-alt"></i> Regenerate
      </button>
      <button class="response-action-btn" data-action="continue" title="Continue response">
        <i class="fas fa-arrow-right"></i> Continue
      </button>
    `;
    responseActions.querySelector('[data-action="regenerate"]').addEventListener('click', () => {
      regenerateResponse(finalizedMessage);
    });
    responseActions.querySelector('[data-action="continue"]').addEventListener('click', () => {
      continueResponse(finalizedMessage);
    });
    messageContent.appendChild(responseActions);
  } else {
    const messageContent = finalizedMessage.querySelector('.message-content');
    const responseActions = document.createElement('div');
    responseActions.className = 'response-actions';
    responseActions.innerHTML = `
      <button class="response-action-btn" data-action="regenerate" title="Regenerate response">
        <i class="fas fa-sync-alt"></i> Regenerate
      </button>
    `;
    responseActions.querySelector('[data-action="regenerate"]').addEventListener('click', () => {
      regenerateResponse(finalizedMessage);
    });
    messageContent.appendChild(responseActions);
  }
  currentStreamingMessage = null;
  streamingContent = '';
  streamingThought = '';
}
function removeStreamingMessage() {
  if (currentStreamingMessage) {
    currentStreamingMessage.remove();
    currentStreamingMessage = null;
    streamingContent = '';
    streamingThought = '';
  }
  isTyping = false;
  setChatEnabled(!!currentModel);
  stopBtn.style.display = 'none';
  btn.style.display = 'flex';
  continueBtn.style.display = 'none';
}
function parseMarkdown(content) {
  let thinkingContent = '';
  content = content.replace(/<think>([\s\S]*?)<\/think>/g, (match, thinking) => {
    thinkingContent = thinking.trim(); // Store the thinking content
    return ''; // Remove the thinking tags from the main content
  });
  content = content.replace(/\\\[\s*\\boxed\{([^}]+)\}\s*\\\]/g, (match, boxedContent) => {
    try {
      return `<div class="math-display">${katex.renderToString(`\\boxed{${boxedContent}}`, {
        displayMode: true,
        strict: false,
        trust: true
      })}</div>`;
    } catch (error) {
      console.error('LaTeX render error:', error);
      return match;
    }
  });
  content = content.replace(/\\\[([\s\S]*?)\\\]/g, (match, math) => {
    if (math.trim().startsWith('\\boxed{')) {
      return match;
    }
    try {
      const cleanMath = math.trim().replace(/\n\s*/g, ' ');
      return `<div class="math-display">${katex.renderToString(cleanMath, {
        displayMode: true,
        strict: false,
        trust: true
      })}</div>`;
    } catch (error) {
      console.error('LaTeX render error:', error);
      return match;
    }
  });
  content = content.replace(/\\\(([\s\S]*?)\\\)/g, (match, math) => {
    try {
      const cleanMath = math.trim().replace(/\n\s*/g, ' ');
      return katex.renderToString(cleanMath, {
        displayMode: false,
        strict: false,
        trust: true
      });
    } catch (error) {
      console.error('LaTeX render error:', error);
      return match;
    }
  });
  content = content.replace(/\\boxed\{([^}]+)\}/g, (match, boxedContent) => {
    try {
      return katex.renderToString(`\\boxed{${boxedContent}}`, {
        displayMode: false,
        strict: false,
        trust: true
      });
    } catch (error) {
      console.error('LaTeX render error:', error);
      return match;
    }
  });
  const codeBlocks = [];
  const inlineCodes = [];
  content = content.replace(/```(\w+)?\n?([\s\S]*?)```/g, (match, lang, code) => {
    const language = (lang || 'text').toLowerCase();
    let highlighted = '';
    try {
      if (typeof Prism !== 'undefined' && Prism.languages) {
        let grammar = Prism.languages[language];
        if (language === 'php' && (!grammar || !grammar.tokenizePlaceholders)) {
          grammar = Prism.languages.markup || Prism.languages.javascript;
        }
        if (!grammar) {
          grammar = Prism.languages[lang] || Prism.languages.markup || Prism.languages.javascript || Prism.languages.clike;
        }
        if (grammar && Prism.highlight) {
          highlighted = Prism.highlight(code.trim(), grammar, language);
        } else {
          highlighted = escapeHtml(code.trim());
        }
      } else {
        highlighted = escapeHtml(code.trim());
      }
    } catch (e) {
      console.warn('Prism highlighting failed for ' + language + ', using plain text:', e.message);
      highlighted = escapeHtml(code.trim());
    }
    const isPreviewable = ['html', 'css', 'javascript', 'js'].includes(language.toLowerCase());
    const isRunnable = ['python', 'javascript', 'js'].includes(language.toLowerCase());
    const langClass = language ? `language-${language}` : '';
    const placeholder = `___CODEBLOCK_${codeBlocks.length}___`;
    codeBlocks.push(`<div class="code-container"><div class="code-header"><span class="code-lang ${langClass}">${language}</span><div class="code-actions"><button class="code-btn" data-action="copy" data-tooltip="Copy code" disabled><i class="fas fa-copy"></i></button><button class="code-btn download-btn" data-action="download" data-tooltip="Download code" disabled><i class="fas fa-download"></i></button>${isPreviewable ? `<button class="code-btn preview-btn" data-action="preview" data-tooltip="Preview code" disabled><i class="fas fa-eye"></i></button>` : ''}${isRunnable ? `<button class="code-btn run-btn" data-action="run" data-tooltip="Run code" disabled><i class="fas fa-play"></i></button>` : ''}</div></div><div class="code-content"><pre><code class="language-${language}">${highlighted}</code></pre></div></div>`);
    return placeholder;
  });
  content = content.replace(/`([^`]+)`/g, (match, code) => {
    const placeholder = `___INLINECODE_${inlineCodes.length}___`;
    inlineCodes.push(`<code>${escapeHtml(code)}</code>`);
    return placeholder;
  });
  content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
  content = content.replace(/\*(.*?)\*/g, '<em>$1</em>');
  content = content.replace(/\n/g, '<br>');
  inlineCodes.forEach((code, index) => {
    content = content.replace(`___INLINECODE_${index}___`, code);
  });
  codeBlocks.forEach((block, index) => {
    content = content.replace(`___CODEBLOCK_${index}___`, block);
  });
  return { content: content, thinking: thinkingContent };
}
function appendMessage(role, content, thought = null, modelName = null, wasStopped = false) {
  console.log('[Message] Appending message:', role, 'Length:', content.length, 'Stopped:', wasStopped);
  const message = document.createElement('div');
  message.className = `message ${role}`;
  message.dataset.content = content; // Store original content for editing
  const avatar = document.createElement('div');
  avatar.className = 'avatar';
  avatar.innerHTML = role === 'user'
    ? '<i class="fas fa-user"></i>'
    : '<i class="fas fa-robot"></i>';
  const nameLabel = document.createElement('div');
  nameLabel.className = 'message-name';
  nameLabel.textContent = role === 'user' ? 'You' : (modelName || currentModel || 'AI');
  const avatarWrapper = document.createElement('div');
  avatarWrapper.className = 'avatar-wrapper';
  avatarWrapper.appendChild(avatar);
  avatarWrapper.appendChild(nameLabel);
  const messageContent = document.createElement('div');
  messageContent.className = 'message-content';
  const messageActions = document.createElement('div');
  messageActions.className = 'message-actions';
  if (role === 'user') {
    messageActions.innerHTML = `
      <button class="message-action-btn" data-action="copy" title="Copy message">
        <i class="fas fa-copy"></i>
      </button>
      <button class="message-action-btn" data-action="edit" title="Edit message">
        <i class="fas fa-edit"></i>
      </button>
    `;
    messageActions.querySelector('[data-action="copy"]').addEventListener('click', () => {
      copyMessageText(message);
    });
    messageActions.querySelector('[data-action="edit"]').addEventListener('click', () => {
      editMessage(message);
    });
  } else {
    messageActions.innerHTML = `
      <button class="message-action-btn" data-action="copy" title="Copy response">
        <i class="fas fa-copy"></i>
      </button>
    `;
    messageActions.querySelector('[data-action="copy"]').addEventListener('click', () => {
      copyMessageText(message);
    });
  }
  messageContent.appendChild(messageActions);
  const text = document.createElement('div');
  text.className = 'text';
  const parsed = parseMarkdown(content);
  text.innerHTML = parsed.content;
  if (parsed.thinking && !thought) {
    thought = parsed.thinking;
  }
  messageContent.appendChild(text);
  if (role === 'assistant' && thought && thought.trim()) {
      const thoughtContainer = document.createElement('div');
      thoughtContainer.className = 'thought-container';
      const thoughtToggle = document.createElement('button');
      thoughtToggle.className = 'thought-toggle';
      thoughtToggle.innerHTML = '<i class="fas fa-chevron-down"></i> AI Thought';
      const thoughtContent = document.createElement('div');
      thoughtContent.className = 'thought-content';
      thoughtContent.textContent = thought.trim(); // Use textContent to preserve whitespace and prevent HTML parsing
      thoughtContainer.appendChild(thoughtToggle);
      thoughtContainer.appendChild(thoughtContent);
      messageContent.appendChild(thoughtContainer);
      thoughtToggle.addEventListener('click', () => {
          thoughtContainer.classList.toggle('expanded');
      });
  }
  if (role === 'assistant') {
    const responseActions = document.createElement('div');
    responseActions.className = 'response-actions';
    let buttonsHtml = `
      <button class="response-action-btn" data-action="regenerate" title="Regenerate response">
        <i class="fas fa-sync-alt"></i> Regenerate
      </button>
    `;
    if (wasStopped) {
      buttonsHtml += `
        <button class="response-action-btn" data-action="continue" title="Continue response">
          <i class="fas fa-arrow-right"></i> Continue
        </button>
      `;
    }
    responseActions.innerHTML = buttonsHtml;
    responseActions.querySelector('[data-action="regenerate"]').addEventListener('click', () => {
      regenerateResponse(message);
    });
    if (wasStopped) {
      responseActions.querySelector('[data-action="continue"]').addEventListener('click', () => {
        continueResponse(message);
      });
    }
    messageContent.appendChild(responseActions);
  }
  message.appendChild(avatarWrapper);
  message.appendChild(messageContent);
  msgs.appendChild(message);
  msgs.scrollTop = msgs.scrollHeight;
  addCodeBlockHandlers(message);
}
function downloadCode(code, language) {
  const fileExtensions = {
    'python': 'py',
    'javascript': 'js',
    'js': 'js',
    'html': 'html',
    'css': 'css',
    'php': 'php',
    'bash': 'sh',
    'shell': 'sh',
    'sql': 'sql',
    'json': 'json',
    'xml': 'xml',
    'yaml': 'yml',
    'yml': 'yml',
    'markdown': 'md',
    'md': 'md'
  };
  const extension = fileExtensions[language.toLowerCase()] || 'txt';
  const filename = `code-${Date.now()}.${extension}`;
  const blob = new Blob([code], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}
function copyMessageText(messageElement) {
  const textElement = messageElement.querySelector('.text');
  if (!textElement) return;
  let textContent = textElement.innerHTML
    .replace(/<br>/g, '\n')
    .replace(/<[^>]+>/g, '')
    .replace(/&nbsp;/g, ' ')
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&#039;/g, "'");
  navigator.clipboard.writeText(textContent).then(() => {
    const copyBtn = messageElement.querySelector('[data-action="copy"]');
    if (copyBtn) {
      const originalHTML = copyBtn.innerHTML;
      copyBtn.innerHTML = '<i class="fas fa-check"></i>';
      copyBtn.classList.add('copied');
      setTimeout(() => {
        copyBtn.innerHTML = originalHTML;
        copyBtn.classList.remove('copied');
      }, 2000);
    }
  }).catch(error => {
    console.error('Failed to copy message:', error);
  });
}
function editMessage(messageElement) {
  console.log('[Edit] Editing message');
  const originalContent = messageElement.dataset.content;
  if (!originalContent) return;
  const messages = Array.from(msgs.querySelectorAll('.message'));
  const messageIndex = messages.indexOf(messageElement);
  if (messageIndex === -1) return;
  for (let i = messages.length - 1; i >= messageIndex; i--) {
    messages[i].remove();
  }
  currentChat = currentChat.slice(0, messageIndex);
  inp.value = originalContent;
  inp.style.height = 'auto';
  inp.style.height = Math.min(inp.scrollHeight, 200) + 'px';
  inp.focus();
  btn.disabled = !currentModel || !originalContent.trim();
  console.log('[Edit] Message loaded for editing, subsequent messages removed');
}
function regenerateResponse(messageElement) {
  console.log('[Regenerate] Regenerating response');
  const messages = Array.from(msgs.querySelectorAll('.message'));
  const messageIndex = messages.indexOf(messageElement);
  if (messageIndex === -1) return;
  for (let i = messages.length - 1; i >= messageIndex; i--) {
    messages[i].remove();
  }
  currentChat = currentChat.slice(0, messageIndex);
  if (currentChat.length === 0 || currentChat[currentChat.length - 1].role !== 'user') {
    console.error('[Regenerate] Last message in chat is not a user message');
    return;
  }
  console.log('[Regenerate] Regenerating from user message, currentChat length:', currentChat.length);
  sendMessageForRegenerate();
}
async function sendMessageForRegenerate() {
  console.log('[Regenerate] Sending message for regeneration');
  if (!currentModel) {
    console.error('[Regenerate] No model selected!');
    showAlert('No Model Selected', 'Please select a model before regenerating.', 'warning');
    return;
  }
  if (isTyping) {
    console.log('[Regenerate] Already typing, ignoring');
    return;
  }
  if (currentChat.length === 0 || currentChat[currentChat.length - 1].role !== 'user') {
    console.error('[Regenerate] No user message to regenerate from');
    return;
  }
  isTyping = true;
  isStreamStopped = false;
  stopBtn.style.display = 'flex';
  btn.style.display = 'none';
  continueBtn.style.display = 'none';
  btn.disabled = true;
  currentStreamingMessage = createStreamingMessage();
  streamingContent = '';
  streamingThought = '';
  try {
    let messagesToSend = [];
    if (memoryEnabled && currentChat.length > 0) {
      messagesToSend = [...currentChat];
    } else {
      messagesToSend = [currentChat[currentChat.length - 1]];
    }
    console.log('[Regenerate] Sending request to API, model:', currentModel, 'messages:', messagesToSend.length);
    const response = await fetch('ollama_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'chat_stream',
        model: currentModel,
        messages: messagesToSend,
        useMemory: memoryEnabled
      })
    });
    console.log('[Regenerate] Response status:', response.status, response.statusText);
    if (!response.ok) {
      let bodyText = '';
      try {
        bodyText = await response.text();
        console.error('[Regenerate] Error response body:', bodyText);
      } catch (e) {
        bodyText = `HTTP ${response.status} ${response.statusText}`;
      }
      throw new Error(`HTTP ${response.status}: ${bodyText}`);
    }
    if (!response.body) {
      const textBody = await response.text();
      console.error('[Regenerate] No response body:', textBody);
      try {
        const parsed = JSON.parse(textBody);
        if (parsed.error) throw new Error(parsed.error);
        if (parsed.success === false) throw new Error(parsed.error || 'Server returned failure');
      } catch (e) {
        throw new Error(textBody || 'Empty response from server');
      }
    }
    streamReader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    console.log('[Regenerate] Starting to read stream');
    while (true) {
      if (isStreamStopped) {
        console.log('[Regenerate] Stream stopped by user');
        break;
      }
      const { done, value } = await streamReader.read();
      if (done) {
        console.log('[Regenerate] Stream ended');
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) continue;
        let payload = null;
        if (line.startsWith('data:')) {
          const data = line.slice(5).trim();
          if (data === '[DONE]' || data === ' [DONE]') {
            console.log('[Regenerate] Received [DONE] signal');
            finishStreamingMessage();
            return;
          }
          try {
            payload = JSON.parse(data);
          } catch (err) {
            console.warn('[Regenerate] Non-JSON streaming chunk:', data);
            continue;
          }
        } else {
          try {
            payload = JSON.parse(line);
          } catch (err) {
            continue;
          }
        }
        if (!payload) continue;
        if (payload.error) {
          throw new Error(payload.error);
        }
        if (payload.content) {
          appendToStreamingMessage(payload.content);
          scrollToBottomIfNeeded();
        }
      }
    }
    finishStreamingMessage();
  } catch (error) {
    console.error('[Regenerate] Error during regeneration:', error);
    showAlert('Regeneration Error', error.message || 'Failed to regenerate response', 'error');
    removeStreamingMessage();
  }
}
function continueResponse(messageElement) {
  console.log('[Continue] Continuing response from stopped message', messageElement);
  if (!messageElement) {
    console.error('[Continue] No message element provided');
    return;
  }
  if (isTyping) {
    console.log('[Continue] Already generating, ignoring');
    return;
  }
  const messageContentDiv = messageElement.querySelector('.message-content');
  if (!messageContentDiv) {
    console.error('[Continue] Could not find message-content div');
    return;
  }
  const textElement = messageContentDiv.querySelector('.text');
  if (!textElement) {
    console.error('[Continue] Could not find text element in message');
    return;
  }
  const messages = Array.from(msgs.querySelectorAll('.message'));
  const messageIndex = messages.indexOf(messageElement);
  if (messageIndex === -1 || messageIndex >= currentChat.length) {
    console.error('[Continue] Could not find message in currentChat');
    return;
  }
  const assistantMessage = currentChat[messageIndex];
  if (!assistantMessage || assistantMessage.role !== 'assistant') {
    console.error('[Continue] Message is not an assistant message');
    return;
  }
  assistantMessage.wasStopped = false;
  isTyping = true;
  isStreamStopped = false;
  stopBtn.style.display = 'flex';
  btn.style.display = 'none';
  continueBtn.style.display = 'none';
  btn.disabled = true;
  currentStreamingMessage = messageElement;
  streamingContent = assistantMessage.content;
  streamingThought = assistantMessage.thought || '';
  const responseActions = messageElement.querySelector('.response-actions');
  if (responseActions) {
    responseActions.remove();
  }
  textElement.id = 'streaming-text';
  const cursor = document.createElement('span');
  cursor.className = 'streaming-cursor';
  textElement.appendChild(cursor);
  console.log('[Continue] Calling continueGeneration with existing content');
  continueGenerationFromMessage();
}
async function continueGenerationFromMessage() {
  console.log('[Continue] Continuing generation from existing message');
  try {
    const partialResponse = streamingContent;
    const lastAssistantIndex = currentChat.length - 1;
    if (lastAssistantIndex >= 0 && currentChat[lastAssistantIndex].role === 'assistant') {
      currentChat[lastAssistantIndex].content = partialResponse;
    }
    const lastUserIndex = currentChat.findLastIndex(m => m.role === 'user');
    const originalUserMessage = lastUserIndex >= 0 ? currentChat[lastUserIndex].content : '';
    let messagesToSend = [];
    const messagesBeforePartial = currentChat.slice(0, -1);
    if (memoryEnabled && messagesBeforePartial.length > 0) {
      messagesToSend = [...messagesBeforePartial];
    } else {
      if (lastUserIndex >= 0) {
        messagesToSend = [messagesBeforePartial[lastUserIndex]];
      }
    }
    messagesToSend.push({
      role: 'user',
      content: `Original request: "${originalUserMessage}"\n\nYou started responding with:\n${partialResponse}\n\nContinue your response from the exact point where you stopped. Do not repeat anything, just continue seamlessly.`
    });
    console.log('[Continue] Sending continuation with history, messages:', messagesToSend.length);
    console.log('[Continue] Partial response length:', partialResponse.length);
    abortController = new AbortController();
    const response = await fetch('ollama_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'chat_stream',
        model: currentModel,
        messages: messagesToSend,
        useMemory: false  // Don't use internal memory, we're providing full context
      }),
      signal: abortController.signal
    });
    if (!response.ok) {
      let bodyText = '';
      try {
        bodyText = await response.text();
      } catch (e) {
        bodyText = `HTTP ${response.status} ${response.statusText}`;
      }
      throw new Error(`HTTP ${response.status}: ${bodyText}`);
    }
    if (!response.body) {
      throw new Error('No response body from server');
    }
    streamReader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';
    while (true) {
      if (isStreamStopped) {
        console.log('[Continue] Stream stopped by user');
        break;
      }
      const { done, value } = await streamReader.read();
      if (done) {
        console.log('[Continue] Stream ended');
        break;
      }
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || '';
      for (const rawLine of lines) {
        const line = rawLine.trim();
        if (!line) continue;
        let payload = null;
        if (line.startsWith('data:')) {
          const data = line.slice(5).trim();
          if (data === '[DONE]' || data === ' [DONE]') {
            console.log('[Continue] Received [DONE] signal');
            finishContinuedMessage();
            return;
          }
          try {
            payload = JSON.parse(data);
          } catch (err) {
            console.warn('[Continue] Non-JSON chunk:', data);
            continue;
          }
        } else {
          try {
            payload = JSON.parse(line);
          } catch (err) {
            continue;
          }
        }
        if (!payload) continue;
        if (payload.error) {
          throw new Error(payload.error);
        }
        if (payload.content) {
          appendToStreamingMessage(payload.content);
          scrollToBottomIfNeeded();
        }
      }
    }
    finishContinuedMessage();
  } catch (error) {
    console.error('[Continue] Error:', error);
    if (error.name === 'AbortError') {
      console.log('[Continue] Request was aborted by user');
      finishContinuedMessage();
    } else {
      showAlert('Continue Error', error.message || 'Failed to continue generation', 'error');
      const cursor = currentStreamingMessage?.querySelector('.streaming-cursor');
      if (cursor) cursor.remove();
      if (currentStreamingMessage) {
        const failedMessage = currentStreamingMessage; // Capture reference
        const messageContent = failedMessage.querySelector('.message-content');
        const responseActions = document.createElement('div');
        responseActions.className = 'response-actions';
        responseActions.innerHTML = `
          <button class="response-action-btn" data-action="regenerate" title="Regenerate response">
            <i class="fas fa-sync-alt"></i> Regenerate
          </button>
          <button class="response-action-btn" data-action="continue" title="Continue response">
            <i class="fas fa-arrow-right"></i> Continue
          </button>
        `;
        responseActions.querySelector('[data-action="regenerate"]').addEventListener('click', () => {
          regenerateResponse(failedMessage);
        });
        responseActions.querySelector('[data-action="continue"]').addEventListener('click', () => {
          continueResponse(failedMessage);
        });
        messageContent.appendChild(responseActions);
      }
      isTyping = false;
      setChatEnabled(!!currentModel);
      stopBtn.style.display = 'none';
      btn.style.display = 'flex';
    }
  } finally {
    streamReader = null;
    abortController = null;
  }
}
function finishContinuedMessage() {
  if (!currentStreamingMessage) return;
  console.log('[Continue] Finishing continued message');
  isTyping = false;
  setChatEnabled(!!currentModel);
  stopBtn.style.display = 'none';
  btn.style.display = 'flex';
  continueBtn.style.display = 'none';
  const textElement = currentStreamingMessage.querySelector('.text');
  const cursor = textElement?.querySelector('.streaming-cursor');
  if (cursor) cursor.remove();
  if (textElement) {
    textElement.removeAttribute('id');
  }
  const codeBlockCount = (streamingContent.match(/```/g) || []).length;
  if (codeBlockCount % 2 !== 0) {
    streamingContent += '\n```';
  }
  const parsed = parseMarkdown(streamingContent);
  if (textElement) {
    textElement.innerHTML = parsed.content;
  }
  const messages = Array.from(msgs.querySelectorAll('.message'));
  const messageIndex = messages.indexOf(currentStreamingMessage);
  if (messageIndex !== -1 && messageIndex < currentChat.length) {
    currentChat[messageIndex].content = streamingContent;
    currentChat[messageIndex].wasStopped = isStreamStopped; // Update stopped status
    console.log('[Continue] Updated currentChat at index:', messageIndex, 'wasStopped:', isStreamStopped);
  }
  addCodeBlockHandlers(currentStreamingMessage);
  const finalizedMessage = currentStreamingMessage;
  const messageContent = finalizedMessage.querySelector('.message-content');
  const responseActions = document.createElement('div');
  responseActions.className = 'response-actions';
  if (isStreamStopped) {
    responseActions.innerHTML = `
      <button class="response-action-btn" data-action="regenerate" title="Regenerate response">
        <i class="fas fa-sync-alt"></i> Regenerate
      </button>
      <button class="response-action-btn" data-action="continue" title="Continue response">
        <i class="fas fa-arrow-right"></i> Continue
      </button>
    `;
    responseActions.querySelector('[data-action="continue"]').addEventListener('click', () => {
      continueResponse(finalizedMessage);
    });
  } else {
    responseActions.innerHTML = `
      <button class="response-action-btn" data-action="regenerate" title="Regenerate response">
        <i class="fas fa-sync-alt"></i> Regenerate
      </button>
    `;
  }
  responseActions.querySelector('[data-action="regenerate"]').addEventListener('click', () => {
    regenerateResponse(finalizedMessage);
  });
  messageContent.appendChild(responseActions);
  updateChatInHistory();
  currentStreamingMessage = null;
  streamingContent = '';
  streamingThought = '';
}
function addCodeBlockHandlers(messageElement) {
  messageElement.querySelectorAll('.code-btn').forEach(btn => {
    btn.disabled = false;
    btn.addEventListener('click', async function() {
      const action = this.dataset.action;
      const container = this.closest('.code-container');
      const code = container.querySelector('code').textContent;
      const language = container.querySelector('.code-lang').textContent.toLowerCase();
      if (action === 'copy') {
        try {
          await navigator.clipboard.writeText(code);
          const originalText = this.innerHTML;
          this.innerHTML = '<i class="fas fa-check"></i>';
          this.classList.add('copied');
          setTimeout(() => {
            this.innerHTML = originalText;
            this.classList.remove('copied');
          }, 2000);
        } catch (error) {
          console.error('Failed to copy code:', error);
        }
      } else if (action === 'download') {
        downloadCode(code, language);
      } else if (action === 'run') {
        await runCode(code, language, container);
      } else if (action === 'preview') {
        showPreview(code, language, this);
      }
    });
  });
}
async function runCode(code, language, container) {
  const runBtn = container.querySelector('[data-action="run"]');
  const originalText = runBtn.innerHTML;
  runBtn.innerHTML = '<div class="loading"></div> Running...';
  runBtn.disabled = true;
  let outputEl = container.querySelector('.output');
  if (!outputEl) {
    outputEl = document.createElement('div');
    outputEl.className = 'output';
    container.appendChild(outputEl);
  }
  try {
    let endpoint = 'python_runner';
    if (language === 'javascript' || language === 'js') {
      try {
        const originalLog = console.log;
        let output = '';
        console.log = (...args) => {
          output += args.join(' ') + '\n';
        };
        eval(code);
        console.log = originalLog;
        outputEl.textContent = output || 'Code executed successfully (no output)';
        outputEl.className = 'output success';
      } catch (error) {
        outputEl.textContent = `Error: ${error.message}`;
        outputEl.className = 'output error';
      }
    } else {
      const response = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ code, language })
      });
      const result = await response.json();
      if (result.success) {
        outputEl.textContent = result.output || 'Code executed successfully (no output)';
        outputEl.className = 'output success';
      } else {
        outputEl.textContent = `Error: ${result.error}`;
        outputEl.className = 'output error';
      }
    }
  } catch (error) {
    outputEl.textContent = `Execution error: ${error.message}`;
    outputEl.className = 'output error';
  } finally {
    runBtn.innerHTML = originalText;
    runBtn.disabled = false;
  }
}
function showPreview(code, language, sourceElement) {
  const previewWindow = document.getElementById('previewWindow');
  if (!previewWindow) return;
  previewWindow.style.display = 'flex';
  const previewContent = document.getElementById('previewContent');
  if (!previewContent) return;
  previewContent.innerHTML = `
    <div style="display: flex; justify-content: center; align-items: center; height: 100%; background: white;">
      <div style="text-align: center;">
        <div class="loading"></div>
        <div style="margin-top: 10px;">Loading preview...</div>
      </div>
    </div>
  `;
  const currentCodeContainer = sourceElement ? sourceElement.closest('.code-container') : null;
  if (['html', 'css', 'javascript', 'js'].includes(language.toLowerCase())) {
    if (!currentCodeContainer) {
      fetch('preview.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ code, language })
      })
      .then(response => response.text())
      .then(html => {
        previewContent.innerHTML = `<iframe id="previewFrame" class="preview-iframe" sandbox="allow-scripts" srcdoc="${escapeHtml(html)}"></iframe>`;
      })
      .catch(error => {
        previewContent.innerHTML = `<div style="padding: 20px; color: #dc3545;">Error loading preview: ${error.message}</div>`;
      });
      return;
    }
    let htmlBlocks = [];
    let cssBlocks = [];
    let jsBlocks = [];
    switch(language.toLowerCase()) {
      case 'html':
        htmlBlocks.push(code);
        break;
      case 'css':
        cssBlocks.push(code);
        break;
      case 'javascript':
      case 'js':
        jsBlocks.push(code);
        break;
    }
    const codeContainers = currentCodeContainer.closest('.message')?.querySelectorAll('.code-container') || [];
    codeContainers.forEach(container => {
      if (container === currentCodeContainer) return;
      const lang = container.querySelector('.code-lang')?.textContent?.toLowerCase();
      const blockCode = container.querySelector('code')?.textContent;
      if (!blockCode) return;
      if (lang === 'html') {
        htmlBlocks.push(blockCode);
      } else if (lang === 'css') {
        cssBlocks.push(blockCode);
      } else if (lang === 'javascript' || lang === 'js') {
        jsBlocks.push(blockCode);
      }
    });
    const mergedHtml = htmlBlocks.join('\n\n');
    const mergedCss = cssBlocks.join('\n\n');
    const mergedJs = jsBlocks.join('\n\n');
    const hasMergeableContent = (htmlBlocks.length > 0 ? 1 : 0) +
                               (cssBlocks.length > 0 ? 1 : 0) +
                               (jsBlocks.length > 0 ? 1 : 0) > 1 ||
                               (htmlBlocks.length > 1 || cssBlocks.length > 1 || jsBlocks.length > 1);
    if (hasMergeableContent) {
      const previewTitle = document.querySelector('.preview-title');
      if (previewTitle) {
        previewTitle.innerHTML = `
          Message Preview
          ${htmlBlocks.length > 0 ? `<span class="preview-tag">HTML (${htmlBlocks.length})</span>` : ''}
          ${cssBlocks.length > 0 ? `<span class="preview-tag">CSS (${cssBlocks.length})</span>` : ''}
          ${jsBlocks.length > 0 ? `<span class="preview-tag">JS (${jsBlocks.length})</span>` : ''}
        `;
      }
      fetch('preview', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          combinedPreview: true,
          htmlCode: mergedHtml,
          cssCode: mergedCss,
          jsCode: mergedJs
        })
      })
      .then(response => response.text())
      .then(html => {
        previewContent.innerHTML = `<iframe id="previewFrame" class="preview-iframe" sandbox="allow-scripts" srcdoc="${escapeHtml(html)}"></iframe>`;
      })
      .catch(error => {
        previewContent.innerHTML = `<div style="padding: 20px; color: #dc3545;">Error loading preview: ${error.message}</div>`;
      });
      return;
    }
  }
  const previewTitle = document.querySelector('.preview-title');
  if (previewTitle) {
    previewTitle.textContent = `${language.toUpperCase()} Preview`;
  }
  fetch('preview.php', {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({ code, language })
  })
  .then(response => response.text())
  .then(html => {
    previewContent.innerHTML = `<iframe id="previewFrame" class="preview-iframe" sandbox="allow-scripts" srcdoc="${escapeHtml(html)}"></iframe>`;
  })
  .catch(error => {
    previewContent.innerHTML = `<div style="padding: 20px; color: #dc3545;">Error loading preview: ${error.message}</div>`;
  });
}
function escapeHtml(html) {
  return html
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}
function closePreview() {
  const previewWindow = document.getElementById('previewWindow');
  if (previewWindow) {
    previewWindow.style.display = 'none';
  }
}
function makePreviewWindowDraggable() {
  const previewWindow = document.getElementById('previewWindow');
  const previewHeader = previewWindow.querySelector('.preview-header');
  let isDragging = false;
  let isResizing = false;
  let initialX, initialY, initialWidth, initialHeight;
  previewHeader.addEventListener('mousedown', (e) => {
    isDragging = true;
    initialX = e.clientX;
    initialY = e.clientY;
    const rect = previewWindow.getBoundingClientRect();
    const onMouseMove = (e) => {
      if (isDragging) {
        const dx = e.clientX - initialX;
        const dy = e.clientY - initialY;
        const left = rect.left + dx;
        const top = rect.top + dy;
        previewWindow.style.left = `${left}px`;
        previewWindow.style.top = `${top}px`;
        previewWindow.style.transform = 'none';
      }
    };
    const onMouseUp = () => {
      isDragging = false;
      document.removeEventListener('mousemove', onMouseMove);
      document.removeEventListener('mouseup', onMouseUp);
    };
    document.addEventListener('mousemove', onMouseMove);
    document.addEventListener('mouseup', onMouseUp);
  });
  previewWindow.addEventListener('mousedown', (e) => {
    const rect = previewWindow.getBoundingClientRect();
    const isBottomRight = (
      e.clientX > rect.right - 20 &&
      e.clientX < rect.right &&
      e.clientY > rect.bottom - 20 &&
      e.clientY < rect.bottom
    );
    if (isBottomRight) {
      isResizing = true;
      initialX = e.clientX;
      initialY = e.clientY;
      initialWidth = previewWindow.offsetWidth;
      initialHeight = previewWindow.offsetHeight;
      const onMouseMove = (e) => {
        if (isResizing) {
          const width = initialWidth + (e.clientX - initialX);
          const height = initialHeight + (e.clientY - initialY);
          if (width > 300) previewWindow.style.width = `${width}px`;
          if (height > 200) previewWindow.style.height = `${height}px`;
        }
      };
      const onMouseUp = () => {
        isResizing = false;
        document.removeEventListener('mousemove', onMouseMove);
        document.removeEventListener('mouseup', onMouseUp);
      };
      document.addEventListener('mousemove', onMouseMove);
      document.addEventListener('mouseup', onMouseUp);
      e.preventDefault();
    }
  });
}
const previewWindow = document.getElementById('previewWindow');
const maximizePreviewBtn = document.getElementById('maximizePreview');
if (previewWindow && maximizePreviewBtn) {
  maximizePreviewBtn.addEventListener('click', () => {
    previewWindow.classList.toggle('maximized');
    if (previewWindow.classList.contains('maximized')) {
      maximizePreviewBtn.innerHTML = '<i class="fas fa-compress"></i>';
    } else {
      maximizePreviewBtn.innerHTML = '<i class="fas fa-expand"></i>';
    }
  });
}
function initPreviewWindowResize() {
  const handles = previewWindow.querySelectorAll('.resize-handle');
  handles.forEach(handle => {
    handle.addEventListener('mousedown', startResize);
  });
  function startResize(e) {
    e.preventDefault();
    e.stopPropagation();
    if (previewWindow.classList.contains('maximized')) {
      return;
    }
    const direction = e.target.className.replace('resize-handle ', '');
    const rect = previewWindow.getBoundingClientRect();
    const startX = e.clientX;
    const startY = e.clientY;
    const startWidth = rect.width;
    const startHeight = rect.height;
    const startTop = rect.top;
    const startLeft = rect.left;
    function resize(e) {
      const dx = e.clientX - startX;
      const dy = e.clientY - startY;
      previewWindow.style.transform = 'none';
      if (direction.includes('right')) {
        const newWidth = Math.max(300, startWidth + dx);
        previewWindow.style.width = newWidth + 'px';
      }
      if (direction.includes('bottom')) {
        const newHeight = Math.max(200, startHeight + dy);
        previewWindow.style.height = newHeight + 'px';
      }
      if (direction.includes('left')) {
        const newWidth = Math.max(300, startWidth - dx);
        const newLeft = startLeft + startWidth - newWidth;
        previewWindow.style.width = newWidth + 'px';
        previewWindow.style.left = newLeft + 'px';
      }
      if (direction.includes('top')) {
        const newHeight = Math.max(200, startHeight - dy);
        const newTop = startTop + startHeight - newHeight;
        previewWindow.style.height = newHeight + 'px';
        previewWindow.style.top = newTop + 'px';
      }
    }
    function stopResize() {
      document.removeEventListener('mousemove', resize);
      document.removeEventListener('mouseup', stopResize);
    }
    document.addEventListener('mousemove', resize);
    document.addEventListener('mouseup', stopResize);
  }
}
function initPreviewWindow() {
  makePreviewWindowDraggable();
  initPreviewWindowResize();
}
initPreviewWindow();
function saveChatToHistory() {
  if (currentChat.length === 0) {
    console.log('[History] Cannot save - no messages in current chat');
    return;
  }
  if (!currentChatId) {
    currentChatId = generateChatId();
    setChatIdInUrl(currentChatId);
    console.log('[History] Generated new chat ID:', currentChatId);
  }
  const chatSummary = currentChat[0]?.content?.substring(0, 50) + '...';
  const chatData = {
    id: currentChatId,
    title: chatSummary,
    messages: [...currentChat],
    model: currentModel,
    timestamp: new Date().toISOString()
  };
  console.log('[History] Saving chat:', chatData.id, chatData.title);
  const existingIndex = chatHistory.findIndex(chat => chat.id === chatData.id);
  if (existingIndex !== -1) {
    chatHistory[existingIndex] = chatData;
    console.log('[History] Updated existing chat at index:', existingIndex);
  } else {
    chatHistory.unshift(chatData);
    console.log('[History] Added new chat, total chats:', chatHistory.length);
    if (chatHistory.length > 50) {
      chatHistory = chatHistory.slice(0, 50);
    }
  }
  try {
    localStorage.setItem('ollama_chat_history', JSON.stringify(chatHistory));
    console.log('[History] Saved to localStorage successfully');
  } catch (error) {
    console.error('[History] Failed to save to localStorage:', error);
  }
  updateChatHistoryUI();
}
function updateChatInHistory() {
  if (!currentChatId || currentChat.length === 0) {
    console.log('[History] Cannot update - no chat ID or empty chat');
    return;
  }
  const existingIndex = chatHistory.findIndex(chat => chat.id === currentChatId);
  if (existingIndex !== -1) {
    chatHistory[existingIndex].messages = [...currentChat];
    chatHistory[existingIndex].timestamp = new Date().toISOString();
    console.log('[History] Updated chat:', currentChatId, 'Messages:', currentChat.length);
    try {
      localStorage.setItem('ollama_chat_history', JSON.stringify(chatHistory));
      console.log('[History] Updated in localStorage successfully');
    } catch (error) {
      console.error('[History] Failed to update localStorage:', error);
    }
    updateChatHistoryUI();
  } else {
    console.log('[History] Chat not found in history, saving as new');
    saveChatToHistory();
  }
}
function deleteChatFromHistory(chatId) {
  showModal(
    'Delete Chat',
    'Are you sure you want to delete this chat? This action cannot be undone.',
    'warning',
    'Delete',
    'Cancel'
  ).then(confirmed => {
    if (confirmed) {
      chatHistory = chatHistory.filter(chat => chat.id !== chatId);
      localStorage.setItem('ollama_chat_history', JSON.stringify(chatHistory));
      updateChatHistoryUI();
    }
  });
}
function exportSpecificChat(chat) {
  const chatText = chat.messages.map(msg =>
    `${msg.role.toUpperCase()}: ${msg.content}${msg.thought ? '\n\nTHOUGHT:\n' + msg.thought : ''}`
  ).join('\n\n---\n\n'); // Use a separator between messages
  const blob = new Blob([chatText], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `ollama-chat-${chat.title.substring(0, 20)}-${new Date(chat.timestamp).toLocaleDateString()}.txt`;
  a.click();
  URL.revokeObjectURL(url);
}
function loadChatHistory() {
  console.log('[History] Loading chat history, count:', chatHistory.length);
  updateChatHistoryUI();
}
function updateChatHistoryUI() {
  console.log('[History] Updating UI with', chatHistory.length, 'chats');
  const chatHistoryContainer = document.getElementById('chatHistoryList');
  if (!chatHistoryContainer) {
    console.error('[History] chatHistoryList element not found!');
    return;
  }
  chatHistoryContainer.innerHTML = '';
  if (chatHistory.length === 0) {
    chatHistoryContainer.innerHTML = '<div class="empty-history"><i class="fas fa-comments" style="font-size: 48px; margin-bottom: 16px; opacity: 0.3;"></i><p style="text-align: center; color: var(--text-secondary);">No chat history yet</p></div>';
    console.log('[History] No chats to display');
    return;
  }
  console.log('[History] Rendering', chatHistory.length, 'chat items');
  chatHistory.forEach(chat => {
    const chatItem = document.createElement('div');
    chatItem.className = 'chat-history-item';
    if (currentChatId === chat.id) {
      chatItem.classList.add('active');
    }
    const chatTitle = document.createElement('div');
    chatTitle.className = 'chat-history-item-header';
    const titleText = document.createElement('div');
    titleText.style.cssText = 'font-weight: 600; font-size: 14px; flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;';
    titleText.textContent = chat.title;
    const actionsDiv = document.createElement('div');
    actionsDiv.className = 'chat-history-actions';
    const exportBtn = document.createElement('button');
    exportBtn.className = 'chat-history-action-btn';
    exportBtn.title = 'Export';
    exportBtn.innerHTML = '<i class="fas fa-download"></i>';
    exportBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      exportSpecificChat(chat);
    });
    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'chat-history-action-btn';
    deleteBtn.title = 'Delete';
    deleteBtn.innerHTML = '<i class="fas fa-trash"></i>';
    deleteBtn.addEventListener('click', (e) => {
      e.stopPropagation();
      deleteChatFromHistory(chat.id);
    });
    actionsDiv.appendChild(exportBtn);
    actionsDiv.appendChild(deleteBtn);
    chatTitle.appendChild(titleText);
    chatTitle.appendChild(actionsDiv);
    const metaInfo = document.createElement('div');
    metaInfo.style.cssText = 'font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;';
    metaInfo.innerHTML = `<i class="fas fa-robot"></i> ${chat.model} • <i class="fas fa-clock"></i> ${new Date(chat.timestamp).toLocaleDateString()}`;
    const messageCount = document.createElement('div');
    messageCount.style.cssText = 'font-size: 12px; color: var(--text-secondary);';
    messageCount.innerHTML = `<i class="fas fa-comment"></i> ${chat.messages.length} messages`;
    chatItem.appendChild(chatTitle);
    chatItem.appendChild(metaInfo);
    chatItem.appendChild(messageCount);
    chatItem.addEventListener('click', (e) => {
      if (!e.target.closest('.chat-history-action-btn')) {
        loadChatFromHistory(chat);
      }
    });
    chatHistoryContainer.appendChild(chatItem);
  });
}
function loadChatFromHistory(chat) {
  msgs.innerHTML = '';
  currentChat = [...chat.messages];
  currentChatId = chat.id;
  currentModel = chat.model;
  setChatIdInUrl(chat.id);
  modelSel.value = chat.model;
  if (modelSel.value !== chat.model) {
    const option = document.createElement('option');
    option.value = chat.model;
    option.textContent = chat.model;
    modelSel.insertBefore(option, modelSel.firstChild);
    modelSel.value = chat.model;
  }
  setChatEnabled(!!currentModel);
  if (currentModel) {
    inp.placeholder = `Chat with ${currentModel}...`;
  }
  chat.messages.forEach(msg => {
    appendMessage(msg.role, msg.content, msg.thought, msg.role === 'assistant' ? chat.model : null, msg.wasStopped);
  });
  toggleSidebar();
}
function newChat() {
  console.log('[Chat] New chat requested, current messages:', currentChat.length);
  if (currentChat.length > 0) {
    showModal(
      'Start New Chat',
      'Start a new chat? Your current conversation will be saved to history.',
      'question',
      'Start New',
      'Cancel'
    ).then(confirmed => {
      if (confirmed) {
        console.log('[Chat] User confirmed new chat');
        clearChat();
      } else {
        console.log('[Chat] User cancelled new chat');
      }
    });
  } else {
    console.log('[Chat] Starting new chat (no existing messages)');
    clearChat();
  }
}
function clearChat() {
  console.log('[Chat] Clearing chat');
  msgs.innerHTML = '';
  currentChat = [];
  currentChatId = generateChatId();
  setChatIdInUrl(currentChatId);
  updateChatHistoryUI();
  autoScroll = true;
  scrollToBottomBtn.classList.remove('show');
  const hasText = inp.value.trim().length > 0;
  btn.disabled = !currentModel || !hasText || isTyping;
  console.log('[Chat] Chat cleared, new currentChatId:', currentChatId);
}
function clearAllChatHistory() {
  showModal(
    'Clear All History',
    'Are you sure you want to delete all chat history? This action cannot be undone.',
    'warning',
    'Delete All',
    'Cancel'
  ).then(confirmed => {
    if (confirmed) {
      chatHistory = [];
      localStorage.removeItem('ollama_chat_history');
      updateChatHistoryUI();
    }
  });
}
function exportChat() {
  if (currentChat.length === 0) {
    showAlert('No Content', 'There is no conversation to export.', 'info');
    return;
  }
  const chatText = currentChat.map(msg =>
    `${msg.role.toUpperCase()}: ${msg.content}${msg.thought ? '\n\nTHOUGHT:\n' + msg.thought : ''}`
  ).join('\n\n---\n\n');
  const blob = new Blob([chatText], { type: 'text/plain' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = `ollama-chat-${new Date().toISOString().split('T')[0]}.txt`;
  a.click();
  URL.revokeObjectURL(url);
}
if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
  });
}
(function initializeFromUrl() {
  const chatIdFromUrl = getChatIdFromUrl();
  if (chatIdFromUrl) {
    console.log('[Init] Chat ID found in URL:', chatIdFromUrl);
    const chat = chatHistory.find(c => String(c.id) === String(chatIdFromUrl));
    if (chat) {
      console.log('[Init] Loading chat from history:', chat.id);
      loadChatFromHistory(chat);
    } else {
      console.log('[Init] Chat ID not found in history, starting fresh with this ID');
      currentChatId = chatIdFromUrl;
    }
  } else {
    console.log('[Init] No chat ID in URL, generating new one');
    currentChatId = generateChatId();
    setChatIdInUrl(currentChatId);
  }
})();
window.addEventListener('message', function(event) {
  if (event.data && event.data.action === "bypassSecurity") {
    const previewData = event.data;
    if (previewData.isCombinedPreview) {
      fetch('preview', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          combinedPreview: true,
          htmlCode: previewData.htmlCode,
          cssCode: previewData.cssCode,
          jsCode: previewData.jsCode,
          bypassSecurity: true
        })
      })
      .then(response => response.text())
      .then(html => {
        const previewContent = document.getElementById('previewContent');
        previewContent.innerHTML = `<iframe id="previewFrame" class="preview-iframe" sandbox="allow-scripts" srcdoc="${escapeHtml(html)}"></iframe>`;
      })
      .catch(error => {
        const previewContent = document.getElementById('previewContent');
        previewContent.innerHTML = `<div style="padding: 20px; color: #dc3545;">Error loading preview: ${error.message}</div>`;
      });
    } else {
      fetch('preview', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
          code: previewData.code,
          language: previewData.language,
          bypassSecurity: true
        })
      })
      .then(response => response.text())
      .then(html => {
        const previewContent = document.getElementById('previewContent');
        previewContent.innerHTML = `<iframe id="previewFrame" class="preview-iframe" sandbox="allow-scripts" srcdoc="${escapeHtml(html)}"></iframe>`;
      })
      .catch(error => {
        const previewContent = document.getElementById('previewContent');
        previewContent.innerHTML = `<div style="padding: 20px; color: #dc3545;">Error loading preview: ${error.message}</div>`;
      });
    }
  }
});
