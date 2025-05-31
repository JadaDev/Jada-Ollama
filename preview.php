<?php
header('Content-Type: text/html');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit(0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo '<html><body><h1>Method Not Allowed</h1><p>This endpoint only accepts POST requests.</p></body></html>';
  exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';
$language = strtolower($input['language'] ?? 'html');

$isCombinedPreview = isset($input['combinedPreview']) && $input['combinedPreview'] === true;
$htmlCode = $input['htmlCode'] ?? '';
$cssCode = $input['cssCode'] ?? '';
$jsCode = $input['jsCode'] ?? '';

if ($isCombinedPreview) {
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
      <meta charset="UTF-8">
      <meta name="viewport" content="width=device-width, initial-scale=1.0">
      <title>Combined Preview</title>
      <style>
          body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
          
          <?php echo $cssCode; ?>
      </style>
  </head>
  <body>
      <?php echo $htmlCode; ?>
      
      <script>
          const originalConsole = window.console;
          window.console = {
              log: function(...args) {
                  originalConsole.log(...args);
              },
              error: function(...args) {
                  originalConsole.error(...args);
              },
              warn: function(...args) {
                  originalConsole.warn(...args);
              },
              info: function(...args) {
                  originalConsole.info(...args);
              }
          };
          
          try {
              <?php echo $jsCode; ?>
          } catch (error) {
              console.error("Error in JavaScript:", error.message);
          }
      </script>
  </body>
  </html>
  <?php
  exit;
}

switch ($language) {
  case 'html':
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>HTML Preview</title>
        <style>
            body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
        </style>
    </head>
    <body>
        <div id="preview-content">
            <?php echo $code; ?>
        </div>
    </body>
    </html>
    <?php
    break;
    
  case 'css':
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CSS Preview</title>
        <style>
            body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            .preview-note { background: #f8f9fa; border-left: 4px solid #007bff; padding: 10px; margin-bottom: 20px; }
            <?php echo $code; ?>
        </style>
    </head>
    <body>
        <div class="preview-note">This is a CSS preview with sample elements below:</div>
        <div class="sample-content">
            <h1>Heading 1</h1>
            <h2>Heading 2</h2>
            <p>This is a paragraph of text for testing CSS styles. It contains <a href="#">a link</a>, <strong>bold text</strong>, and <em>emphasized text</em>.</p>
            <div class="container">
                <div class="box">Box 1</div>
                <div class="box">Box 2</div>
                <div class="box">Box 3</div>
            </div>
            <ul>
                <li>List item 1</li>
                <li>List item 2</li>
                <li>List item 3</li>
            </ul>
            <button>Sample Button</button>
        </div>
    </body>
    </html>
    <?php
    break;
    
  case 'javascript':
  case 'js':
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>JavaScript Preview</title>
        <style>
            body { margin: 0; padding: 20px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
            #console { background: #f8f9fa; border: 1px solid #ddd; padding: 10px; margin-top: 20px; height: 200px; overflow-y: auto; font-family: monospace; }
            .log { color: #333; }
            .error { color: #dc3545; }
            .warn { color: #fd7e14; }
            .preview-note { background: #f8f9fa; border-left: 4px solid #007bff; padding: 10px; margin-bottom: 20px; }
            #output { margin-bottom: 20px; min-height: 50px; padding: 10px; }
            .dom-container { border: 1px dashed #ddd; padding: 10px; margin: 10px 0; }
        </style>
    </head>
    <body>
        <div class="preview-note">This is a JavaScript preview environment. Use the DOM or console output below.</div>
        <div id="playground">
            <div class="dom-container">
                <h4>DOM Manipulation Area:</h4>
                <div id="output"></div>
            </div>
            <div id="console"></div>
        </div>
        
        <script>
            const consoleOutput = document.getElementById('console');
            const realConsole = window.console;
            
            window.console = {
                log: function(...args) {
                    const message = args.map(arg => {
                        if (arg === null) return 'null';
                        if (arg === undefined) return 'undefined';
                        if (typeof arg === 'object') {
                            try {
                                return JSON.stringify(arg, null, 2);
                            } catch (e) {
                                return String(arg);
                            }
                        }
                        return String(arg);
                    }).join(' ');
                    const logElement = document.createElement('div');
                    logElement.className = 'log';
                    logElement.textContent = '> ' + message;
                    consoleOutput.appendChild(logElement);
                    realConsole.log(...args);
                },
                error: function(...args) {
                    const message = args.map(arg => typeof arg === 'object' ? JSON.stringify(arg) : String(arg)).join(' ');
                    const logElement = document.createElement('div');
                    logElement.className = 'error';
                    logElement.textContent = '❌ ' + message;
                    consoleOutput.appendChild(logElement);
                    realConsole.error(...args);
                },
                warn: function(...args) {
                    const message = args.map(arg => typeof arg === 'object' ? JSON.stringify(arg) : String(arg)).join(' ');
                    const logElement = document.createElement('div');
                    logElement.className = 'warn';
                    logElement.textContent = '⚠️ ' + message;
                    consoleOutput.appendChild(logElement);
                    realConsole.warn(...args);
                },
                info: function(...args) {
                    this.log(...args);
                },
                clear: function() {
                    consoleOutput.innerHTML = '';
                    realConsole.clear();
                }
            };
            
            try {
                <?php echo $code; ?>
            } catch (error) {
                console.error(error.message);
                console.error("Stack trace: " + error.stack);
            }
        </script>
    </body>
    </html>
    <?php
    break;
    
  default:
    echo '<html><body><h1>Unsupported Language</h1><p>Preview is only available for HTML, CSS, and JavaScript.</p></body></html>';
}
?>