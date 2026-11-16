<?php
namespace FusionSolarTest;
use Exception;
// Isolierter Test ohne Netzwerk oder Symcon: php tests/web_session.php

$constants = ['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_ENCODING','CURLOPT_COOKIEFILE','CURLOPT_USERAGENT','CURLOPT_REFERER','CURLOPT_COOKIELIST','CURLOPT_URL','CURLOPT_HTTPHEADER','CURLINFO_HTTP_CODE','CURLINFO_COOKIELIST','CURLOPT_HTTPGET','CURLOPT_POSTFIELDS','CURLOPT_HEADERFUNCTION'];
foreach ($constants as $i => $name) { if (!defined($name)) define($name, $i + 1); }
define('VARIABLETYPE_FLOAT', 2);
class IPSModule {
    public $InstanceID = 7;
    public $properties = [], $attributes = [], $values = [], $timers = [], $status;
    public function Create() {}
    public function ApplyChanges() {}
    public function __call($name, $args) {
        if (strpos($name, 'RegisterProperty') === 0) {$this->properties[$args[0]] = $args[1]; return;}
        if (strpos($name, 'ReadProperty') === 0) return $this->properties[$args[0]];
        if (strpos($name, 'RegisterAttribute') === 0) {$this->attributes[$args[0]] = $args[1]; return;}
        if (strpos($name, 'ReadAttribute') === 0) return $this->attributes[$args[0]];
        if (strpos($name, 'WriteAttribute') === 0) {$this->attributes[$args[0]] = $args[1]; return;}
        if (strpos($name, 'RegisterVariable') === 0 || $name === 'UpdateFormField') return;
        if ($name === 'SetValue') {$this->values[$args[0]] = $args[1]; return;}
        if ($name === 'SetStatus') {$this->status = $args[0]; return;}
        if ($name === 'RegisterTimer' || $name === 'SetTimerInterval') {$this->timers[$args[0]] = $args[1]; return;}
        throw new Exception('Unexpected mock call: ' . $name);
    }
}
$objects = [];
function IPS_CreateCategory() {return createObject(0);}
function IPS_CreateVariable($type) {$id=createObject(2);$GLOBALS['objects'][$id]['VariableType']=$type;return $id;}
function createObject($type) {static $id=100; $id++;$GLOBALS['objects'][$id]=['ObjectType'=>$type,'ObjectIdent'=>'','ParentID'=>0,'Name'=>''];return $id;}
function IPS_GetObjectIDByIdent($ident,$parent) {foreach($GLOBALS['objects'] as $id=>$o)if($o['ObjectIdent']===$ident && $o['ParentID']===$parent)return $id;return false;}
function IPS_GetObject($id) {return $GLOBALS['objects'][$id];}
function IPS_GetVariable($id) {return $GLOBALS['objects'][$id];}
function IPS_GetName($id) {return $GLOBALS['objects'][$id]['Name'];}
function IPS_SetParent($id,$parent) {$GLOBALS['objects'][$id]['ParentID']=$parent;}
function IPS_SetIdent($id,$ident) {$GLOBALS['objects'][$id]['ObjectIdent']=$ident;}
function IPS_SetName($id,$name) {$GLOBALS['objects'][$id]['Name']=$name;}
function IPS_SetVariableCustomProfile($id,$profile) {$GLOBALS['objects'][$id]['Profile']=$profile;}
function IPS_GetChildrenIDs($parent) {return array_keys(array_filter($GLOBALS['objects'],static function($o)use($parent){return $o['ParentID']===$parent;}));}
function SetValue($id,$value) {$GLOBALS['objects'][$id]['Value']=$value;$GLOBALS['m']->values[$GLOBALS['objects'][$id]['ObjectIdent']]=$value;}
function IPS_VariableProfileExists($name) {return true;}
function IPS_SemaphoreEnter($name, $timeout) {return true;}
function IPS_SemaphoreLeave($name) {}
function function_exists($name) {return $name === 'curl_init' || \function_exists($name);}
function curl_init() {return (object)['options'=>[]];}
function curl_setopt_array($ch,$options) {foreach($options as $k=>$v)curl_setopt($ch,$k,$v); return true;}
function curl_setopt($ch,$k,$v) {$ch->options[$k]=$v; if($k===CURLOPT_COOKIELIST)$GLOBALS['loaded'][]=$v; return true;}
function curl_getinfo($ch,$key) {
    if($key===CURLINFO_HTTP_CODE)return $GLOBALS['http'];
    if (!empty($GLOBALS['loginCookies'])) return $GLOBALS['loginCookies'];
    return ["uni004eu5.fusionsolar.huawei.com\tFALSE\t/\tTRUE\t0\tJSESSIONID\trotated"];
}
function curl_close($ch) {}
function curl_exec($ch) {
    $GLOBALS['calls']++;
    if (!empty($GLOBALS['loginQueue'])) {
        $next = array_shift($GLOBALS['loginQueue']);
        if (strpos($ch->options[CURLOPT_URL], $next[0]) === false) throw new Exception('Unexpected login URL');
        if (strpos($next[0], 'validateUser.action') !== false) {
            $payload = json_decode($ch->options[CURLOPT_POSTFIELDS] ?? '', true);
            if (($payload['username'] ?? '') !== 'test%40example.invalid' || empty($payload['password'])
                || strpos($payload['password'], 'synthetic-only') !== false) throw new Exception('Login payload encoding');
            if (strpos($ch->options[CURLOPT_URL], 'nonce=') === false) throw new Exception('Missing nonce');
        } elseif (strpos($next[0], '/unisso/') !== false || strpos($next[0], 'credential-ready') !== false || strpos($next[0], 'cloud.html') !== false) {
            if (empty($ch->options[CURLOPT_HTTPGET])) throw new Exception('Login GET method missing');
        }
        $GLOBALS['http'] = $next[1];
        if (isset($next[3])) ($ch->options[CURLOPT_HEADERFUNCTION])($ch, 'Location: ' . $next[3]);
        return $next[2];
    }
    if(strpos($ch->options[CURLOPT_URL],'keep-alive')!==false)return json_encode(['code'=>$GLOBALS['keepCode'],'payload'=>'renewed-secret']);
    if(!in_array('roarand: renewed-secret',$ch->options[CURLOPT_HTTPHEADER],true))throw new Exception('Renewed token missing');
    if($ch->options[CURLOPT_FOLLOWLOCATION]!==false)throw new Exception('Redirects enabled');
    if (strpos($ch->options[CURLOPT_URL], 'device-realtime-data') !== false) {
        parse_str(parse_url($ch->options[CURLOPT_URL], PHP_URL_QUERY), $query);
        $GLOBALS['deviceCalls'][] = $query['deviceDn'];
        return json_encode($GLOBALS['deviceResponses'][$query['deviceDn']]);
    }
    return json_encode(['success'=>true,'failCode'=>0,'data'=>['flow'=>$GLOBALS['flow']]]);
}
$trait = file_get_contents(__DIR__ . '/../libs/WebSession.php');
eval('namespace FusionSolarTest; use \RuntimeException; use \Throwable; use \DateTimeImmutable; use \DateTimeZone; ' . substr($trait, 5));
require_once __DIR__ . '/../libs/WebLoginCrypto.php';
$loginTrait = preg_replace('/require_once[^;]+;/', '', file_get_contents(__DIR__ . '/../libs/WebLogin.php'));
eval('namespace FusionSolarTest; use \RuntimeException; use \FusionSolarWebLoginCrypto; ' . substr($loginTrait, 5));
$module = file_get_contents(__DIR__ . '/../Huawei Fusion Solar/module.php');
$module = preg_replace('/require_once[^;]+;/', '', $module);
eval('namespace FusionSolarTest; use \Throwable; use \Exception; ' . substr($module, 5));
function check($condition,$name) {if(!$condition)throw new Exception($name); echo "PASS $name\n";}
$flow=['nodes'=>[
    ['id'=>'0','name'=>'neteco.pvms.devTypeLangKey.string','display'=>'CONNECTED','description'=>['value'=>'1.138 kW']],
    ['id'=>'5','name'=>'neteco.pvms.KPI.kpiView.electricalLoad','display'=>'CONNECTED','description'=>['value'=>'1.392 kW']],
    ['id'=>'3','name'=>'neteco.pvms.partials.main.dm.detailInfo.curInfo.grid']],
    'links'=>[['fromNode'=>'3','toNode'=>'2','visible'=>true,'flowing'=>'FORWARD','description'=>['value'=>'0.254 kW']]]];
