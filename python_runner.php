<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
  exit(0);
}

if($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Method not allowed']);
  exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$code = $input['code'] ?? '';
$language = strtolower($input['language'] ?? 'python');

if (!$code) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'No code provided']);
  exit;
}


function executeCode($code, $language) {
  $timeout = 5;
  
  switch ($language) {
    case 'python':
    case 'py':
      return executePython($code, $timeout, null, null);
      
    case 'javascript':
    case 'js':
      return executeJavaScript($code);
      
    default:
      return ['success' => false, 'error' => "Unsupported language: $language"];
  }
}

function executePython($code, $timeout, $outputFile, $errorFile) {
  $execId = uniqid('py_');
  $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $execId . '.py';
  file_put_contents($tempFile, $code);
  
  $pythonCmd = PHP_OS === 'WINNT' ? 'where python' : 'which python';
  exec($pythonCmd, $output, $exitCode);
  
  if ($exitCode !== 0) {
    @unlink($tempFile);
    return ['success' => false, 'error' => 'Python is not installed or not in PATH'];
  }
  
  $pythonPath = trim($output[0]);
  
  if (PHP_OS === 'WINNT') {
    $outputFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $execId . '_output.txt';
    $errorFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $execId . '_error.txt';
    
    $cmd = "\"$pythonPath\" \"$tempFile\" > \"$outputFile\" 2> \"$errorFile\"";
    
    $result = 0;
    $output = [];
    
    $startTime = microtime(true);
    exec($cmd, $output, $result);
    $executionTime = microtime(true) - $startTime;
    
    if ($executionTime >= $timeout) {
      @unlink($tempFile);
      @unlink($outputFile);
      @unlink($errorFile);
      return ['success' => false, 'error' => 'Code execution timed out'];
    }
    
    $stdout = file_exists($outputFile) ? @file_get_contents($outputFile) : '';
    $stderr = file_exists($errorFile) ? @file_get_contents($errorFile) : '';
    
    @unlink($tempFile);
    @unlink($outputFile);
    @unlink($errorFile);
    
    if ($result !== 0) {
      return ['success' => false, 'error' => trim($stderr) ?: 'Unknown error occurred'];
    } else {
      return ['success' => true, 'output' => trim($stdout)];
    }
  } else {
    $cmd = "timeout $timeout \"$pythonPath\" \"$tempFile\" > \"$outputFile\" 2> \"$errorFile\"";
    exec($cmd, $output, $exitCode);
    
    $stdout = file_get_contents($outputFile);
    $stderr = file_get_contents($errorFile);
    
    @unlink($tempFile);
    
    if ($exitCode === 124 || $exitCode === -1) {
      return ['success' => false, 'error' => 'Code execution timed out'];
    } elseif ($exitCode !== 0) {
      return ['success' => false, 'error' => trim($stderr) ?: 'Unknown error occurred'];
    } else {
      return ['success' => true, 'output' => trim($stdout)];
    }
  }
}

function executeJavaScript($code) {
  return ['success' => false, 'error' => 'JavaScript execution is handled client-side'];
}

$result = executeCode($code, $language);
echo json_encode($result);
?>
