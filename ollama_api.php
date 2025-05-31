<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin:*');
header('Access-Control-Allow-Methods:GET,POST,OPTIONS');
header('Access-Control-Allow-Headers:Content-Type');

if($_SERVER['REQUEST_METHOD']==='OPTIONS') exit(0);

class Ollama {
    private $url='http://localhost:11434';
    public function status(){
        $c=curl_init("{$this->url}/api/tags");
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>3]);
        curl_exec($c);
        $ok = curl_getinfo($c,CURLINFO_HTTP_CODE)===200;
        curl_close($c);
        return $ok;
    }
    public function models(){
        $c=curl_init("{$this->url}/api/tags");
        curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>10]);
        $r=curl_exec($c);
        $hc=curl_getinfo($c,CURLINFO_HTTP_CODE);
        curl_close($c);
        if($hc!==200) return [];
        $j=json_decode($r,true);
        return $j['models']??[];
    }
    
    public function chat($model, $messages, $useMemory = true){
        if (!$useMemory && count($messages) > 0) {
            $messages = [end($messages)];
        }
        
        $data = [
            'model' => $model,
            'messages' => $messages,
            'stream' => false,
            'options' => [
                'temperature' => 0.7,
                'top_p' => 0.9,
                'top_k' => 40,
                'num_ctx' => 4096
            ]
        ];
        
        $c = curl_init("{$this->url}/api/chat");
        curl_setopt_array($c, [
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        
        $r = curl_exec($c);
        $hc = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        
        if($hc !== 200) throw new Exception("HTTP $hc");
        $j = json_decode($r, true);
        if(isset($j['error'])) throw new Exception($j['error']);
        
        return $j['message']['content'] ?? '';
    }

    public function chatStream($model, $messages, $useMemory = true) {
        if (!$useMemory && count($messages) > 0) {
            $messages = [end($messages)];
        }
        
        $data = [
            'model' => $model,
            'messages' => $messages,
            'stream' => true,
            'options' => [
                'temperature' => 0.7,
                'top_p' => 0.9,
                'top_k' => 40,
                'num_ctx' => 4096
            ]
        ];
        
        // Set headers for streaming
        header('Content-Type: text/plain');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        
        // Disable output buffering for real-time streaming
        if (ob_get_level()) {
            ob_end_clean();
        }
        
        $c = curl_init("{$this->url}/api/chat");
        curl_setopt_array($c, [
            CURLOPT_POST => 1,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_WRITEFUNCTION => [$this, 'streamCallback'],
            CURLOPT_TIMEOUT => 120,
            CURLOPT_BUFFERSIZE => 128
        ]);
        
        curl_exec($c);
        $hc = curl_getinfo($c, CURLINFO_HTTP_CODE);
        curl_close($c);
        
        if($hc !== 200) {
            echo "data: " . json_encode(['error' => "HTTP $hc"]) . "\n\n";
        }
        echo "data: [DONE]\n\n";
        flush();
    }
    
    private function streamCallback($ch, $data) {
        $lines = explode("\n", $data);
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) continue;
            
            $json = json_decode($line, true);
            if ($json && isset($json['message']['content'])) {
                $content = $json['message']['content'];
                echo "data: " . json_encode(['content' => $content]) . "\n\n";
                flush();
            }
            
            if ($json && isset($json['done']) && $json['done']) {
                return strlen($data);
            }
        }
        return strlen($data);
    }
}

$api=new Ollama();

try {
  if($_SERVER['REQUEST_METHOD']==='GET'){
    $a=$_GET['action']??'';
    if($a==='status') {
      echo json_encode(['status'=>$api->status()?'online':'offline']);
      exit;
    }
    if($a==='models'){
      $ms=$api->models();
      $out=[];
      foreach($ms as $m) $out[]= ['name'=>$m['name'],'size'=>round($m['size']/1024/1024,2).' MB'];
      echo json_encode(['success'=>true,'models'=>$out]);
      exit;
    }
    throw new Exception('Invalid action');
  }
  $in=json_decode(file_get_contents('php://input'),true);
  if(!$in||!isset($in['action'])) throw new Exception('Bad request');
  
  if($in['action']==='chat'){
    if(empty($in['model'])||empty($in['messages'])) throw new Exception('Model & messages required');
    $useMemory = $in['useMemory'] ?? true;
    $resp=$api->chat($in['model'], $in['messages'], $useMemory);
    echo json_encode(['success'=>true,'response'=>$resp]);
    exit;
  }
  
  if($in['action']==='chat_stream'){
    if(empty($in['model'])||empty($in['messages'])) throw new Exception('Model & messages required');
    $useMemory = $in['useMemory'] ?? true;
    $api->chatStream($in['model'], $in['messages'], $useMemory);
    exit;
  }
  
  throw new Exception('Unknown action');
} catch(Exception $e){
  http_response_code(400);
  echo json_encode(['success'=>false,'error'=>$e->getMessage()]);
}
?>