$http=200;$keepCode=0;$calls=0;$loaded=[];
$m=new HuaweiFusionSolar();$m->Create();
check($m->properties['AccessMode']==='api','existing installations keep API mode');
$m->properties['AccessMode']='web';$m->properties['WebStation']='NE=151380676';$m->ApplyChanges();
check($m->status===201 && $m->timers['UpdateTimer']===0,'missing session stops polling');
$import=json_encode(['cookie'=>'JSESSIONID=initial','token'=>'initial-secret']);
$m->RequestAction('ImportWebSession',$import);
check($m->values['GridImport']===254.0 && $m->values['GridExport']===0.0 && $m->values['WebDataValid'],'import and real-shaped import flow');
check($m->timers['UpdateTimer']===300000 && strpos($m->attributes['WebSession'],'renewed-secret')!==false,'timer and renewed session persisted');
$m->Update();check(strpos(end($loaded),'rotated')!==false,'rotated cookies loaded on next cycle');
$flow['links'][0]['flowing']='BACKWARD';$m->Update();
check($m->values['GridImport']===0.0 && $m->values['GridExport']===254.0,'export direction reverses values');
$flow['links'][0]['description']['value']='0 W';$flow['links'][0]['flowing']='NONE';$m->Update();
check($m->values['GridImport']===0.0 && $m->values['GridExport']===0.0,'explicit zero flow');
$old=$m->values['TotalPV'];unset($flow['nodes'][0]['description']);$m->Update();
check(!$m->values['WebDataValid'] && $m->values['TotalPV']===$old && $m->timers['UpdateTimer']===0,'missing data preserves values and stops');
$m->ApplyChanges();check($m->timers['UpdateTimer']===0,'paused state survives ApplyChanges');
$flow['nodes'][0]['description']=['value'=>'1.138 kW'];$m->Update();check($m->values['WebDataValid'],'manual retry recovers');
$http=302;$m->Update();check($m->attributes['WebSession']==='' && $m->status===201 && $m->timers['UpdateTimer']===0,'redirect clears session and stops');
$http=200;$m->RequestAction('ImportWebSession',$import);$m->properties['WebStation']='NE=42';$m->ApplyChanges();
check($m->attributes['WebSession']==='' && $m->timers['UpdateTimer']===0,'station change invalidates session');
$m->properties['WebStation']='NE=151380676';$keepCode=1;$m->RequestAction('ImportWebSession',$import);
check($m->status===201 && $m->attributes['WebSession']==='','keep-alive rejection stops');
$keepCode=0;$m->RequestAction('ImportWebSession',$import);$m->RequestAction('ForgetWebSession',0);
check($m->attributes['WebSession']==='' && $m->timers['UpdateTimer']===0,'manual removal');
$m->properties['WebOrigin']='https://attacker.example';$before=$calls;$m->ApplyChanges();
check($calls===$before && $m->status===202,'invalid origin blocked');
check(strpos(implode(' ',array_filter($m->values,'is_string')),'secret')===false,'status contains no tokens');

