<?php
namespace FusionSolarTest;
use Exception;
// Isolierter Test ohne Netzwerk oder Symcon: php -n tests/web_session.php

$constants = ['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_SSL_VERIFYPEER','CURLOPT_SSL_VERIFYHOST','CURLOPT_ENCODING','CURLOPT_COOKIEFILE','CURLOPT_USERAGENT','CURLOPT_REFERER','CURLOPT_COOKIELIST','CURLOPT_URL','CURLOPT_HTTPHEADER','CURLINFO_HTTP_CODE','CURLINFO_COOKIELIST'];
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
function IPS_VariableProfileExists($name) {return true;}
function IPS_SemaphoreEnter($name, $timeout) {return true;}
function IPS_SemaphoreLeave($name) {}
function function_exists($name) {return $name === 'curl_init' || \function_exists($name);}
function curl_init() {return (object)['options'=>[]];}
function curl_setopt_array($ch,$options) {foreach($options as $k=>$v)curl_setopt($ch,$k,$v); return true;}
function curl_setopt($ch,$k,$v) {$ch->options[$k]=$v; if($k===CURLOPT_COOKIELIST)$GLOBALS['loaded'][]=$v; return true;}
function curl_getinfo($ch,$key) {
    if($key===CURLINFO_HTTP_CODE)return $GLOBALS['http'];
    return ["uni004eu5.fusionsolar.huawei.com\tFALSE\t/\tTRUE\t0\tJSESSIONID\trotated"];
}
function curl_close($ch) {}
function curl_exec($ch) {
    $GLOBALS['calls']++;
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
$module = file_get_contents(__DIR__ . '/../Huawei Fusion Solar/module.php');
$module = preg_replace('/require_once[^;]+;/', '', $module, 1);
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
