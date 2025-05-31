const modelSel = document.getElementById('model');
const dot = document.getElementById('dot');
const stat = document.getElementById('statustext');
const msgs = document.getElementById('msgs');
const inp = document.getElementById('inp');
const btn = document.getElementById('btn');
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

const defaultModels = [
  { name: 'deepseek-r1', displayName: 'DeepSeek R1', size: '1.5B', gpuRam: '2GB' },
  { name: 'mistral', displayName: 'Mistral', size: '7B', gpuRam: '8GB' },
  { name: 'llama2', displayName: 'Llama 2', size: '7B', gpuRam: '8GB' },
  { name: 'codellama', displayName: 'Code Llama', size: '7B', gpuRam: '8GB' },
  { name: 'phi', displayName: 'Microsoft Phi-2', size: '2.7B', gpuRam: '4GB' },
  { name: 'neural-chat', displayName: 'Neural Chat', size: '7B', gpuRam: '8GB' },
  { name: 'starling-lm', displayName: 'Starling', size: '7B', gpuRam: '8GB' },
  { name: 'openchat', displayName: 'OpenChat', size: '7B', gpuRam: '8GB' },
  { name: 'tinyllama', displayName: 'Tiny Llama', size: '1.1B', gpuRam: '2GB' },
  { name: 'stablelm-zephyr', displayName: 'Zephyr', size: '3B', gpuRam: '4GB' },
  { name: 'dolphin-phi', displayName: 'Dolphin Phi', size: '2.7B', gpuRam: '4GB' },
  { name: 'gemma', displayName: 'Google Gemma', size: '7B', gpuRam: '8GB' },
  { name: 'qwen', displayName: 'Qwen', size: '7B', gpuRam: '8GB' },
  { name: 'yi', displayName: 'Yi', size: '6B', gpuRam: '8GB' },
  { name: 'orca-mini', displayName: 'Orca Mini', size: '3B', gpuRam: '4GB' },
  { name: 'vicuna', displayName: 'Vicuna', size: '7B', gpuRam: '8GB' },
  { name: 'wizard-math', displayName: 'WizardMath', size: '7B', gpuRam: '8GB' },
  { name: 'meditron', displayName: 'Meditron', size: '7B', gpuRam: '8GB' },
  { name: 'nous-hermes', displayName: 'Nous Hermes', size: '7B', gpuRam: '8GB' },
  { name: 'solar', displayName: 'Solar', size: '7B', gpuRam: '8GB' }
];

(async () => {
  loadTheme();
  updateMemoryStatus();
  await checkStatus();
  await loadModels();
  loadChatHistory();
  setupInputHandlers();
  setInterval(checkStatus, 30000);
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
    setChatEnabled(!!currentModel);
  });
  inp.addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) {
      e.preventDefault();
      btn.click();
    }
  });
  btn.addEventListener('click', sendMessage);
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

async function checkStatus() {
  try {
    const response = await fetch('ollama_api.php?action=status');
    const data = await response.json();
    if (data.status === 'online') {
      dot.classList.add('online');
      stat.textContent = 'OLLAMA Online';
      await loadModels();
    } else {
      dot.classList.remove('online');
      stat.textContent = 'Offline';
      showOllamaNotFoundPopup();
    }
  } catch (error) {
    dot.classList.remove('online');
    stat.textContent = 'Connection Error';
    showOllamaNotFoundPopup();
  }
}

let installedModels = []; // Track installed models

async function loadModels() {
  try {
    const response = await fetch('ollama_api.php?action=models');
    const data = await response.json();
    modelSel.innerHTML = '';
    
    if (data.success) {
      const defaultOption = new Option('Select a model to start chatting...', '');
      modelSel.add(defaultOption);
      
      // Store installed models for reference
      installedModels = data.models.map(m => m.name);
      
      if (data.models.length === 0) {
        showModelSelectionPopup();
      } else {
        data.models.forEach(model => {
          const option = new Option(`${model.name} (${model.size})`, model.name);
          modelSel.add(option);
        });
      }
      
      // Add download option at the end
      const downloadOption = new Option('🔽 Download more models...', 'download');
      downloadOption.className = 'download-option';
      modelSel.add(downloadOption);
      
      modelSel.disabled = false;
    } else {
      modelSel.innerHTML = '<option>Error loading models</option>';
      showModelSelectionPopup();
    }
  } catch (error) {
    modelSel.innerHTML = '<option>Error loading models</option>';
    showModelSelectionPopup();
  }
}