// Signal fixture transcribed from the user's actual device-realtime-data response.
$sample = [
 [10025,'512','','Netzanschluss'],[10032,'0.01','kWh'],[10029,'27559.25','kWh'],
 [10018,'0.078','kW'],[10019,'0.000','kvar'],[10006,'10.000','kW'],
 [10020,'0.999',''],[10021,'49.99','Hz'],[21029,'0','','Dreiphasen-Vierleitersystem'],
 [10014,'0.215','A'],[10015,'0.189','A'],[10016,'0.231','A'],
 [10011,'228.2','V'],[10012,'226.6','V'],[10013,'225.7','V'],
 [10027,'2026-11-03 07:23:06',''],[10028,'2026-11-02 16:36:20',''],
 [10023,'11.7','℃'],[10024,'3.000','MΩ'],
 [230223000,'P=100.0% (Wirkleistungsminderung in Prozent)',''],
 [230223001,'PF=1.000(Leistungsfaktor)',''],[230223007,'0','','NA']
];
$signals=[];
foreach ($sample as $row) {
    $signals[]=['id'=>$row[0],'realValue'=>$row[1],'unit'=>$row[2],'value'=>$row[3]??$row[1],
        'latestTime'=>$row[0]===10006?1793629210:1793687997];
}
$response=['success'=>true,'failCode'=>0,'data'=>[['status'=>1],['groupName'=>'Anderes','signals'=>$signals],['pv2mppt'=>false]]];
$deviceResponses=['NE=151380680'=>$response,'NE=188552817'=>$response];
$deviceResponses['NE=188552817']['data'][1]['signals'][3]['realValue']='0.125';
$deviceCalls=[];
$flow['nodes'][]=['name'=>'neteco.pvms.devTypeLangKey.inverter','devIds'=>['NE=151380680','NE=188552817','NE=151380680']];
$m->properties['WebOrigin']='https://uni004eu5.fusionsolar.huawei.com';
$m->properties['WebInverterDetails']=true;
$m->RequestAction('ImportWebSession',$import);
check($deviceCalls===['NE=151380680','NE=188552817'],'discover and deduplicate both inverter IDs');
check($m->values['WEBINV_151380680_ActivePower']===78.0 && $m->values['WEBINV_188552817_ActivePower']===125.0,'distinct devices and kW to W conversion');
check($m->values['WEBINV_151380680_TotalYield']===27559.25 && $m->values['WEBINV_151380680_CurrentL1']===0.215,'preserve kWh and amp units');
check($m->values['WEBINV_151380680_Status']==='Netzanschluss' && $m->values['WEBINV_151380680_Shutdown']==='2026-11-02 16:36:20','display status and date strings without guessed timezone');
check($m->values['WEBINV_151380680_RatedPower_Time']===1793629210 && $m->values['WEBINV_151380680_ActivePower_Time']===1793687997,'retain individual signal timestamps');
check($m->values['WebInvertersValid'] && $m->values['WEBINV_188552817_Valid'],'complete detail validity');
$deviceResponses['NE=151380680']['data'][1]['signals'][3]['unit']='MW';
$deviceResponses['NE=188552817']['data'][1]['signals'][4]['realValue']='-0.123';
$m->Update();
check(!$m->values['WEBINV_151380680_Valid'] && $m->values['WEBINV_151380680_ActivePower']===78.0 && $m->values['WEBINV_151380680_ActivePower_Time']===0,'unknown unit invalidates timestamp without zeroing previous power');
check($m->values['WEBINV_188552817_ReactivePower']===-0.123 && $m->values['WebDataValid'],'signed reactive power and unaffected station validity');
$deviceResponses['NE=151380680']=$response;
$deviceResponses['NE=188552817']=['success'=>false,'failCode'=>1];
$beforeTime=$m->values['LastWebSuccess'];$m->Update();
check(!$m->values['WebInvertersValid'] && !$m->values['WEBINV_151380680_Valid'] && $m->timers['UpdateTimer']===0 && $m->values['LastWebSuccess']===$beforeTime,'failed second inverter stops cycle and invalidates details');
$deviceResponses['NE=188552817']=$response;$m->Update();
$deviceCalls=[];$m->properties['WebInverterDetails']=false;$m->ApplyChanges();$m->Update();
check($deviceCalls===[] && !$m->values['WebInvertersValid'] && !$m->values['WEBINV_188552817_Valid'] && $m->values['WebDataValid'],'disabling details stops requests and invalidates old device values');
$folder1=IPS_GetObjectIDByIdent('WEBINV_151380680',7);
$folder2=IPS_GetObjectIDByIdent('WEBINV_188552817',7);
$powerID=IPS_GetObjectIDByIdent('WEBINV_151380680_ActivePower',$folder1);
check($folder1!==false && $folder2!==false && $folder1!==$folder2 && $powerID!==false,'separate folders contain device values');
check(IPS_GetObjectIDByIdent('WEBINV_151380680_ActivePower',7)===false,'no device variable duplicated at instance root');
// Simulate a pre-1.3 variable, including its existing ID/profile and archive marker.
IPS_SetParent($powerID,7);IPS_SetName($powerID,'WR 151380680 – Wirkleistung AC');
$objects[$powerID]['Profile']='User.Custom';$objects[$powerID]['ArchiveMarker']=[11,22];
$unrelated=IPS_CreateVariable(2);IPS_SetParent($unrelated,7);IPS_SetIdent($unrelated,'UserPower');
$m->ApplyChanges();
check(IPS_GetObjectIDByIdent('WEBINV_151380680_ActivePower',$folder1)===$powerID && $objects[$powerID]['Profile']==='User.Custom' && $objects[$powerID]['ArchiveMarker']===[11,22],'migration preserves object ID profile and history');
check(IPS_GetName($powerID)==='Wirkleistung AC' && $objects[$unrelated]['ParentID']===7,'short captions and unrelated variables untouched');
$count=count($objects);$m->ApplyChanges();check(count($objects)===$count,'repeated migration creates no duplicates');
$m->properties['WebInverterDetails']=true;$m->Update();
check(IPS_GetObjectIDByIdent('WEBINV_151380680_ActivePower',$folder1)===$powerID && $objects[$powerID]['Value']===78.0,'subsequent polls update migrated variables');

