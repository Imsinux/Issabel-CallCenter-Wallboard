<?php
/* Queue Agent Summary – Single-Queue + Live + Wallboard + JSON API + XLSX + Workforce
 * Updated:
 * 1. Frequent Callers includes >= 3 calls (was > 3).
 * 2. Excel Export now includes a "Summary" sheet with all dashboard KPIs.
 */

date_default_timezone_set('Asia/Tehran');

/* Work window (HH:MM) for pause clipping */
$WORK_START = '07:15';
$WORK_END   = '16:15';

/* ---- Inputs ---- */
function normalize_start($val){
  $val = trim($val);
  if (preg_match('/\d{1,2}:\d{2}/', $val)) return $val;
  return $val.' 00:00:00';
}
function normalize_end($val){
  $val = trim($val);
  if (preg_match('/\d{1,2}:\d{2}/', $val)) return $val;
  return $val.' 23:59:59';
}

$start_in = isset($_GET['start']) ? $_GET['start'] : date('Y-m-d');
$end_in   = isset($_GET['end'])   ? $_GET['end']   : date('Y-m-d');

$start = normalize_start($start_in);
$end   = normalize_end($end_in);

$sla     = isset($_GET['sla'])     ? max(0, (int)$_GET['sla'])  : 20;
$refresh = isset($_GET['refresh']) ? max(3, (int)$_GET['refresh']) : 10;
$live    = isset($_GET['live']) && (int)$_GET['live']===1;
$mode    = isset($_GET['mode']) ? trim($_GET['mode']) : ''; 

/* Single-queue filter */
$selectedQueue = '';
if (isset($_GET['queue']) && trim($_GET['queue'])!=='') { $selectedQueue = trim($_GET['queue']); }

$from  = strtotime($start);
$to    = strtotime($end);