function showOllamaNotFoundPopup() {
  const popup = document.createElement('div');
  popup.className = 'popup-overlay';
  popup.innerHTML = `
    <div class="popup-content">
      <h2>⚠️ Ollama Not Detected</h2>
      <p>It seems Ollama is not installed or running on your system. To use this chat interface:</p>
      <ol>
        <li>Download and install Ollama from <a href="https://ollama.ai" target="_blank">ollama.ai</a></li>
        <li>Run Ollama on your system</li>
        <li>Refresh this page</li>
      </ol>
      <button class="primary-btn" onclick="this.closest('.popup-overlay').remove()">Got it</button>
    </div>
  `;
  document.body.appendChild(popup);
}

function showModelSelectionPopup() {
  const popup = document.createElement('div');
  popup.className = 'popup-overlay model-selection-popup';
  
  // Make the overlay clickable to close the popup
  popup.addEventListener('click', (e) => {
    // Only close if the actual overlay was clicked (not the content)
    if (e.target === popup) {
      popup.remove();
    }
  });
  
  popup.innerHTML = `
    <div class="popup-content">
      <h2>🤖 Model Library</h2>
      <p class="model-library-desc">Discover and manage AI models for your conversations</p>
      
      ${installedModels.length === 0 ? `
        <div class="quick-download">
          <div class="quick-download-header">
            <h3>⚡ Quick Start</h3>
            <p>Get started with our recommended lightweight model</p>
          </div>
          <button class="primary-btn quick-start-btn" onclick="downloadModel('deepseek-r1')">
            <i class="fas fa-download"></i>
            Download DeepSeek R1 (1.5B) - Recommended
          </button>
        </div>
      ` : ''}
      
      <div class="models-section">
        <div class="section-header">
          <h3>Available Models</h3>
          <span class="model-count">${defaultModels.length} models available</span>
        </div>
        <div class="models-grid">
          ${defaultModels.map(model => {
            const isInstalled = installedModels.some(installed => installed.includes(model.name));
            return `
              <div class="model-card ${isInstalled ? 'installed' : ''}">
                <div class="model-header">
                  <h4>${model.displayName}</h4>
                  ${isInstalled ? '<span class="installed-badge"><i class="fas fa-check-circle"></i> Installed</span>' : ''}
                </div>
                <div class="model-specs">
                  <div class="spec-item">
                    <i class="fas fa-microchip"></i>
                    <span>Size: ${model.size}</span>
                  </div>
                  <div class="spec-item">
                    <i class="fas fa-memory"></i>
                    <span>GPU RAM: ${model.gpuRam}</span>
                  </div>
                </div>
                <div class="model-actions">
                  ${isInstalled ? 
                    `<button class="chat-btn" onclick="selectAndCloseModel('${model.name}')">
                      <i class="fas fa-comments"></i> Chat with ${model.displayName}
                    </button>` :
                    `<button class="downloadmdlbtn" onclick="downloadModel('${model.name}')">
                      <i class="fas fa-download"></i> Download
                    </button>`
                  }
                </div>
              </div>
            `;
          }).join('')}
        </div>
      </div>
      
      <div class="popup-footer">
        <button class="close-btn" id="close-model-popup">
          <i class="fas fa-times"></i>
        </button>
      </div>
    </div>
  `;
  document.body.appendChild(popup);
  
  // Add event listener for the close button
  const closeBtn = popup.querySelector('#close-model-popup');
  closeBtn.addEventListener('click', () => {
    popup.remove();
  });
}

function selectAndCloseModel(modelName) {
  // Find the option that contains this model name
  const options = Array.from(modelSel.options);
  const matchingOption = options.find(option => 
    option.value === modelName || option.textContent.includes(modelName)
  );
  
  if (matchingOption) {
    // Select the model using the actual option value
    modelSel.value = matchingOption.value;
    currentModel = matchingOption.value;
    
    // Trigger the change event to ensure all UI updates happen
    const changeEvent = new Event('change', { bubbles: true });
    modelSel.dispatchEvent(changeEvent);
    
    // Update UI elements
    setChatEnabled(!!currentModel);
    if (currentModel) {
      inp.placeholder = `Chat with ${currentModel}...`;
      inp.disabled = false;
    }
  }
  
  // Close the popup
  const popup = document.querySelector('.model-selection-popup');
  if (popup) {
    popup.remove();
  }
}

