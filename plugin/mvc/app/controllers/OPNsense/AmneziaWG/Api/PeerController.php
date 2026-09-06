<?php
namespace OPNsense\AmneziaWG\Api;
use OPNsense\Base\ApiMutableModelControllerBase;
use OPNsense\Core\Backend;

class PeerController extends ApiMutableModelControllerBase
{
    protected static $internalModelClass='\\OPNsense\\AmneziaWG\\Peer';
    protected static $internalModelName='peer';
    const PRIVKEY_DIR='/usr/local/etc/amnezia';
    const HANDSHAKE_FRESH_SEC=180;

    public function searchItemAction()
    {
        $r=$this->searchBase('peer',['enabled','name','server','public_key','allowed_ips','description']);
        $servers=[];
        foreach((new \OPNsense\AmneziaWG\Server())->server->iterateItems() as $u=>$s){
            $servers[(string)$u]=[
                'name'=>(string)$s->name,
                'interface'=>'awg'.(int)(string)$s->interface_number,
            ];
        }
        $status=json_decode((string)(new Backend())->configdRun('amneziawg status'),true);
        $runtime=null;
        if(is_array($status)){
            $runtime=[];
            foreach(($status['tunnels']??[]) as $t){
                $runtime[(string)($t['interface']??'')]=is_array($t['peers']??null)?$t['peers']:[];
            }
        }
        $now=time();
        foreach($r['rows'] as &$row){
            $serverId=(string)($row['server']??'');
            $server=$servers[$serverId]??null;
            $row['server_name']=$server['name']??'missing';
            $row['client_config']=file_exists($this->keyFilePath((string)($row['uuid']??'')))?'ready':'unavailable';
            $row['runtime']='';
            $row['runtime_endpoint']='';
            $row['latest_handshake']=0;
            if($runtime===null || $server===null)continue;
            $iface=$server['interface'];
            if(!array_key_exists($iface,$runtime)){
                $row['runtime']='stopped';
                continue;
            }
            $key=(string)($row['public_key']??'');
            if($key==='' || !isset($runtime[$iface][$key])){
                $row['runtime']='never';
                continue;
            }
            $peer=$runtime[$iface][$key];
            $hs=(int)($peer['latest_handshake']??0);
            $row['latest_handshake']=$hs;
            $row['runtime_endpoint']=(string)($peer['endpoint']??'');
            if($hs<=0)$row['runtime']='never';
            elseif(($now-$hs)<=self::HANDSHAKE_FRESH_SEC)$row['runtime']='active';
            else $row['runtime']='inactive';
        }
        unset($row);
        return $r;
    }
    public function getItemAction($uuid=null){$r=$this->getBase('peer','peer',$uuid);if(isset($r['peer']))$r['peer']['client_private_key']='';return $r;}

    public function addItemAction(){
        if(!$this->request->isPost())return ['result'=>'failed'];
        $body=$this->request->getPost('peer',null,[]);$key=$this->validateClientPrivateKey($body);
        if(isset($key['validation']))return ['result'=>'failed','validations'=>['peer.public_key'=>$key['validation']]];
        $v=$this->validatePeer($body,null);if($v)return ['result'=>'failed','validations'=>$v];
        $r=$this->addBase('peer','peer');
        if(($r['result']??'')!=='failed' && $key['key']!==null && !empty($r['uuid'])){
            try{$this->writePrivateKey((string)$r['uuid'],$key['key']);}catch(\RuntimeException $e){$this->delBase('peer',(string)$r['uuid']);return ['result'=>'failed','validations'=>['peer.public_key'=>$e->getMessage()]];}
        }
        return $r;
    }
    public function setItemAction($uuid){
        if(!$this->request->isPost())return ['result'=>'failed'];
        $body=$this->request->getPost('peer',null,[]);$key=$this->validateClientPrivateKey($body);
        if(isset($key['validation']))return ['result'=>'failed','validations'=>['peer.public_key'=>$key['validation']]];
        $v=$this->validatePeer($body,(string)$uuid);if($v)return ['result'=>'failed','validations'=>$v];
        $r=$this->setBase('peer','peer',$uuid);
        if(($r['result']??'')==='saved' && $key['key']!==null){try{$this->writePrivateKey((string)$uuid,$key['key']);}catch(\RuntimeException $e){return ['result'=>'failed','validations'=>['peer.public_key'=>$e->getMessage()]];}}
        return $r;
    }
    public function delItemAction($uuid){$r=$this->delBase('peer',$uuid);if(($r['result']??'')==='deleted')@unlink($this->keyFilePath((string)$uuid));return $r;}
    public function toggleItemAction($uuid,$enabled=null){return $this->toggleBase('peer',$uuid,$enabled);}
    public function serversAction(){ $rows=[]; foreach((new \OPNsense\AmneziaWG\Server())->server->iterateItems() as $u=>$s)$rows[]=['uuid'=>(string)$u,'name'=>(string)$s->name,'interface'=>'awg'.(int)(string)$s->interface_number,'enabled'=>(string)$s->enabled]; return ['rows'=>$rows]; }