/* Helpers */
function mmss($secs){ if(!is_numeric($secs)||$secs<0)$secs=0; $m=floor($secs/60); $s=$secs%60; return sprintf('%d:%02d',$m,$s); }
function hhmmss($secs){ if(!is_numeric($secs)||$secs<0)$secs=0; $h=floor($secs/3600); $m=floor(($secs%3600)/60); $s=$secs%60; return sprintf('%02d:%02d:%02d',$h,$m,$s); }
function ext_from_agent($agent){ if (strpos($agent,'/')!==false){ $tmp=explode('/',$agent); return end($tmp);} return $agent; }
function clip_to_workdays($segStart, $segEnd, $workStart, $workEnd){
  $out=[]; if ($segEnd <= $segStart) return $out;
  $startDay=strtotime(date('Y-m-d 00:00:00',$segStart));
  $endDay  =strtotime(date('Y-m-d 00:00:00',$segEnd));
  for ($day=$startDay; $day<=$endDay; $day+=86400){
    $ws=strtotime(date('Y-m-d',$day).' '.$workStart);
    $we=strtotime(date('Y-m-d',$day).' '.$workEnd);
    $clipS=max($segStart,$ws); $clipE=min($segEnd,$we);
    if ($clipE>$clipS){ $d=date('Y-m-d',$day); $out[$d]=($out[$d]??0)+($clipE-$clipS); }
  }
  return $out;
}
function x_esc($s){ return htmlspecialchars($s, ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }

/* Helper to get ALL log files (Rotated + Current) */
function get_all_log_files() {
    $files = glob('/var/log/asterisk/queue_log*');
    if (!$files) return [];
    sort($files); 
    return $files;
}

/* * FUNCTION: Get Most Repetitive Number for SELECTED RANGE */
function get_top_caller_range($fromTs, $toTs, $queueFilter='') {
    $counts = [];
    $files = get_all_log_files();
    
    foreach ($files as $logPath) {
        $fh = fopen($logPath, 'r');
        if (!$fh) continue;
        while (($line = fgets($fh)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $p = explode('|', $line); if (count($p) < 5) continue;
            $t = (int)$p[0];
            if ($t < $fromTs || $t > $toTs) continue;
            
            if ($p[4] === 'ENTERQUEUE') {
                $q = $p[2];
                if ($queueFilter !== '' && $q !== $queueFilter) continue;
                $callerId = $p[6] ?? '';
                if ($callerId === '') continue;
                if (!isset($counts[$callerId])) $counts[$callerId] = 0;
                $counts[$callerId]++;
            }
        }
        fclose($fh);
    }

    if (empty($counts)) return ['N/A', 0];
    arsort($counts);
    return [key($counts), current($counts)];
}

/* * UPDATED FUNCTION: Get All Numbers >= 3 calls per Day */
function get_frequent_callers_per_day($fromTs, $toTs, $queueFilter='') {
    $dailyCounts = []; 
    $files = get_all_log_files();

    foreach ($files as $logPath) {
        $fh = fopen($logPath, 'r');
        if (!$fh) continue;
        
        while (($line = fgets($fh)) !== false) {
            $line = trim($line); if ($line === '') continue;
            $p = explode('|', $line); if (count($p) < 5) continue;
            $t = (int)$p[0];
            if ($t < $fromTs || $t > $toTs) continue;
            
            if ($p[4] === 'ENTERQUEUE') {
                $q = $p[2];
                if ($queueFilter !== '' && $q !== $queueFilter) continue;
                $callerId = $p[6] ?? '';
                if ($callerId === '') continue;

                $dateStr = date('Y-m-d', $t);
                if (!isset($dailyCounts[$dateStr][$callerId])) {
                    $dailyCounts[$dateStr][$callerId] = 0;
                }
                $dailyCounts[$dateStr][$callerId]++;
            }
        }
        fclose($fh);
    }

    // Filter for >= 3
    $result = [];
    foreach($dailyCounts as $date => $nums){
        foreach($nums as $num => $cnt){
            if($cnt >= 3){ // CHANGED FROM > 3 TO >= 3
                $result[] = ['date'=>$date, 'number'=>$num, 'count'=>$cnt];
            }
        }
    }

    // Sort by Date desc, then Count desc
    usort($result, function($a, $b){
        if($a['date'] !== $b['date']) return strcmp($b['date'], $a['date']);
        return $b['count'] <=> $a['count'];
    });

    return $result;
}

/* ---- Fast counts for arbitrary time windows ---- */
function compute_counts_for_window($fromTs, $toTs, $queueFilter=''){
  $files = get_all_log_files();
  $enteredSeen = []; $answeredSeen = [];
  
  foreach($files as $logPath) {
      $fh = fopen($logPath,'r'); if(!$fh) continue;
      while(($line=fgets($fh))!==false){
        $line=trim($line); if($line==='') continue;
        $p=explode('|',$line); if(count($p)<5) continue;
        $t=(int)$p[0]; if($t<$fromTs || $t>$toTs) continue;
        $callid=$p[1]; $queue=$p[2]; $event=$p[4];
        if($queueFilter!=='' && $queue!==$queueFilter) continue;
        if($event==='ENTERQUEUE'){ $enteredSeen[$callid]=1; }
        elseif($event==='CONNECT'){ $answeredSeen[$callid]=1; }
      }
      fclose($fh);
  }
  return [count($enteredSeen), count($answeredSeen)];
}

/* Collections */
$entered=[]; $abandoned=[]; $connects=[]; $completes=[];
$queuesSeen=[];
$abandonTimestamps=[];
$callState=[];       
$openConnectCountExt=[]; 
$pauseOpen=[]; $pauseSegments=[]; $pauseAccumAll=[];

/* Parse ALL queue_log files */
$allLogFiles = get_all_log_files();

foreach ($allLogFiles as $logPath) {
    if (!is_readable($logPath)) continue;
    $fh=fopen($logPath,'r');
    if (!$fh) continue;

    while(($line=fgets($fh))!==false){
      $line=trim($line); if($line==='') continue;
      $p=explode('|',$line); if(count($p)<5) continue;
      $t=(int)$p[0]; if($t<$from || $t>$to) continue;

      $callid=$p[1]; $queue=$p[2]; $agent=$p[3]; $event=$p[4];
      $d1=$p[5]??''; $d2=$p[6]??'';

      if($queue!=='') $queuesSeen[$queue]=1;

      if($event==='ENTERQUEUE'){
        $entered[$callid]=$queue;
        $callState[$callid]=['queue'=>$queue,'last'=>'ENTERQUEUE','ts'=>$t];
      } elseif($event==='RINGNOANSWER'){
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>'RINGNOANSWER','ts'=>$t];
      } elseif ($event==='ABANDON'){
        $abandoned[$callid] = $queue;
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>'ABANDON','ts'=>$t];
        $abandonTimestamps[$callid] = $t;
      } elseif ($event==='EXITWITHTIMEOUT' || $event==='EXITWITHKEY' || $event==='TRANSFER' || $event==='SYSCOMPAT'){
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>$event,'ts'=>$t];
      } elseif ($event==='CONNECT'){
        $ext=ext_from_agent($agent);
        $wait=is_numeric($d1)?(int)$d1:0;
        if(!isset($connects[$callid])) $connects[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'ext'=>$ext,'wait'=>$wait,'ts'=>$t];
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>'CONNECT','ts'=>$t,'ext'=>$ext];
        $openConnectCountExt[$ext] = ($openConnectCountExt[$ext] ?? 0) + 1;
      } elseif($event==='COMPLETEAGENT' || $event==='COMPLETECALLER'){
        $talk=0; if(is_numeric($d2)) $talk=(int)$d2; if($talk===0 && is_numeric($d1) && (int)$d1>0) $talk=(int)$d1;
        if(!isset($completes[$callid]) || $talk>$completes[$callid]['talk']) $completes[$callid]=['talk'=>$talk];
        if(isset($callState[$callid]['ext'])){ $ext = $callState[$callid]['ext']; if(isset($openConnectCountExt[$ext])) $openConnectCountExt[$ext] = max(0, $openConnectCountExt[$ext]-1); }
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>'COMPLETE','ts'=>$t];
      } elseif($event==='PAUSE'){
        $ext=ext_from_agent($agent);
        $pauseOpen[$ext]=$t; if(!isset($pauseAccumAll[$ext])) $pauseAccumAll[$ext]=0;
      } elseif($event==='UNPAUSE'){
        $ext=ext_from_agent($agent);
        if(isset($pauseOpen[$ext])){
          $ps=$pauseOpen[$ext]; unset($pauseOpen[$ext]);
          $segStart=max($ps,$from); $segEnd=min($t,$to);
          if($segEnd>$segStart){
            $pauseAccumAll[$ext]+=($segEnd-$segStart);
            $pauseSegments[$ext][] = [$segStart,$segEnd];
          }
        }
      }
    }
    fclose($fh);
}

/* Close open pause segments */
foreach($pauseOpen as $ext=>$ps){
  $segStart=max($ps,$from); $segEnd=$to;
  if($segEnd>$segStart){
    $pauseAccumAll[$ext]+=($segEnd-$segStart);
    $pauseSegments[$ext][]=[$segStart,$segEnd];
  }
}

/* Aggregations */
$answeredByExt=[]; $waitAggByExt=[]; $talkAggByExt=[];
$answeredByQueue=[]; $waitAggByQueue=[]; $talkAggByQueue=[];
$answeredUnique=[]; $slaHits=0;