async function downloadModel(modelName) {
  const modelInfo = defaultModels.find(m => m.name === modelName);
  const downloadPopup = document.createElement('div');
  downloadPopup.className = 'popup-overlay download-popup';
  
  // Make the overlay clickable to close the popup
  downloadPopup.addEventListener('click', (e) => {
    // Only close if the actual overlay was clicked (not the content)
    if (e.target === downloadPopup) {
      downloadPopup.remove();
    }
  });
  
  // Only show custom module input for new downloads, not for already defined models
  const showCustomNameInput = !defaultModels.some(m => m.name === modelName);
  
  downloadPopup.innerHTML = `
    <div class="popup-content download-dialog">
      <div class="download-header">
        <h2><i class="fas fa-cloud-download-alt"></i> Installing ${modelInfo?.displayName || modelName}</h2>
        <p class="download-subtitle">This may take a few minutes depending on your internet connection</p>
      </div>
      
      ${showCustomNameInput ? `
      <!-- Custom Module Input Field -->
      <div class="custom-module-input">
        <label class="custom-module-label" for="custom-module-name">Custom Module Name (Optional)</label>
        <input type="text" id="custom-module-name" class="custom-module-field" 
               placeholder="Enter custom model name or leave empty to use default" 
               value="${modelName}">
        <p class="custom-module-hint">You can specify a different model name or repository URL</p>
      </div>
      ` : ''}
      
      <div class="download-progress-container">
        <div class="download-status-card">
          <div class="status-icon">
            <i class="fas fa-download rotating"></i>
          </div>
          <div class="status-text">
            <div class="status-title" id="download-status-title">Preparing download...</div>
            <div class="status-detail" id="download-status-detail">Connecting to Ollama service</div>
          </div>
        </div>
        
        <div class="progress-bar-container">
          <div class="progress-bar" id="download-progress-bar">
            <div class="progress-fill" id="download-progress-fill"></div>
          </div>
          <div class="progress-text" id="download-progress-text">0%</div>
        </div>
        
        <div class="download-log" id="download-log">
          <div class="log-header">Download Log</div>
          <div class="log-content" id="log-content">
            <div class="log-entry">Starting download process...</div>
          </div>
        </div>
      </div>
      
      <div class="download-actions">
        <button class="secondary-btn" id="cancel-download" onclick="cancelDownload()">
          <i class="fas fa-times"></i> Cancel
        </button>
      </div>
      
      <!-- Enhanced Close Button with Tooltip -->
      <button class="close-btn" id="close-download-popup">
        <i class="fas fa-times"></i>
      </button>
    </div>
  `;
  document.body.appendChild(downloadPopup);

  // Add event listener for the close button
  const closeBtn = downloadPopup.querySelector('#close-download-popup');
  closeBtn.addEventListener('click', () => {
    downloadPopup.remove();
  });

  try {
    let downloadCancelled = false;
    window.cancelDownload = () => {
      downloadCancelled = true;
      downloadPopup.remove();
    };

    // Get the custom module name when the download button is clicked
    const customModuleInput = downloadPopup.querySelector('#custom-module-name');
    const actualModelName = customModuleInput ? customModuleInput.value.trim() : modelName;

    const response = await fetch('dl.php?action=download', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ model: actualModelName })
    });

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    const statusTitle = downloadPopup.querySelector('#download-status-title');
    const statusDetail = downloadPopup.querySelector('#download-status-detail');
    const progressFill = downloadPopup.querySelector('#download-progress-fill');
    const progressText = downloadPopup.querySelector('#download-progress-text');
    const logContent = downloadPopup.querySelector('#log-content');
    const statusIcon = downloadPopup.querySelector('.status-icon i');

    let progress = 0;
    let lastProgressUpdate = Date.now();

    while (true) {
      if (downloadCancelled) break;
      
      const { done, value } = await reader.read();
      if (done) break;
      
      const text = decoder.decode(value).trim();
      if (!text) continue;

      // Add to log
      const logEntry = document.createElement('div');
      logEntry.className = 'log-entry';
      logEntry.textContent = text;
      logContent.appendChild(logEntry);
      logContent.scrollTop = logContent.scrollHeight;

      // Parse progress from output more reliably
      // Check for different formats of progress reporting
      let progressMatch = text.match(/(\d+)%\s*|(\d+\.\d+)\s*%/);
      if (progressMatch) {
        // Use the first matched group that contains a number
        const matchedProgress = progressMatch[1] || progressMatch[2];
        if (matchedProgress) {
          progress = parseFloat(matchedProgress);
          progressFill.style.width = `${progress}%`;
          progressText.textContent = `${progress}%`;
          lastProgressUpdate = Date.now();
        }
      } else if (text.includes('digesting')) {
        // When in digesting phase, advance progress gradually
        if (Date.now() - lastProgressUpdate > 1000) {  
          progress = Math.min(progress + 0.5, 99); // Increment gradually but don't exceed 99%
          progressFill.style.width = `${progress}%`;
          progressText.textContent = `${progress}%`;
        }
      }

      // Update status based on content
      if (text.includes('pulling manifest')) {
        statusTitle.textContent = 'Downloading manifest...';
        statusDetail.textContent = 'Getting model information';
      } else if (text.includes('pulling')) {
        statusTitle.textContent = 'Downloading model files...';
        statusDetail.textContent = 'This may take several minutes';
      } else if (text.includes('verifying')) {
        statusTitle.textContent = 'Verifying download...';
        statusDetail.textContent = 'Ensuring file integrity';
      } else if (text.includes('writing manifest')) {
        statusTitle.textContent = 'Writing manifest...';
        statusDetail.textContent = 'Finalizing installation';
        progress = 95;
        progressFill.style.width = '95%';
        progressText.textContent = '95%';
      } else if (text.includes('copying model files')) {
        statusTitle.textContent = 'Installing model files...';
        statusDetail.textContent = 'Almost done';
        progress = 98;
        progressFill.style.width = '98%'; 
        progressText.textContent = '98%';
      } else if (text.includes('Downloaded successfully') || text.includes('success')) {
        statusTitle.textContent = 'Installation complete!';
        statusDetail.textContent = `${modelInfo?.displayName || actualModelName} is ready to use`;
        statusIcon.className = 'fas fa-check-circle';
        statusIcon.classList.remove('rotating');
        progressFill.style.width = '100%';
        progressText.textContent = '100%';
        
        // Update download button to chat button
        setTimeout(async () => {
          await loadModels(); // Refresh model list
          downloadPopup.innerHTML = `
            <div class="popup-content download-success">
              <div class="success-animation">
                <i class="fas fa-check-circle"></i>
              </div>
              <h2>🎉 Installation Complete!</h2>
              <p>${modelInfo?.displayName || actualModelName} has been successfully installed and is ready to use.</p>
              <div class="success-actions">
                <button class="primary-btn" onclick="selectAndCloseModel('${actualModelName}')">
                  <i class="fas fa-comments"></i> Start Chatting
                </button>
                <button class="secondary-btn" onclick="this.closest('.popup-overlay').remove()">
                  <i class="fas fa-times"></i> Close
                </button>
              </div>
            </div>
          `;
        }, 2000);
        break;
      } else if (text.includes('error') || text.includes('Error')) {
        statusTitle.textContent = 'Download failed';
        statusDetail.textContent = 'Please check your connection and try again';
        statusIcon.className = 'fas fa-exclamation-triangle';
        statusIcon.classList.remove('rotating');
        break;
      }
    }
  } catch (error) {
    const statusTitle = downloadPopup.querySelector('#download-status-title');
    const statusDetail = downloadPopup.querySelector('#download-status-detail');
    const statusIcon = downloadPopup.querySelector('.status-icon i');
    
    statusTitle.textContent = 'Download failed';
    statusDetail.textContent = `Error: ${error.message}`;
    statusIcon.className = 'fas fa-exclamation-triangle';
    statusIcon.classList.remove('rotating');
  }
}