    public function genClientKeysAction()
    {
        $pair=json_decode((string)(new Backend())->configdRun('amneziawg gen_keypair'),true);
        if(!isset($pair['private_key'],$pair['public_key']))return ['status'=>'error','message'=>$pair['message']??'Client key generation failed'];
        $proc=proc_open(['/usr/local/bin/awg','genpsk'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($proc))return ['status'=>'error','message'=>'Unable to run awg genpsk'];
        fclose($pipes[0]);
        $psk=trim((string)stream_get_contents($pipes[1]));fclose($pipes[1]);
        $err=trim((string)stream_get_contents($pipes[2]));fclose($pipes[2]);
        $rc=proc_close($proc);
        if($rc!==0||!preg_match('/^[A-Za-z0-9+\/]{43}=$/',$psk))return ['status'=>'error','message'=>$err!==''?$err:'Unable to generate preshared key'];
        return ['status'=>'ok','private_key'=>$pair['private_key'],'public_key'=>$pair['public_key'],'preshared_key'=>$psk];
    }

    public function clientConfigAction($uuid='')
    {
        if(!preg_match('/^[a-fA-F0-9]{8}(-[a-fA-F0-9]{4}){3}-[a-fA-F0-9]{12}$/',(string)$uuid))return ['status'=>'error','message'=>'Invalid peer UUID'];
        $peer=$this->getModel()->getNodeByReference('peer.'.(string)$uuid);if($peer===null)return ['status'=>'error','message'=>'Peer not found'];
        return $this->buildClientConfig((string)$uuid,$peer);
    }
    public function clientConfigLookupAction()
    {
        if(!$this->request->isPost())return ['status'=>'error','message'=>'POST required'];
        $b=$this->request->getPost('peer',null,[]);$server=trim((string)($b['server']??''));$public=trim((string)($b['public_key']??''));
        foreach($this->getModel()->peer->iterateItems() as $u=>$peer)if((string)$peer->server===$server && (string)$peer->public_key===$public)return $this->buildClientConfig((string)$u,$peer);
        return ['status'=>'error','message'=>'Save this peer before generating its client configuration'];
    }
    private function buildClientConfig(string $uuid,$peer):array
    {
        $keyPath=$this->keyFilePath($uuid);if(!file_exists($keyPath))return ['status'=>'error','message'=>'Client private key is unavailable for this peer. Generate a new client keypair and save the peer first.'];
        $private=trim((string)file_get_contents($keyPath));if(!preg_match('/^[A-Za-z0-9+\/]{43}=$/',$private))return ['status'=>'error','message'=>'Stored client private key is invalid'];
        $sm=new \OPNsense\AmneziaWG\Server();$server=$sm->getNodeByReference('server.'.(string)$peer->server);if($server===null)return ['status'=>'error','message'=>'Peer server not found'];
        $endpoint=trim((string)$server->clients_endpoint);if($endpoint==='')return ['status'=>'error','message'=>'Clients Endpoint is not configured on this server'];
        $serverPublic=$this->serverPublicKey((string)$peer->server,$server);if(isset($serverPublic['error']))return ['status'=>'error','message'=>$serverPublic['error']];
        $rows=['[Interface]','PrivateKey = '.$private,'Address = '.trim((string)$peer->allowed_ips)];
        if(trim((string)$server->clients_dns)!=='')$rows[]='DNS = '.trim((string)$server->clients_dns);
        if(trim((string)$server->mtu)!=='')$rows[]='MTU = '.trim((string)$server->mtu);
        $fieldMap=['jc'=>'Jc','jmin'=>'Jmin','jmax'=>'Jmax','s1'=>'S1','s2'=>'S2','s3'=>'S3','s4'=>'S4','h1'=>'H1','h2'=>'H2','h3'=>'H3','h4'=>'H4',
            'header_protection_key'=>'HeaderProtectionKey','content_padding_addition'=>'ContentPaddingAddition',
            'rekey_after_time'=>'RekeyAfterTime','rekey_timeout'=>'RekeyTimeout','reject_after_time'=>'RejectAfterTime',
            'keepalive_timeout'=>'KeepaliveTimeout','max_handshake_attempts'=>'MaxHandshakeAttempts',
            'i1'=>'I1','i2'=>'I2','i3'=>'I3','i4'=>'I4','i5'=>'I5'];
        foreach($fieldMap as $f=>$label){$v=trim((string)$server->$f);if($v!=='')$rows[]=$label.' = '.$v;}
        foreach(['random_trailers'=>'RandomTrailers','disable_cookies'=>'DisableCookies'] as $f=>$label){$v=(string)$server->$f;if($v==='1')$rows[]=$label.' = on';}
        $rows[]='';$rows[]='[Peer]';$rows[]='PublicKey = '.$serverPublic['key'];
        if(trim((string)$peer->preshared_key)!=='')$rows[]='PresharedKey = '.trim((string)$peer->preshared_key);
        $keepalive=trim((string)$peer->persistent_keepalive);
        if($keepalive==='')$keepalive=trim((string)$server->clients_keepalive);
        if($keepalive==='')$keepalive='25';
        $rows[]='Endpoint = '.$endpoint;$rows[]='AllowedIPs = 0.0.0.0/0';$rows[]='PersistentKeepalive = '.$keepalive;
        $name=preg_replace('/[^A-Za-z0-9_.-]+/','_',trim((string)$peer->name));if($name==='')$name='amneziawg-client';
        $config=implode("\n",$rows)."\n";
        // AmneziaVPN 5.0.1.5 multipart QR framing for a native AWG config.
        // The scanner reassembles the raw UTF-8 .conf bytes first and then
        // feeds that exact native config to the normal AWG import path.
        // QDataStream layout per chunk:
        //   qint16 magic=1984, quint8 total, quint8 id, QByteArray(raw chunk)
        // QByteArray is serialized as uint32_be length followed by its bytes.
        $rawChunks=str_split($config,400);
        $chunksCount=count($rawChunks);
        if($chunksCount<1||$chunksCount>255)return ['status'=>'error','message'=>'Client configuration requires an unsupported number of QR chunks'];
        $qrPayloads=[];
        foreach($rawChunks as $chunkId=>$chunk){
            $packed=pack('nCCN',1984,$chunksCount,$chunkId,strlen($chunk)).$chunk;
            $payload=rtrim(strtr(base64_encode($packed),'+/','-_'),'=');
            if($payload==='')return ['status'=>'error','message'=>'Unable to build AmneziaVPN QR payload'];
            $qrPayloads[]=$payload;
        }
        return [
            'status'=>'ok',
            'name'=>$name.'.conf',
            'config_b64'=>base64_encode($config),
            'amnezia_qr_chunks'=>$qrPayloads
        ];
    }
    private function serverPublicKey(string $uuid,$server):array
    {
        $stored=trim((string)$server->private_key);$path=self::PRIVKEY_DIR.'/server-'.preg_replace('/[^a-fA-F0-9\-]/','',$uuid).'.key';
        $private=$stored==='::file::'?(file_exists($path)?trim((string)file_get_contents($path)):''):$stored;
        if(!preg_match('/^[A-Za-z0-9+\/]{43}=$/',$private))return ['error'=>'Server private key is invalid or missing'];
        $proc=proc_open(['/usr/local/bin/awg','pubkey'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))return ['error'=>'Unable to run awg pubkey'];
        fwrite($pipes[0],$private."\n");fclose($pipes[0]);$public=trim((string)stream_get_contents($pipes[1]));fclose($pipes[1]);$err=trim((string)stream_get_contents($pipes[2]));fclose($pipes[2]);$rc=proc_close($proc);
        if($rc!==0||!preg_match('/^[A-Za-z0-9+\/]{43}=$/',$public))return ['error'=>$err!==''?$err:'Unable to derive server public key'];return ['key'=>$public];
    }
    private function validateClientPrivateKey(array $b):array
    {
        $key=trim((string)($b['client_private_key']??''));if($key==='')return ['key'=>null];if(!preg_match('/^[A-Za-z0-9+\/]{43}=$/',$key))return ['validation'=>'Generated client private key is invalid'];
        $public=trim((string)($b['public_key']??''));$proc=proc_open(['/usr/local/bin/awg','pubkey'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);if(!is_resource($proc))return ['validation'=>'Unable to validate client keypair'];fwrite($pipes[0],$key."\n");fclose($pipes[0]);$derived=trim((string)stream_get_contents($pipes[1]));fclose($pipes[1]);stream_get_contents($pipes[2]);fclose($pipes[2]);$rc=proc_close($proc);if($rc!==0||$derived!==$public)return ['validation'=>'Client private key does not match Client Public Key'];return ['key'=>$key];
    }
    private function validKeepaliveRange(string $v):bool{if(!preg_match('/^\d{1,5}(-\d{1,5})?$/',$v))return false;$p=explode('-',$v,2);$lo=(int)$p[0];$hi=isset($p[1])?(int)$p[1]:$lo;return $lo<=65535&&$hi<=65535&&$hi>=$lo;}
    private function keyFilePath(string $u):string{return self::PRIVKEY_DIR.'/peer-'.preg_replace('/[^a-fA-F0-9\-]/','',$u).'.key';}
    private function writePrivateKey(string $u,string $k):void{if(!is_dir(self::PRIVKEY_DIR)&&!mkdir(self::PRIVKEY_DIR,0700,true))throw new \RuntimeException('Cannot create key directory');@chmod(self::PRIVKEY_DIR,0700);$tmp=tempnam(self::PRIVKEY_DIR,'.peer-keytmp-');if($tmp===false)throw new \RuntimeException('Cannot create temporary client key file');try{if(file_put_contents($tmp,trim($k)."\n",LOCK_EX)===false)throw new \RuntimeException('Cannot write client private key');if(!chmod($tmp,0600))throw new \RuntimeException('Cannot secure client private key permissions');if(!rename($tmp,$this->keyFilePath($u)))throw new \RuntimeException('Cannot install client private key');$tmp='';}finally{if($tmp!==''&&file_exists($tmp))@unlink($tmp);}}
    private function validatePeer(array $b,?string $self):array{
        $e=[];$ka=trim((string)($b['persistent_keepalive']??''));if($ka!=='' && (!$this->validKeepaliveRange($ka)))$e['peer.persistent_keepalive']='Keepalive must be 0-65535 or a range with start <= end';$srv=trim($b['server']??'');$sm=new \OPNsense\AmneziaWG\Server();if($srv===''||$sm->getNodeByReference('server.'.$srv)===null)$e['peer.server']='Select an existing server';$pk=trim($b['public_key']??'');$newAllowed=array_filter(array_map('trim',explode(',',(string)($b['allowed_ips']??''))));foreach($this->getModel()->peer->iterateItems() as $u=>$p){if(($self!==null&&(string)$u===$self)||(string)$p->server!==$srv)continue;if($pk!==''&&(string)$p->public_key===$pk)$e['peer.public_key']='This public key is already configured on the selected server';$existingAllowed=array_filter(array_map('trim',explode(',',(string)$p->allowed_ips)));$duplicates=array_values(array_intersect($newAllowed,$existingAllowed));if(!empty($duplicates))$e['peer.allowed_ips']='Allowed IP '.implode(', ',$duplicates).' is already assigned to another peer on this server';}return $e;
    }
}