foreach($connects as $cid=>$c){
  $ext=$c['ext']; $q=$c['queue']; $w=$c['wait'];
  if ($selectedQueue !== '' && $q !== $selectedQueue) continue;

  $answeredUnique[$cid]=1; if($w<=$sla) $slaHits++;
  $answeredByExt[$ext]=($answeredByExt[$ext]??0)+1;
  $waitAggByExt[$ext]=($waitAggByExt[$ext]??0)+$w;
  $answeredByQueue[$q]=($answeredByQueue[$q]??0)+1;
  $waitAggByQueue[$q]=($waitAggByQueue[$q]??0)+$w;
  $talk = $completes[$cid]['talk']??0;
  $talkAggByExt[$ext]=($talkAggByExt[$ext]??0)+$talk;
  $talkAggByQueue[$q]=($talkAggByQueue[$q]??0)+$talk;
}

/* KPIs */
$enteredCount=0; 
foreach($entered as $cid=>$q){ 
  if ($selectedQueue === '' || $q === $selectedQueue) $enteredCount++;
}
$answeredCount=count($answeredUnique);
$offered=$enteredCount; 
$answerRate=($offered>0)?round(100*$answeredCount/$offered,2):0.0;
$slaOnOffered=($offered>0)?round(100*$slaHits/$offered,2):0.0;
$slaOnAnswered=($answeredCount>0)?round(100*$slaHits/$answeredCount,2):0.0;
$sumWaitAll=array_sum($waitAggByQueue);
$sumTalkAll=array_sum($talkAggByQueue);
$avgWaitSec=($answeredCount>0)?(int)round($sumWaitAll/$answeredCount):0;
$avgTalkSec=($answeredCount>0)?(int)round($sumTalkAll/$answeredCount):0;
$noAnswered = max(0, $offered - $answeredCount); 

/* Peak Abandon Hour */
$offeredCids = [];
foreach($entered as $cid=>$q){
  if ($selectedQueue === '' || $q === $selectedQueue) $offeredCids[$cid] = 1;
}
$answeredCidsInScope = [];
foreach($connects as $cid=>$c){
  $q = $c['queue'];
  if ($selectedQueue === '' || $q === $selectedQueue) $answeredCidsInScope[$cid] = 1;
}
$abandonedCids = array_diff_key($offeredCids, $answeredCidsInScope);
$abandonByHour = [];
foreach ($abandonedCids as $cid => $_) {
  if (isset($abandonTimestamps[$cid])) {
    $t = $abandonTimestamps[$cid];
    $hourKey = date('Y-m-d H:00', $t); 
    $abandonByHour[$hourKey] = ($abandonByHour[$hourKey] ?? 0) + 1;
  }
}
$peakHour = ''; $peakCount = 0;
if (!empty($abandonByHour)) {
  arsort($abandonByHour); 
  $peakHour = key($abandonByHour); 
  $peakCount = current($abandonByHour); 
}
// Get most repetitive number for dashboard summary
list($repNum, $repCnt) = get_top_caller_range($from, $to, $selectedQueue);