modelSel.addEventListener('change', function() {
  if (this.value === 'download') {
    this.value = ''; // Reset selection
    // Add a timestamp parameter to prevent caching
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
  inp.disabled = !enabled;
  btn.disabled = !enabled || !hasText || isTyping;
}

async function sendMessage() {
  const text = inp.value.trim();
  if (!text || !currentModel || isTyping) return;
  appendMessage('user', text);
  currentChat.push({ role: 'user', content: text });
  inp.value = '';
  inp.style.height = 'auto';
  setChatEnabled(!!currentModel);
  
  // Create empty assistant message for live streaming
  currentStreamingMessage = createStreamingMessage();
  streamingContent = '';
  streamingThought = '';
  
  try {
    let messagesToSend = [];
    if (memoryEnabled && currentChat.length > 0) {
      messagesToSend = [...currentChat];
    } else {
      messagesToSend = [{ role: 'user', content: text }];
    }
    
    // Use fetch with ReadableStream for live streaming
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

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
      const { done, value } = await reader.read();
      if (done) break;
      
      buffer += decoder.decode(value, { stream: true });
      const lines = buffer.split('\n');
      buffer = lines.pop() || ''; // Keep incomplete line in buffer
      
      for (const line of lines) {
        if (line.startsWith('data: ')) {
          const data = line.slice(6);
          if (data === '[DONE]') {
            finishStreamingMessage();
            return;
          }
          
          try {
            const parsed = JSON.parse(data);
            if (parsed.error) {
              throw new Error(parsed.error);
            }
            if (parsed.content) {
              appendToStreamingMessage(parsed.content);
            }
          } catch (e) {
            console.error('Error parsing streaming data:', e);
          }
        }
      }
    }
    
    finishStreamingMessage();
    
  } catch (error) {
    console.error('Streaming error:', error);
    if (currentStreamingMessage) {
      removeStreamingMessage();
    }
    appendMessage('assistant', '❌ Connection error. Please try again.');
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
  
  const messageContent = document.createElement('div');
  messageContent.className = 'message-content';
  
  // Add message actions div with copy button - Make sure it's visible during streaming
  const messageActions = document.createElement('div');
  messageActions.className = 'message-actions';
  messageActions.style.opacity = '1'; // Make it visible right away
  messageActions.innerHTML = `
    <button class="message-action-btn" data-action="copy-response" title="Copy response">
      <i class="fas fa-copy"></i>
    </button>
  `;
  messageContent.appendChild(messageActions);
  
  // Create text div first
  const text = document.createElement('div');
  text.className = 'text';
  text.id = 'streaming-text';
  
  // Add cursor for typing effect
  const cursor = document.createElement('span');
  cursor.className = 'streaming-cursor';
  cursor.innerHTML = '▊';
  cursor.style.animation = 'blink 1s infinite';
  text.appendChild(cursor);
  
  // Add text content first (before thought container)
  messageContent.appendChild(text);
  
  // Create thought container after text
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
  
  // Add event listener for thought toggle
  thoughtToggle.addEventListener('click', () => {
    thoughtContainer.classList.toggle('expanded');
  });
  
  // Add thought container after text content
  messageContent.appendChild(thoughtContainer);
  
  // Add event listener to the copy button
  messageActions.querySelector('[data-action="copy-response"]').addEventListener('click', () => {
    copyResponseText(message);
  });
  
  message.appendChild(avatar);
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
  
  // Check if content contains thinking tags
  const thinkMatch = content.match(/<think>([\s\S]*?)<\/think>/);
  if (thinkMatch) {
    streamingThought += thinkMatch[1];
    content = content.replace(/<think>[\s\S]*?<\/think>/g, '');
    
    // Update thought content and show the container if there's thought content
    if (streamingThought.trim() && thoughtContainer) {
      thoughtContent.textContent = streamingThought.trim();
      thoughtContainer.style.display = 'block';
      // Add expanded class to make toggle button work properly
      thoughtContainer.classList.add('expanded');
    }
  }
  
  streamingContent += content;
  
  // Smart code block handling - detect opening ``` and pre-add closing ```
  let displayContent = streamingContent;
  
  // Count unclosed code blocks
  const codeBlockMatches = [...displayContent.matchAll(/```(\w+)?/g)];
  const unclosedBlocks = codeBlockMatches.length % 2;
  
  if (unclosedBlocks === 1) {
    // There's an unclosed code block, add temporary closing
    displayContent += '\n```';
  }
  
  // Remove cursor temporarily
  if (cursor) cursor.remove();
  
  // Parse and display content (without thinking tags since we handle them separately)
  const parsed = parseMarkdown(displayContent);
  textElement.innerHTML = parsed.content;
  
  // Re-add cursor at the end
  const newCursor = document.createElement('span');
  newCursor.className = 'streaming-cursor';
  newCursor.innerHTML = '▊';
  newCursor.style.animation = 'blink 1s infinite';
  textElement.appendChild(newCursor);
  
  // Auto-scroll to bottom and handle code block scrolling
  msgs.scrollTop = msgs.scrollHeight;
  
  // If we're inside a code block, scroll the code content to the bottom
  const codeContainers = textElement.querySelectorAll('.code-content');
  codeContainers.forEach(codeContent => {
    codeContent.scrollTop = codeContent.scrollHeight;
  });
}

function finishStreamingMessage() {
  if (!currentStreamingMessage) return;
  
  isTyping = false;
  setChatEnabled(!!currentModel);
  
  const textElement = currentStreamingMessage.querySelector('#streaming-text');
  const cursor = textElement.querySelector('.streaming-cursor');
  const thoughtContainer = currentStreamingMessage.querySelector('#streaming-thought-container');
  const thoughtContent = currentStreamingMessage.querySelector('#streaming-thought-content');
  
  // Remove cursor only
  if (cursor) cursor.remove();
  
  // Parse the final content without the temporary closing ```
  // BUT preserve the original content with <think> tags for proper parsing
  const originalContentWithThinks = streamingContent + (streamingThought ? `<think>${streamingThought}</think>` : '');
  const parsed = parseMarkdown(originalContentWithThinks);
  
  // The final content should NOT include think tags in the main text
  const finalContent = streamingContent || 'No response received';
  
  // Use the thought from streaming OR from parsing (whichever is available)
  const finalThought = streamingThought.trim() || parsed.thinking || null;
  
  // Update the text content with final parsed markdown (without think tags)
  const cleanParsed = parseMarkdown(finalContent);
  textElement.innerHTML = cleanParsed.content;
  
  // Remove streaming IDs from main elements but preserve thought container structure
  currentStreamingMessage.removeAttribute('id');
  textElement.removeAttribute('id');
  
  // Handle thought container properly - ALWAYS preserve if there's content
  if (thoughtContainer) {
    // Remove only the streaming IDs, keep everything else intact
    thoughtContainer.removeAttribute('id');
    if (thoughtContent) {
      thoughtContent.removeAttribute('id');
    }
    
    // Check if we have any thought content (prioritize streaming thought over parsed)
    const hasThoughtContent = finalThought && finalThought.trim();
    
    if (hasThoughtContent) {
      // Update the thought content with the final thought
      if (thoughtContent) {
        thoughtContent.textContent = finalThought;
      }
      // Ensure the thought container stays visible and properly configured
      thoughtContainer.style.display = 'block';
      // Keep it collapsed by default, let user expand if they want
      thoughtContainer.classList.remove('expanded');
    } else {
      // Only hide if there's truly no content
      thoughtContainer.style.display = 'none';
    }
  }
  
  // Add code block handlers to the finalized message
  addCodeBlockHandlers(currentStreamingMessage);
  
  // Add to chat history with the final thought
  currentChat.push({ 
    role: 'assistant', 
    content: finalContent, 
    thought: finalThought 
  });
  
  if (!currentChatId || currentChat.length === 2) {
    currentChatId = Date.now();
    saveChatToHistory();
  } else {
    updateChatInHistory();
  }
  
  // Ensure copy button is visible after streaming
  const messageActions = currentStreamingMessage.querySelector('.message-actions');
  if (messageActions) {
    messageActions.style.opacity = '1';
    setTimeout(() => {
      messageActions.style.opacity = ''; // Reset to use CSS hover rules after a delay
    }, 2000);
  }
  
  // Clean up streaming variables
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
}

// Remove the old showTypingIndicator and hideTypingIndicator functions since we're using live streaming now
function parseMarkdown(content) {
  // First, extract and remove thinking content before processing other markdown
  let thinkingContent = '';
  content = content.replace(/<think>([\s\S]*?)<\/think>/g, (match, thinking) => {
    thinkingContent = thinking.trim(); // Store the thinking content
    return ''; // Remove the thinking tags from the main content
  });

  // Now process the rest of the markdown normally
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
  content = content.replace(/```(\w+)?\n?([\s\S]*?)```/g, (match, lang, code) => {
    const language = lang || 'text';
    const highlighted = Prism.highlight(
      code.trim(),
      Prism.languages[language] || Prism.languages.plain,
      language
    );
    const isPreviewable = ['html', 'css', 'javascript', 'js'].includes(language.toLowerCase());
    const isRunnable = ['python', 'javascript', 'js'].includes(language.toLowerCase());
    const langClass = language ? `language-${language}` : '';
    return `
      <div class="code-container">
        <div class="code-header">
          <span class="code-lang ${langClass}">${language}</span>
          <div class="code-actions">
            <button class="code-btn" data-action="copy" data-tooltip="Copy code">
              <i class="fas fa-copy"></i>
            </button>
            <button class="code-btn download-btn" data-action="download" data-tooltip="Download code">
              <i class="fas fa-download"></i>
            </button>
            ${isPreviewable ?
              `<button class="code-btn preview-btn" data-action="preview" data-tooltip="Preview code">
                <i class="fas fa-eye"></i>
              </button>` : ''}
            ${isRunnable ?
              `<button class="code-btn run-btn" data-action="run" data-tooltip="Run code">
                <i class="fas fa-play"></i>
              </button>` : ''}
          </div>
        </div>
        <div class="code-content">
          <pre><code class="language-${language}">${highlighted}</code></pre>
        </div>
      </div>
    `;
  });
  content = content.replace(/`([^`]+)`/g, '<code>$1</code>');
  content = content.replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>');
  content = content.replace(/\*(.*?)\*/g, '<em>$1</em>');
  content = content.replace(/\n/g, '<br>');
  
  // Return both the processed content and the thinking content
  return { content: content, thinking: thinkingContent };
}
// Modified appendMessage to handle thought
function appendMessage(role, content, thought = null) {
  const message = document.createElement('div');
  message.className = `message ${role}`;
  const avatar = document.createElement('div');
  avatar.className = 'avatar';
  avatar.innerHTML = role === 'user'
    ? '<i class="fas fa-user"></i>'
    : '<i class="fas fa-robot"></i>';
  const messageContent = document.createElement('div');
  messageContent.className = 'message-content';
  
  // Add copy button for assistant messages - Make it visible by default
  if (role === 'assistant') {
    const messageActions = document.createElement('div');
    messageActions.className = 'message-actions';
    messageActions.style.opacity = '1'; // Make it visible by default
    messageActions.innerHTML = `
      <button class="message-action-btn" data-action="copy-response" title="Copy response">
        <i class="fas fa-copy"></i>
      </button>
    `;
    messageContent.appendChild(messageActions);
    
    // Add event listener for copy button
    messageActions.querySelector('[data-action="copy-response"]').addEventListener('click', () => {
      copyResponseText(message);
    });
    
    // Reset opacity after a delay to use CSS hover rules
    setTimeout(() => {
      messageActions.style.opacity = '';
    }, 2000);
  }
  
  const text = document.createElement('div');
  text.className = 'text';
  
  // Parse the markdown and get both content and thinking
  const parsed = parseMarkdown(content);
  text.innerHTML = parsed.content;
  
  // If there's extracted thinking from parseMarkdown, use it as the thought
  if (parsed.thinking && !thought) {
    thought = parsed.thinking;
  }
  
  messageContent.appendChild(text);
  
  // Add thought section if thought exists and is not empty
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
      // Add event listener to toggle thought visibility
      thoughtToggle.addEventListener('click', () => {
          thoughtContainer.classList.toggle('expanded');
      });
  }
  message.appendChild(avatar);
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

function copyResponseText(messageElement) {
  const textElement = messageElement.querySelector('.text');
  if (!textElement) return;
  
  // Get the text content without HTML tags but preserve line breaks
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
    // Show success feedback
    const copyBtn = messageElement.querySelector('[data-action="copy-response"]');
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
    console.error('Failed to copy response:', error);
  });
}

function addCodeBlockHandlers(messageElement) {
  messageElement.querySelectorAll('.code-btn').forEach(btn => {
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
        showPreview(code, language);
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
    let endpoint = 'python_runner.php';
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

function showPreview(code, language) {
  const previewWindow = document.getElementById('previewWindow');
  previewWindow.style.display = 'block';
  const previewContent = document.getElementById('previewContent');
  previewContent.innerHTML = `
    <div style="display: flex; justify-content: center; align-items: center; height: 100%; background: white;">
      <div style="text-align: center;">
        <div class="loading"></div>
        <div style="margin-top: 10px;">Loading preview...</div>
      </div>
    </div>
  `;
  if (language.toLowerCase() === 'html' || language.toLowerCase() === 'css' || language.toLowerCase() === 'javascript' || language.toLowerCase() === 'js') {
    const currentCodeContainer = event.target.closest('.code-container');
    if (!currentCodeContainer) return;
    const currentMessageContainer = currentCodeContainer.closest('.message');
    if (!currentMessageContainer) return;
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
    const codeContainers = currentMessageContainer.querySelectorAll('.code-container');
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
      fetch('preview.php', {
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
maximizePreviewBtn.addEventListener('click', () => {
  previewWindow.classList.toggle('maximized');
  if (previewWindow.classList.contains('maximized')) {
    maximizePreviewBtn.innerHTML = '<i class="fas fa-compress"></i>';
  } else {
    maximizePreviewBtn.innerHTML = '<i class="fas fa-expand"></i>';
  }
});

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
  if (currentChat.length === 0) return;
  const chatSummary = currentChat[0]?.content?.substring(0, 50) + '...';
  const chatData = {
    id: currentChatId || Date.now(),
    title: chatSummary,
    messages: [...currentChat],
    model: currentModel,
    timestamp: new Date().toISOString()
  };
  const existingIndex = chatHistory.findIndex(chat => chat.id === chatData.id);
  if (existingIndex !== -1) {
    chatHistory[existingIndex] = chatData;
  } else {
    chatHistory.unshift(chatData);
    if (chatHistory.length > 50) {
      chatHistory = chatHistory.slice(0, 50);
    }
  }
  localStorage.setItem('ollama_chat_history', JSON.stringify(chatHistory));
  updateChatHistoryUI();
}

function updateChatInHistory() {
  if (!currentChatId || currentChat.length ===  0) return;
  const existingIndex = chatHistory.findIndex(chat => chat.id === currentChatId);
  if (existingIndex !== -1) {
    chatHistory[existingIndex].messages = [...currentChat];
    chatHistory[existingIndex].timestamp = new Date().toISOString();
    localStorage.setItem('ollama_chat_history', JSON.stringify(chatHistory));
    updateChatHistoryUI();
  } else {
    saveChatToHistory();
  }
}

function deleteChatFromHistory(chatId) {
  if (confirm('Are you sure you want to delete this chat?')) {
    chatHistory = chatHistory.filter(chat => chat.id !== chatId);
    localStorage.setItem('ollama_chat_history', JSON.stringify(chatHistory));
    updateChatHistoryUI();
  }
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
  updateChatHistoryUI();
}

function updateChatHistoryUI() {
  const chatHistoryContainer = document.getElementById('chatHistory');
  chatHistoryContainer.innerHTML = '';
  if (chatHistory.length === 0) {
    chatHistoryContainer.innerHTML = '<p style="text-align: center; color: var(--text-secondary); padding: 20px;">No chat history yet</p>';
    return;
  }
  chatHistory.forEach(chat => {
    const chatItem = document.createElement('div');
    chatItem.className = 'chat-history-item';
    chatItem.innerHTML = `
      <div class="chat-history-item-header">
        <div style="font-weight: 600; font-size: 14px; margin-bottom: 4px;">${chat.title}</div>
        <div class="chat-history-actions">
          <button class="chat-history-action-btn" onclick="exportSpecificChat(${escape(JSON.stringify(chat))})" title="Export">
            <i class="fas fa-download"></i>
          </button>
          <button class="chat-history-action-btn" onclick="deleteChatFromHistory(${chat.id})" title="Delete">
            <i class="fas fa-trash"></i>
          </button>
        </div>
      </div>
      <div style="font-size: 12px; color: var(--text-secondary); margin-bottom: 4px;">
        ${chat.model} • ${new Date(chat.timestamp).toLocaleDateString()}
      </div>
      <div style="font-size: 12px; color: var(--text-secondary);">
        ${chat.messages.length} messages
      </div>
    `;
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
  modelSel.value = chat.model;
  setChatEnabled(!!currentModel);
  if (currentModel) {
    inp.placeholder = `Chat with ${currentModel}...`;
  }
  chat.messages.forEach(msg => {
    // When loading, pass the thought if it exists
    appendMessage(msg.role, msg.content, msg.thought);
  });
  toggleSidebar();
}

function newChat() {
  if (currentChat.length > 0 && confirm('Start a new chat? Current conversation will be saved to history.')) {
    clearChat();
  } else if (currentChat.length === 0) {
    clearChat();
  }
}

function clearChat() {
  msgs.innerHTML = '';
  currentChat = [];
  currentChatId = null;
  setChatEnabled(!!currentModel);
}

function clearAllChatHistory() {
  if (confirm('Are you sure you want to delete all chat history? This cannot be undone.')) {
    chatHistory = [];
    localStorage.removeItem('ollama_chat_history');
    updateChatHistoryUI();
  }
}

function exportChat() {
  if (currentChat.length === 0) {
    alert('No conversation to export');
    return;
  }
  // Include thought in the export for the current chat
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

window.addEventListener('message', function(event) {
  if (event.data && event.data.action === "bypassSecurity") {
    const previewData = event.data;
    if (previewData.isCombinedPreview) {
      fetch('preview.php', {
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
      fetch('preview.php', {
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