// SSO integration: synthetic key/tickets only, no external requests.
$keyResource = openssl_pkey_new(['private_key_bits'=>3072,'private_key_type'=>OPENSSL_KEYTYPE_RSA]);
$keyDetails = openssl_pkey_get_details($keyResource);
$publicReply = json_encode(['enableEncrypt'=>true,'pubKey'=>$keyDetails['key'],'version'=>'00000480','timeStamp'=>1794161276257]);
$portal = 'https://uni004eu5.fusionsolar.huawei.com/uniportal/pvmswebsite/assets/build/cloud.html?app-id=smartpvms';
$loginReply = ['errorCode'=>'470','respMultiRegionName'=>['-5','/rest/dp/web/v1/auth/on-sso-credential-ready?ticket=fake&regionName=region004','uni004eu5.fusionsolar.huawei.com&&fake-tgc'],'verifyCodeCreate'=>false,'twoFactorStatus'=>null];
$loginCookies = [".fusionsolar.huawei.com\tTRUE\t/\tTRUE\t0\tdp-session\tfake-session"];
function loginQueue($reply, $location) {
    return [['/unisso/login.action',200,'html'],['/unisso/pubkey',200,$GLOBALS['publicReply']],
        ['/unisso/v3/validateUser.action?',200,json_encode($reply)],
        ['/on-sso-credential-ready?',302,'',$location],['/cloud.html',200,'html']];
}
$m=new HuaweiFusionSolar();$m->Create();
$m->properties['AccessMode']='web';$m->properties['WebStation']='NE=151380676';
$m->properties['WebAutoLogin']=true;$m->properties['WebUsername']='test@example.invalid';$m->properties['WebPassword']='synthetic-only';
$http=200;$keepCode=0;$loginQueue=loginQueue($loginReply,$portal);
$m->RequestAction('Update',0);
check($m->values['WebDataValid'] && $loginQueue===[] && !$m->attributes['WebLoginBlocked'],'automatic initial login, ticket exchange and token bootstrap');
check(strpos($m->attributes['WebSession'],'fake-session')!==false && $m->timers['UpdateTimer']===300000,'automatic login cookies persisted and polling started');
$m->attributes['WebLoginAttempt']=0;
$loginQueue=array_merge([['keep-alive',302,'']],loginQueue($loginReply,$portal));
$m->Update();
check($m->values['WebDataValid'] && $loginQueue===[],'expired session reauthenticates within same polling cycle');
$before=$calls;$http=302;$m->Update();
check($calls===$before+1 && $m->timers['UpdateTimer']===0,'cooldown prevents repeated password submissions');
$http=200;$m->attributes['WebLoginAttempt']=0;
$bad=$loginReply;$bad['verifyCodeCreate']=true;
$loginQueue=array_slice(loginQueue($bad,$portal),0,3);
$m->RequestAction('Update',0);
check($m->status===201 && $m->attributes['WebLoginBlocked'] && $loginQueue===[],'CAPTCHA stops login before ticket exchange');
$m->ApplyChanges();check($m->timers['UpdateTimer']===0,'failed login remains stopped after ApplyChanges');
$m->attributes['WebLoginAttempt']=0;
$loginQueue=array_slice(loginQueue($loginReply,'https://attacker.invalid/steal'),0,4);
$m->RequestAction('Update',0);
check($m->status===201 && $loginQueue===[] && $m->attributes['WebSession']==='','foreign redirect rejected without navigation or session persistence');
$m->attributes['WebLoginAttempt']=0;
$bad=$loginReply;$bad['respMultiRegionName'][1]='https://attacker.invalid/ticket';
$loginQueue=array_slice(loginQueue($bad,$portal),0,3);$m->RequestAction('Update',0);
check($m->status===201 && $loginQueue===[],'foreign ticket endpoint rejected');
$m->attributes['WebLoginAttempt']=0;$loginQueue=loginQueue($loginReply,$portal);$m->RequestAction('Update',0);
$m->RequestAction('ForgetWebSession',0);$m->ApplyChanges();
check($m->timers['UpdateTimer']===0 && $m->attributes['WebStopped'],'manual stop persists even with automatic login enabled');
$m->attributes['WebLoginAttempt']=0;$loginQueue=loginQueue($loginReply,$portal);$keepCode=1;
$m->RequestAction('Update',0);
check($m->status===201 && $loginQueue===[] && $m->timers['UpdateTimer']===0,'failed token bootstrap does not trigger a second login');

$keepCode=0;$m->attributes['WebLoginAttempt']=0;
$bad=$loginReply;$bad['respMultiRegionName'][0]='-7';
$loginQueue=array_slice(loginQueue($bad,$portal),0,3);$m->RequestAction('Update',0);
check($m->status===201 && $loginQueue===[], 'regional two-factor challenge stops without requesting a code');
$m->attributes['WebLoginAttempt']=0;
$bad=$loginReply;$bad['errorCode']='401';
$loginQueue=array_slice(loginQueue($bad,$portal),0,3);$m->RequestAction('Update',0);
check($m->status===201 && $m->attributes['WebLoginBlocked'] && $loginQueue===[], 'rejected credentials stop after one attempt');
$m->attributes['WebLoginAttempt']=0;$loginCookies=[];
$loginQueue=array_slice(loginQueue($loginReply,$portal),0,4);$m->RequestAction('Update',0);
check($m->status===201 && $loginQueue===[], 'missing regional session cookie rejects login');
check(strpos($m->values['ConnectionStatus'], 'fake')===false && strpos($m->values['ConnectionStatus'],'synthetic-only')===false, 'login status contains no credentials or tickets');