/* Extensions */
$currentExts=[];
try{
  if(file_exists('/etc/freepbx.conf')){
    include_once '/etc/freepbx.conf';
    $dbhost=$amp_conf['AMPDBHOST'] ?? '127.0.0.1';
    $dbname=$amp_conf['AMPDBNAME'] ?? 'asterisk';
    $dbuser=$amp_conf['AMPDBUSER'] ?? 'root';
    $dbpass=$amp_conf['AMPDBPASS'] ?? '';
    $pdo=new PDO("mysql:host=$dbhost;dbname=$dbname;charset=utf8mb4",$dbuser,$dbpass,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    foreach($pdo->query("SELECT id FROM devices WHERE tech IN ('sip','pjsip','iax2')") as $r){ $currentExts[(string)$r['id']]=1; }
  }
}catch(Throwable $e){}
if(empty($currentExts)){
  foreach(array_keys($answeredByExt) as $e) $currentExts[$e]=1;
  if ($selectedQueue === '') { foreach(array_keys($pauseAccumAll) as $e) $currentExts[$e]=1; }
}

/* Pause per day */
$pauseByDayExt=[];
foreach($pauseSegments as $ext=>$segs){
  if(!isset($currentExts[$ext])) continue;
  foreach($segs as [$ps,$pe]){
    $clipped=clip_to_workdays($ps,$pe,$WORK_START,$WORK_END);
    foreach($clipped as $d=>$secs){
      $pauseByDayExt[$d][$ext]=($pauseByDayExt[$d][$ext]??0)+$secs;
    }
  }
}

/* Live queue waiting */
$waitingByQueue = [];
foreach($callState as $cid=>$st){
  $q = $st['queue'] ?? '';
  if($q==='') continue;
  if ($selectedQueue !== '' && $q !== $selectedQueue) continue;
  if($st['last']==='ENTERQUEUE' || $st['last']==='RINGNOANSWER'){
    $waitingByQueue[$q] = ($waitingByQueue[$q] ?? 0) + 1;
  }
}
$queueWaitingSelected = ($selectedQueue!=='') ? (int)($waitingByQueue[$selectedQueue] ?? 0) : array_sum($waitingByQueue);

/* Agent status */
$agentOnCall = [];
foreach($currentExts as $ext=>$_){
  $agentOnCall[$ext] = ((int)($openConnectCountExt[$ext] ?? 0) > 0) ? 1 : 0;
}

/* Days */
$days=[]; 
$startDayTs = strtotime(date('Y-m-d 00:00:00', $from));
$endDayTs   = strtotime(date('Y-m-d 00:00:00', $to));
for($d=$startDayTs; $d<=$endDayTs; $d+=86400){ $days[]=date('Y-m-d',$d); }

/* ===== XLSX export ===== */
if (isset($_GET['export']) && $_GET['export']==='xlsx') {
  if (!class_exists('ZipArchive')) { http_response_code(500); echo "<h3>PHP ZipArchive missing.</h3>"; exit; }
  
  // -- SHEET 1: DASHBOARD SUMMARY --
  $summaryRows = [];
  $summaryRows[] = ['Dashboard Summary', ''];
  $summaryRows[] = ['Date Range', "$start_in to $end_in"];
  $summaryRows[] = ['Queue', $selectedQueue !== '' ? $selectedQueue : 'ALL'];
  $summaryRows[] = ['', '']; // spacer
  $summaryRows[] = ['METRIC', 'VALUE'];
  $summaryRows[] = ['Total Calls (Offered)', $offered];
  $summaryRows[] = ['Answered Calls', $answeredCount];
  $summaryRows[] = ['Customer-Ended (Abandon)', $noAnswered];
  $summaryRows[] = ['Answer Rate', $answerRate.'%'];
  $summaryRows[] = ['SLA (on Offered)', $slaOnOffered.'%'];
  $summaryRows[] = ['SLA (on Answered)', $slaOnAnswered.'%'];
  $summaryRows[] = ['Avg Wait Time', mmss($avgWaitSec)];
  $summaryRows[] = ['Avg Talk Time', mmss($avgTalkSec)];
  $summaryRows[] = ['', '']; // spacer
  $summaryRows[] = ['Peak Abandon Hour', "$peakHour ($peakCount calls)"];
  $summaryRows[] = ['Top Repetitive Number', "$repNum ($repCnt calls)"];

  // -- SHEET 2: AGENTS --
  $agentsRows = [];
  $agentsRows[] = ['Agent/Ext','Answered','Avg Wait (MM:SS)','Avg Talk (MM:SS)'];
  $allExts = array_keys($currentExts); sort($allExts, SORT_STRING | SORT_FLAG_CASE);
  foreach ($allExts as $ext){
    $cntAns = (int)($answeredByExt[$ext] ?? 0);
    $awSec  = $cntAns>0 ? (int)round(($waitAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $atSec  = $cntAns>0 ? (int)round(($talkAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $agentsRows[] = [$ext, (string)$cntAns, mmss($awSec), mmss($atSec)];
  }

  // -- SHEET 3: WORKFORCE --
  $workRows = [];
  $workRows[] = ['Date','Agent/Ext','Pause (HH:MM:SS)'];
  foreach ($days as $d){
    foreach ($currentExts as $ext=>$_){
      $pauseDay = (int)($pauseByDayExt[$d][$ext] ?? 0);
      $workRows[] = [$d, $ext, hhmmss($pauseDay)];
    }
  }

  // -- SHEET 4: FREQUENT CALLERS (>= 3) --
  $frequentRows = [];
  $frequentRows[] = ['Date', 'CallerID', 'Count (>=3)'];
  $frequentList = get_frequent_callers_per_day($from, $to, $selectedQueue);
  foreach ($frequentList as $row) {
      $frequentRows[] = [$row['date'], $row['number'], (string)$row['count']];
  }

  // XML Generator
  $buildSheetXML = function(array $rows){
    $cols = 0; foreach($rows as $r){ $cols = max($cols, count($r)); }
    $rnum = count($rows);
    $colName = function($i){
      $s=''; $i++; while($i>0){ $m=($i-1)%26; $s=chr(65+$m).$s; $i=intval(($i-$m-1)/26);} return $s;
    };
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    for ($r=0; $r<$rnum; $r++){
      $xml .= '<row r="'.($r+1).'">';
      for ($c=0; $c<$cols; $c++){
        $v = isset($rows[$r][$c]) ? (string)$rows[$r][$c] : '';
        $ref = $colName($c).($r+1);
        $xml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.x_esc($v).'</t></is></c>';
      }
      $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
  };
  
  $sheet1 = $buildSheetXML($summaryRows); // New Summary
  $sheet2 = $buildSheetXML($agentsRows);
  $sheet3 = $buildSheetXML($workRows);
  $sheet4 = $buildSheetXML($frequentRows);

  $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
  $zip = new ZipArchive();
  $zip->open($tmp, ZipArchive::OVERWRITE);
  
  // [Content_Types]
  $zip->addFromString('[Content_Types].xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
      '<Default Extension="xml" ContentType="application/xml"/>'.
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
      '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
      '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
      '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
      '<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'.
    '</Types>'
  );

  // _rels
  $zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
    '</Relationships>'
  );

  // workbook.xml
  $zip->addFromString('xl/workbook.xml',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
      '<sheets>'.
        '<sheet name="Summary" sheetId="1" r:id="rId1"/>'.
        '<sheet name="Agents" sheetId="2" r:id="rId2"/>'.
        '<sheet name="Workforce" sheetId="3" r:id="rId3"/>'.
        '<sheet name="Frequent Calls" sheetId="4" r:id="rId4"/>'.
      '</sheets>'.
    '</workbook>'
  );

  // workbook.xml.rels
  $zip->addFromString('xl/_rels/workbook.xml.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<Relationships xmlns="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'.
      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'.
      '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'.
      '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>'.
    '</Relationships>'
  );

  $zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
  $zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
  $zip->addFromString('xl/worksheets/sheet3.xml', $sheet3);
  $zip->addFromString('xl/worksheets/sheet4.xml', $sheet4);
  $zip->close();

  $fname = 'queue_report_'.date('Ymd_His').'.xlsx';
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$fname.'"');
  header('Content-Length: '.filesize($tmp));
  readfile($tmp);
  unlink($tmp);
  exit;
}
/* ===== end XLSX export ===== */

/* ===== JSON API ===== */
if (isset($_GET['api']) && (int)$_GET['api']===1) {
  header('Content-Type: application/json; charset=utf-8');
  // $repNum and $repCnt calculated above
  
  $rangesPayload = null;
  if (isset($_GET['ranges']) && (int)$_GET['ranges']===1){
    $now = time();
    $win = [ 'last_1h'=>[$now-3600,$now], 'last_3h'=>[$now-10800,$now], 'last_24h'=>[$now-86400,$now], 'last_7d'=>[$now-604800,$now]];
    $qFilter = ($selectedQueue!=='') ? $selectedQueue : '';
    $out = [];
    foreach($win as $key => [$ws,$we]){
      list($off,$ans) = compute_counts_for_window($ws, $we, $qFilter);
      $out[$key] = ['total_calls'=>$off, 'answered_calls'=>$ans, 'no_answered'=>max(0,$off-$ans), 'start'=>date('Y-m-d H:i',$ws), 'end'=>date('Y-m-d H:i',$we)];
    }
    $rangesPayload = $out;
  }
  $agents = [];
  $allExts = array_keys($currentExts); sort($allExts, SORT_STRING | SORT_FLAG_CASE);
  foreach ($allExts as $ext){
    $cntAns = (int)($answeredByExt[$ext] ?? 0);
    $awSec  = $cntAns>0 ? (int)round(($waitAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $atSec  = $cntAns>0 ? (int)round(($talkAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $agents[] = ['ext'=>$ext,'answered'=>$cntAns, 'avg_wait_sec'=>$awSec,'avg_talk_sec'=>$atSec, 'avg_wait'=>mmss($awSec),'avg_talk'=>mmss($atSec)];
  }
  $workforce = [];
  foreach ($days as $d){
    foreach ($currentExts as $ext=>$_){
      $pauseDay = (int)($pauseByDayExt[$d][$ext] ?? 0);
      $workforce[] = ['date'=>$d,'ext'=>$ext,'pause_sec'=>$pauseDay,'pause'=>hhmmss($pauseDay)];
    }
  }
  
  // Frequent Callers for API
  $frequentCallers = get_frequent_callers_per_day($from, $to, $selectedQueue);

  $queues = array_keys($queuesSeen); sort($queues);
  $payload = [
    'meta'=>['start'=>$start,'end'=>$end,'sla'=>$sla,'queues'=> $queues,'selected_queue'=> $selectedQueue,'work_window'=> ['start'=>$WORK_START,'end'=>$WORK_END],'generated_at'=>date('Y-m-d H:i:s')],
    'kpi'=>['total_calls'=>$offered,'answered_calls'=>$answeredCount,'no_answered'=>$noAnswered,'answer_rate'=>$answerRate,'sla_offered'=>$slaOnOffered,'sla_answered'=>$slaOnAnswered,'avg_wait_mmss'=>mmss($avgWaitSec),'avg_talk_mmss'=>mmss($avgTalkSec),'peak_abandon_hour' => $peakHour,'peak_abandon_count' => $peakCount,'repetitive_num' => $repNum,'repetitive_count' => $repCnt],
    'live'=>['queue_waiting'=>$queueWaitingSelected],
    'agent_status'=>$agentOnCall,'agents'=>$agents,'workforce'=>$workforce,
    'frequent_callers' => $frequentCallers
  ];
  if ($rangesPayload !== null) $payload['ranges'] = $rangesPayload;
  echo json_encode($payload);
  exit;
}
/* ===== end JSON API ===== */
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo ($mode==='wallboard'?'Wallboard – ':'');?>گزارش صف (Queue Agent Summary)</title>
<style>
:root{
  --bg:#0b1220; --fg:#e6ecf3; --muted:#9fb0c3; --card:#0f172a; --border:#1e293b;
  --accent:#60a5fa; --accent2:#34d399; --warn:#f59e0b; --bad:#ef4444; --ok:#22c55e;
  --rep:#d946ef; --rep-bg:rgba(217,70,239,.15);
  --shadow:0 10px 30px rgba(0,0,0,.35)
}
@media (prefers-color-scheme: light){
  :root{
    --bg:#f8fafc; --fg:#0b1220; --muted:#475569; --card:#ffffff; --border:#e2e8f0;
    --accent:#2563eb; --accent2:#059669; --warn:#d97706; --bad:#dc2626; --ok:#16a34a;
    --rep:#c026d3; --rep-bg:rgba(192,38,211,.12);
    --shadow:0 8px 24px rgba(0,0,0,.08)
  }
}
*{box-sizing:border-box} body{margin:24px;background:var(--bg);color:var(--fg);font:15px/1.6 system-ui,Segoe UI,Roboto,Arial}
.container{max-width:<?php echo ($mode==='wallboard'?'1800px':'1300px');?>;margin:0 auto}
h2{margin:0 0 16px;font-weight:800;letter-spacing:.2px;font-size:<?php echo ($mode==='wallboard'?'34px':'22px');?>}
.card{background:linear-gradient(145deg, rgba(255,255,255,0.04), rgba(255,255,255,0.02));border:1px solid var(--border);border-radius:18px;padding:16px;box-shadow:var(--shadow)}
.row{display:flex;gap:16px;flex-wrap:wrap} .col{flex:1;min-width:220px}
.kpi{display:flex;flex-direction:column;gap:8px;align-items:flex-start}
.kpi .label{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.kpi .value{font-weight:800;font-size:<?php echo ($mode==='wallboard'?'38px':'22px');?>}
.kpi.ok .value{color:var(--ok)} .kpi.warn .value{color:var(--warn)} .kpi.bad .value{color:var(--bad)}
form.filter{display:flex;gap:12px;flex-wrap:wrap;align-items:end;margin-bottom:14px}
input,select,button,a.btn{border:1px solid var(--border);background:var(--card);color:var(--fg);padding:10px 12px;border-radius:12px}
button,a.btn{background:linear-gradient(135deg, var(--accent), var(--accent2));color:#fff;border-color:transparent;cursor:pointer}
a.btn.ghost{background:transparent;border-color:var(--accent);color:var(--accent)}
.table-wrap{overflow:auto;border:1px solid var(--border);border-radius:14px; max-height: 400px;}
table{border-collapse:collapse;min-width:820px;width:100%} th,td{padding:<?php echo ($mode==='wallboard'?'14px 16px':'10px 12px');?>;border-bottom:1px solid var(--border)}
thead th{position:sticky;top:0;background:var(--card);z-index:1;font-weight:800}
tbody tr:nth-child(even){background:rgba(255,255,255,.02)} tbody tr:hover{background:rgba(96,165,250,.10)}
th.sortable{cursor:pointer;white-space:nowrap}
.badge{padding:4px 10px;border-radius:999px;background:rgba(96,165,250,.15);color:var(--accent);font-size:12px}
.footer-note{margin-top:10px;color:var(--muted);font-size:12px}
.topbar{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-bottom:10px}
.fs-btn{padding:10px 12px;border-radius:12px;border:1px solid var(--border);background:transparent;color:var(--fg);cursor:pointer}
.pill{display:inline-block;padding:6px 10px;border-radius:999px;background:rgba(52,211,153,.18);color:#34d399;font-size:12px}
tr.agent-active td{background:rgba(34,197,94,.12);}
tr.agent-active td:first-child{color:#22c55e;font-weight:700}
tr.agent-inactive td{background:rgba(239,68,68,.10);}
tr.agent-inactive td:first-child{color:#ef4444;font-weight:700}
.live-metric{display:flex;gap:10px;align-items:center}
.live-dot{width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 6px rgba(34,197,94,.15)}
.live-box{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:var(--card)}
.live-value{font-weight:800;font-size:<?php echo ($mode==='wallboard'?'36px':'20px');?>}
.live-label{font-size:12px;color:var(--muted)}
.rep-box { border:1px solid var(--rep); background:var(--rep-bg); margin-inline-start: 12px; display:flex; align-items:center; gap:10px; padding:10px 14px; border-radius:12px; }
.rep-val { color: var(--rep); font-weight:800; font-size:<?php echo ($mode==='wallboard'?'28px':'18px');?>; }
</style>
</head>
<body>
<div class="container">
  <div class="topbar">
    <h2><?php echo ($mode==='wallboard'?'والبورد – ':'');?>گزارش صف (Queue Agent Summary)</h2>
    <div style="display:flex;gap:8px;align-items:center">
      <button class="fs-btn" id="btnFS">تمام‌صفحه</button>
      <?php if($live){ ?><span class="pill">LIVE: هر <?php echo (int)$refresh; ?> ثانیه</span><?php } ?>
    </div>
  </div>
  <form class="filter card" method="get" id="filterForm" <?php echo ($mode==='wallboard'?'style="display:none"':''); ?>>
    <div><div class="label">از تاریخ</div><input type="date" name="start" value="<?php echo htmlspecialchars(substr($start,0,10)); ?>"></div>
    <div><div class="label">تا تاریخ</div><input type="date" name="end" value="<?php echo htmlspecialchars(substr($end,0,10)); ?>"></div>
    <div>
      <div class="label">صف (تکی)</div>
      <select name="queue" style="min-width:180px">
        <option value="">همهٔ صف‌ها</option>
        <?php ksort($queuesSeen); foreach($queuesSeen as $qq=>$_){
          $sel = ($selectedQueue === $qq) ? 'selected' : '';
          echo "<option value=\"".htmlspecialchars($qq)."\" $sel>".htmlspecialchars($qq)."</option>";
        } ?>
      </select>
    </div>
    <div><div class="label">SLA (ثانیه)</div><input type="number" min="0" name="sla" value="<?php echo (int)$sla; ?>"></div>
    <div><div class="label">Live Refresh (ثانیه)</div><input type="number" min="3" name="refresh" value="<?php echo (int)$refresh; ?>"></div>
    <div style="display:flex;gap:8px">
      <label style="display:flex;gap:6px;align-items:center"><input type="checkbox" name="live" value="1" <?php echo $live?'checked':''; ?>> لایو</label>
      <button type="submit">اعمال فیلتر</button>
      <a class="btn ghost" href="?start=<?php echo substr($start,0,10); ?>&end=<?php echo substr($end,0,10); ?>&sla=<?php echo (int)$sla; ?>">ریست صف</a>
      <a class="btn" href="?start=<?php echo substr($start,0,10); ?>&end=<?php echo substr($end,0,10); ?>&sla=<?php echo (int)$sla; ?><?php if ($selectedQueue!=='') { echo '&queue='.urlencode($selectedQueue); } ?>&export=xlsx">دانلود Excel (XLSX)</a>
    </div>
    <div style="margin-inline-start:auto;display:flex;gap:8px;flex-wrap:wrap">
      <span class="badge"><?php echo ($selectedQueue==='')?'Queue: ALL':'Queue: '.htmlspecialchars($selectedQueue); ?></span>
      <span class="badge">Work Window: <?php echo $WORK_START.'–'.$WORK_END; ?></span>
    </div>
  </form>
  <div class="card" style="margin-bottom:12px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px">
    <div class="live-metric">
      <span class="live-dot"></span>
      <div class="live-box">
        <div class="live-value" id="live_waiting"><?php echo (int)$queueWaitingSelected; ?></div>
        <div class="live-label">در انتظار در صف <?php echo ($selectedQueue===''?'(همهٔ صف‌ها)':x_esc($selectedQueue)); ?></div>
      </div>
      <div class="rep-box">
          <div style="text-align:center">
             <div class="rep-val" id="val_rep_num">N/A</div>
             <div class="live-label" id="val_rep_cnt">Count: 0</div>
          </div>
          <div class="live-label" style="font-weight:bold; color:var(--rep)">پرتکرارترین (بازه انتخابی)</div>
      </div>
    </div>
    <div class="live-label">به‌روزرسانی در حالت LIVE هر <?php echo (int)$refresh; ?> ثانیه</div>
  </div>
  <div class="row" id="kpiRow">
    <div class="col card kpi"><span class="label">Total Calls</span><span class="value" id="kpi_total"><?php echo number_format($offered); ?></span></div>
    <div class="col card kpi ok"><span class="label">Answered Calls</span><span class="value" id="kpi_answered"><?php echo number_format($answeredCount); ?></span></div>
    <div class="col card kpi bad"><span class="label">Abandon</span><span class="value" id="kpi_noans"><?php echo number_format($noAnswered); ?></span></div>
    <div class="col card kpi <?php echo ($answerRate>=85?'ok':($answerRate>=70?'warn':'bad')); ?>">
      <span class="label">Answer Rate</span><span class="value" id="kpi_ar"><?php echo $answerRate; ?>%</span>
    </div>
    <div class="col card kpi"><span class="label">SLA روی Offered</span><span class="value" id="kpi_sla1"><?php echo $slaOnOffered; ?>%</span></div>
    <div class="col card kpi"><span class="label">SLA روی Answered</span><span class="value" id="kpi_sla2"><?php echo $slaOnAnswered; ?>%</span></div>
    <div class="col card kpi"><span class="label">Avg Wait</span><span class="value" id="kpi_aw"><?php echo mmss($avgWaitSec); ?></span></div>
    <div class="col card kpi"><span class="label">Avg Talk</span><span class="value" id="kpi_at"><?php echo mmss($avgTalkSec); ?></span></div>
  </div>
  <?php if ($mode==='wallboard'): ?>
    <h3 style="margin:18px 0 8px;font-size:20px">Top Agents (Answered)</h3>
    <div class="table-wrap card">
      <table id="tblTopAgents">
        <thead><tr><th>Agent/Ext</th><th>Answered</th><th>Avg Wait</th><th>Avg Talk</th></tr></thead>
        <tbody id="topAgentsBody">
          <?php
            $rows=[];
            foreach ($currentExts as $ext=>$_){
              $ans=(int)($answeredByExt[$ext]??0);
              $aw=$ans>0?(int)round(($waitAggByExt[$ext]??0)/$ans):0;
              $at=$ans>0?(int)round(($talkAggByExt[$ext]??0)/$ans):0;
              $rows[]=['ext'=>$ext,'ans'=>$ans,'aw'=>$aw,'at'=>$at];
            }
            usort($rows,function($a,$b){ return $b['ans']<=>$a['ans'];});
            $rows=array_slice($rows,0,5);
            foreach($rows as $r){ echo "<tr><td>".x_esc($r['ext'])."</td><td>".number_format($r['ans'])."</td><td>".mmss($r['aw'])."</td><td>".mmss($r['at'])."</td></tr>"; }
          ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <h3 style="margin:18px 0 8px">خروجی به‌ازای هر Agent/داخلی</h3>
    <div class="table-wrap card">
      <table id="tblAgents">
        <thead>
          <tr><th class="sortable" data-type="text">Agent/Ext</th><th class="sortable" data-type="number">Answered</th><th class="sortable" data-type="time">Avg Wait</th><th class="sortable" data-type="time">Avg Talk</th></tr>
        </thead>
        <tbody id="agentsBody">
          <?php $allExts = array_keys($currentExts); sort($allExts, SORT_STRING | SORT_FLAG_CASE); foreach ($allExts as $ext):
            $cntAns = (int)($answeredByExt[$ext] ?? 0); $awSec = $cntAns>0 ? (int)round(($waitAggByExt[$ext] ?? 0)/$cntAns) : 0; $atSec = $cntAns>0 ? (int)round(($talkAggByExt[$ext] ?? 0)/$cntAns) : 0;
            $rowClass = ((int)($openConnectCountExt[$ext] ?? 0) > 0) ? 'agent-active' : 'agent-inactive';
          ?>
          <tr class="<?php echo $rowClass; ?>"><td><?php echo htmlspecialchars($ext); ?></td><td><?php echo number_format($cntAns); ?></td><td data-sec="<?php echo $awSec; ?>"><?php echo mmss($awSec); ?></td><td data-sec="<?php echo $atSec; ?>"><?php echo mmss($atSec); ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    
    <h3 style="margin:18px 0 8px">شماره‌های پرتکرار (۳ تماس یا بیشتر)</h3>
    <div class="table-wrap card">
      <table id="tblFreq">
        <thead><tr><th class="sortable" data-type="text">تاریخ (Date)</th><th class="sortable" data-type="text">شماره تماس (CallerID)</th><th class="sortable" data-type="number">تعداد تماس</th></tr></thead>
        <tbody id="freqBody">
           <tr><td colspan="3" style="text-align:center;color:var(--muted)">Loading...</td></tr>
        </tbody>
      </table>
    </div>

    <h3 style="margin:18px 0 8px">Daily Workforce</h3>
    <div class="table-wrap card">
      <table id="tblDaily">
        <thead><tr><th class="sortable" data-type="text">Date</th><th class="sortable" data-type="text">Agent/Ext</th><th class="sortable" data-type="duration">Pause</th></tr></thead>
        <tbody id="wfBody">
          <?php foreach ($days as $d){ foreach ($currentExts as $ext=>$_){ $pauseDay = $pauseByDayExt[$d][$ext] ?? 0; echo "<tr><td>".htmlspecialchars($d)."</td><td>".htmlspecialchars($ext)."</td><td data-dur=\"$pauseDay\">".hhmmss($pauseDay)."</td></tr>"; } } ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
  <div class="footer-note">هایلایت رنگی Agents بر اساس وضعیت «روی خط بودن» در همین بازه و لحظه‌ی فعلی است (CONNECT بدون COMPLETE). نمای Live Queue تعداد تماس‌های منتظر را بر پایه‌ی آخرین رویدادها نشان می‌دهد.</div>
</div>
<script>
document.getElementById('btnFS')?.addEventListener('click',()=>{ if(!document.fullscreenElement) document.documentElement.requestFullscreen(); else document.exitFullscreen(); });
function parseCell(td,type){
  if(type==='number'){ return parseFloat((td.textContent||'0').replaceAll(',',''))||0; }
  if(type==='time'){ const s=td.getAttribute('data-sec'); if(s!==null) return parseInt(s,10)||0; const p=(td.textContent||'0:00').split(':'); return (parseInt(p[0],10)||0)*60 + (parseInt(p[1],10)||0); }
  if(type==='duration'){ const d=td.getAttribute('data-dur'); if(d!==null) return parseInt(d,10)||0; const p=(td.textContent||'0:00:00').split(':'); return (parseInt(p[0],10)||0)*3600+(parseInt(p[1],10)||0)*60+(parseInt(p[2],10)||0); }
  return (td.textContent||'').trim();
}
function makeSortable(id){
  const tbl=document.getElementById(id); if(!tbl) return; const ths=tbl.querySelectorAll('thead th.sortable');
  ths.forEach((th,idx)=>{
    th.addEventListener('click',()=>{
      const type=th.getAttribute('data-type')||'text'; const tbody=tbl.querySelector('tbody'); const rows=Array.from(tbody.querySelectorAll('tr')); const asc=!(th.dataset.asc==='1');
      rows.sort((a,b)=>{ const va=parseCell(a.children[idx],type), vb=parseCell(b.children[idx],type); if(va<vb) return asc?-1:1; if(va>vb) return asc?1:-1; return 0; });
      tbody.innerHTML=''; rows.forEach(r=>tbody.appendChild(r)); ths.forEach(h=>delete h.dataset.asc); th.dataset.asc=asc?'1':'0';
    });
  });
}
<?php if($mode!=='wallboard'){ ?>makeSortable('tblAgents'); makeSortable('tblDaily'); makeSortable('tblFreq');<?php } ?>
const LIVE = <?php echo $live?'true':'false'; ?>; const REFRESH = <?php echo (int)$refresh; ?>;
async function fetchLive(){
  try{
    const params = new URLSearchParams(window.location.search); params.set('api','1'); params.delete('export');
    const res = await fetch(window.location.pathname + '?' + params.toString(), {cache:'no-store'});
    if(!res.ok) return;
    const data = await res.json();
    document.getElementById('kpi_total').textContent = (data.kpi.total_calls||0).toLocaleString('en-US');
    document.getElementById('kpi_answered').textContent = (data.kpi.answered_calls||0).toLocaleString('en-US');
    document.getElementById('kpi_noans').textContent = ((data.kpi.no_answered)||0).toLocaleString('en-US');
    document.getElementById('kpi_ar').textContent = (data.kpi.answer_rate||0)+'%';
    document.getElementById('kpi_sla1').textContent = (data.kpi.sla_offered||0)+'%';
    document.getElementById('kpi_sla2').textContent = (data.kpi.sla_answered||0)+'%';
    document.getElementById('kpi_aw').textContent = data.kpi.avg_wait_mmss||'0:00';
    document.getElementById('kpi_at').textContent = data.kpi.avg_talk_mmss||'0:00';
    if (data.live && typeof data.live.queue_waiting !== 'undefined'){ const el = document.getElementById('live_waiting'); if (el) el.textContent = (data.live.queue_waiting||0); }
    if (data.kpi && typeof data.kpi.repetitive_num !== 'undefined'){
        const elNum = document.getElementById('val_rep_num'); const elCnt = document.getElementById('val_rep_cnt');
        if(elNum) elNum.textContent = data.kpi.repetitive_num; if(elCnt) elCnt.textContent = 'Count: ' + (data.kpi.repetitive_count || 0);
    }
    const ab = document.getElementById('agentsBody'); 
    if(ab && data.agent_status){
      const status = data.agent_status || {}; ab.querySelectorAll('tr').forEach(tr=>{ const ext = (tr.children[0]?.textContent||'').trim(); const on = status[ext] ? 1 : 0; tr.classList.toggle('agent-active', !!on); tr.classList.toggle('agent-inactive', !on); });
    }
    // Update Frequent Callers Table (>=3)
    const fb = document.getElementById('freqBody');
    if (fb && data.frequent_callers) {
        let html = '';
        if (data.frequent_callers.length === 0) {
            html = '<tr><td colspan="3" style="text-align:center;color:var(--muted)">موردی یافت نشد (None)</td></tr>';
        } else {
            data.frequent_callers.forEach(row => {
                html += `<tr><td>${row.date}</td><td>${row.number}</td><td>${row.count}</td></tr>`;
            });
        }
        fb.innerHTML = html;
    }
  }catch(e){}
}
if(LIVE){ fetchLive(); setInterval(fetchLive, REFRESH*1000); }
// Initial fetch for non-live mode to populate tables
fetchLive();
</script>
</body>
</html>
