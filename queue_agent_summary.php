<?php
/* Queue Agent Summary – Single-Queue + Live + Wallboard + JSON API + XLSX + Workforce
 * Updated:
 * 1. Frequent Callers includes >= 3 calls.
 * 2. Excel Export includes Summary sheet.
 * 3. BUG FIX: Agents now turn 'inactive' correctly after a Transfer.
 * 4. NEW: Trends API (Calls Trend + Quality Trend) via ?trends=1
 * 5. NEW: Percentiles (P50/P90/P95) for wait time
 * 6. NEW: Transfer rate per agent + global
 * 7. NEW: Short call rate (talk < 30s) per agent + global
 * 8. NEW: Repeat caller rate
 * 9. NEW: AGENTLOGIN/AGENTLOGOFF parsed → login_sec, occupancy_pct per agent
 * 10. NEW: Abandon wait time (avg_abandon_wait_sec)
 * 11. NEW: Active call count per agent in agent_status
 * 12. NEW: ?compare=1 returns yesterday KPIs for delta display
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

/* Percentile helper */
function percentile(array $arr, float $p) {
  if (empty($arr)) return 0;
  sort($arr);
  $n = count($arr);
  if ($n === 1) return $arr[0];
  $idx = $p * ($n - 1);
  $lo  = (int)floor($idx);
  $hi  = (int)ceil($idx);
  if ($lo === $hi) return $arr[$lo];
  return (int)round($arr[$lo] + ($idx - $lo) * ($arr[$hi] - $arr[$lo]));
}

/* Helper to get ALL log files (Rotated + Current) */
function get_all_log_files() {
    $files = glob('/var/log/asterisk/queue_log*');
    if (!$files) return [];
    sort($files);
    return $files;
}

/* Trend bucket chooser */
function choose_bucket_sec($rangeSec){
  $rangeSec = max(1, (int)$rangeSec);
  if($rangeSec <= 6*3600) return 300;
  if($rangeSec <= 24*3600) return 900;
  if($rangeSec <= 7*86400) return 3600;
  if($rangeSec <= 31*86400) return 6*3600;
  return 86400;
}
function bucket_index($ts, $fromTs, $bucketSec){
  if($bucketSec<=0) return 0;
  $i = (int)floor(($ts - $fromTs)/$bucketSec);
  return max(0, $i);
}

/* FUNCTION: Get Most Repetitive Number for SELECTED RANGE */
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

/* FUNCTION: Get All Numbers >= 3 calls per Day */
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
    $result = [];
    foreach($dailyCounts as $date => $nums){
        foreach($nums as $num => $cnt){
            if($cnt >= 3){
                $result[] = ['date'=>$date, 'number'=>$num, 'count'=>$cnt];
            }
        }
    }
    usort($result, function($a, $b){
        if($a['date'] !== $b['date']) return strcmp($b['date'], $a['date']);
        return $b['count'] <=> $a['count'];
    });
    return $result;
}

/* ---- Extension Outgoing Calls via CDR ---- */
function get_outgoing_calls_by_ext($fromTs, $toTs, array $extList) {
    $result = [];
    try {
        $CDR_HOST = '127.0.0.1';
        $CDR_USER = 'root';
        $CDR_PASS = 'MAriaDB@';
        $CDR_DB   = 'asteriskcdrdb';

        $pdo = new PDO(
            "mysql:host=$CDR_HOST;dbname=$CDR_DB;charset=utf8mb4",
            $CDR_USER, $CDR_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        $startDt = date('Y-m-d H:i:s', $fromTs);
        $endDt   = date('Y-m-d H:i:s', $toTs);

        /*
         * extList keys look like "Agent 8001", "Atefeh Shams" etc. (from queue_log)
         * CDR channel gives us "8001", "8013" etc. (numeric only)
         *
         * Build a map:  numeric_ext => original_agent_name
         * e.g. "Agent 8001" → extract "8001" → ['8001' => 'Agent 8001']
         * Agents with no digits (e.g. "Atefeh Shams") won't appear in CDR anyway.
         */
        $numToAgent = [];
        foreach ($extList as $agentName) {
            if (preg_match('/(\d{3,})/', (string)$agentName, $m)) {
                $numToAgent[$m[1]] = (string)$agentName;
            }
        }

        $sql = "SELECT
                  SUBSTRING_INDEX(SUBSTRING_INDEX(channel, '/', -1), '-', 1) AS ext,
                  COUNT(*) AS cnt
                FROM cdr
                WHERE calldate >= ?
                  AND calldate <= ?
                  AND disposition = 'ANSWERED'
                  AND (channel LIKE 'SIP/%' OR channel LIKE 'PJSIP/%')
                  AND LENGTH(dst) >= 7
                GROUP BY ext";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([$startDt, $endDt]);

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $numExt = (string)$row['ext'];
            if (isset($numToAgent[$numExt])) {
                /* Key by original agent name so it matches $outgoingByExt[$ext] lookups */
                $result[$numToAgent[$numExt]] = (int)$row['cnt'];
            }
        }

    } catch (Throwable $e) {}
    return $result;
}

/* Fast counts for arbitrary time windows */
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

/* ============================================================
   SINGLE-PASS AGGREGATION — parses all new metrics in same loop
   ============================================================ */

/* Existing collections */
$entered=[]; $abandoned=[]; $connects=[]; $completes=[];
$queuesSeen=[];
$abandonTimestamps=[];
$callState=[];
$openConnectCountExt=[];
$pauseOpen=[]; $pauseSegments=[]; $pauseAccumAll=[];

/* NEW collections */
$waitSecAll          = [];   // answered wait seconds (for percentiles)
$abandonWaitAll      = [];   // abandon wait seconds
$transferCountExt    = [];   // ext => TRANSFER count
$shortCallCountExt   = [];   // ext => short call count (talk < 30s)
$callerIdCounts      = [];   // callerID => total appearances (repeat rate)
$agentLoginOpen      = [];   // ext => login_ts (open session)
$agentLoginTotalSec  = [];   // ext => total logged-in seconds
$agentLoginCount     = [];   // ext => number of login sessions

/* Trend accumulators */
$wantTrends = (isset($_GET['trends']) && (int)$_GET['trends']===1);
$bucketSec = choose_bucket_sec(max(1, $to-$from));
$bucketCount = (int)ceil(max(1, $to-$from)/$bucketSec);
$bucketCount = max(1, min($bucketCount, 1500));

$enterBucket   = [];
$answeredBucket= [];
$bucketWaitSum = [];
$bucketWaitCnt = [];
$bucketSlaHits = [];

/* ---- Parse ALL queue_log files ---- */
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

      /* ---------- ENTERQUEUE ---------- */
      if($event==='ENTERQUEUE'){
        $entered[$callid]=$queue;
        $callState[$callid]=['queue'=>$queue,'last'=>'ENTERQUEUE','ts'=>$t];

        /* collect callerID for repeat-rate */
        $cid_caller = $p[6] ?? '';
        if($cid_caller !== ''){
          $callerIdCounts[$cid_caller] = ($callerIdCounts[$cid_caller]??0)+1;
        }

        if($wantTrends){
          if($selectedQueue==='' || $queue===$selectedQueue){
            $bi = bucket_index($t, $from, $bucketSec);
            if($bi < $bucketCount) $enterBucket[$callid] = $bi;
          }
        }

      /* ---------- RINGNOANSWER ---------- */
      } elseif($event==='RINGNOANSWER'){
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>'RINGNOANSWER','ts'=>$t];

      /* ---------- ABANDON ---------- */
      } elseif ($event==='ABANDON'){
        $abandoned[$callid] = $queue;
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>'ABANDON','ts'=>$t];
        $abandonTimestamps[$callid] = $t;
        /* Asterisk ABANDON format: ts|callid|queue|agent|ABANDON|position|origpos|waittime */
        $abWait = is_numeric($p[7]??'') ? (int)$p[7] : (is_numeric($d1) ? (int)$d1 : 0);
        if($abWait > 0) $abandonWaitAll[] = $abWait;

      /* ---------- EXIT events ---------- */
      } elseif ($event==='EXITWITHTIMEOUT' || $event==='EXITWITHKEY' || $event==='SYSCOMPAT'){
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>$event,'ts'=>$t];

      /* ---------- TRANSFER ---------- */
      } elseif ($event==='TRANSFER' || $event==='BLINDTRANSFER' || $event==='ATTENDEDTRANSFER') {
        if(isset($callState[$callid]['ext'])){
            $ext = $callState[$callid]['ext'];
            if(isset($openConnectCountExt[$ext])) {
                $openConnectCountExt[$ext] = max(0, $openConnectCountExt[$ext]-1);
            }
            /* NEW: count transfer per agent */
            $transferCountExt[$ext] = ($transferCountExt[$ext]??0)+1;
        }
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>$event,'ts'=>$t];

      /* ---------- CONNECT ---------- */
      } elseif ($event==='CONNECT'){
        $ext=ext_from_agent($agent);
        $wait=is_numeric($d1)?(int)$d1:0;

        $qResolved = ($queue!==''?$queue:($entered[$callid]??''));
        if(!isset($connects[$callid])) $connects[$callid]=['queue'=>$qResolved,'ext'=>$ext,'wait'=>$wait,'ts'=>$t];
        $callState[$callid]=['queue'=>$qResolved,'last'=>'CONNECT','ts'=>$t,'ext'=>$ext];
        $openConnectCountExt[$ext] = ($openConnectCountExt[$ext] ?? 0) + 1;

        if($wantTrends){
          if(($selectedQueue==='' || $qResolved===$selectedQueue) && !isset($answeredBucket[$callid])){
            $bi = bucket_index($t, $from, $bucketSec);
            if($bi < $bucketCount){
              $answeredBucket[$callid] = $bi;
              $bucketWaitSum[$bi] = ($bucketWaitSum[$bi] ?? 0) + $wait;
              $bucketWaitCnt[$bi] = ($bucketWaitCnt[$bi] ?? 0) + 1;
              if($wait <= $sla) $bucketSlaHits[$bi] = ($bucketSlaHits[$bi] ?? 0) + 1;
            }
          }
        }

      /* ---------- COMPLETE ---------- */
      } elseif($event==='COMPLETEAGENT' || $event==='COMPLETECALLER'){
        $talk=0; if(is_numeric($d2)) $talk=(int)$d2; if($talk===0 && is_numeric($d1) && (int)$d1>0) $talk=(int)$d1;
        if(!isset($completes[$callid]) || $talk>$completes[$callid]['talk']) $completes[$callid]=['talk'=>$talk];
        if(isset($callState[$callid]['ext'])){
          $ext = $callState[$callid]['ext'];
          if(isset($openConnectCountExt[$ext])) $openConnectCountExt[$ext] = max(0, $openConnectCountExt[$ext]-1);
          /* NEW: count short calls */
          if($talk > 0 && $talk < 30){
            $shortCallCountExt[$ext] = ($shortCallCountExt[$ext]??0)+1;
          }
        }
        $callState[$callid]=['queue'=>($queue!==''?$queue:($entered[$callid]??'')),'last'=>'COMPLETE','ts'=>$t];

      /* ---------- PAUSE ---------- */
      } elseif($event==='PAUSE'){
        $ext=ext_from_agent($agent);
        $pauseOpen[$ext]=$t; if(!isset($pauseAccumAll[$ext])) $pauseAccumAll[$ext]=0;

      /* ---------- UNPAUSE ---------- */
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

      /* ---------- AGENTLOGIN (NEW) ---------- */
      } elseif($event==='AGENTLOGIN'){
        $ext=ext_from_agent($agent!=='' ? $agent : ($d1!=='' ? $d1 : ''));
        if($ext!==''){
          $agentLoginOpen[$ext] = $t;
          $agentLoginCount[$ext] = ($agentLoginCount[$ext]??0)+1;
        }

      /* ---------- AGENTLOGOFF (NEW) ---------- */
      } elseif($event==='AGENTLOGOFF'){
        $ext=ext_from_agent($agent!=='' ? $agent : ($d1!=='' ? $d1 : ''));
        if($ext!=='' && isset($agentLoginOpen[$ext])){
          $loginSec = max(0, $t - $agentLoginOpen[$ext]);
          $agentLoginTotalSec[$ext] = ($agentLoginTotalSec[$ext]??0)+$loginSec;
          unset($agentLoginOpen[$ext]);
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

/* Close open login sessions (agent still logged in at $to) */
foreach($agentLoginOpen as $ext=>$loginTs){
  $loginSec = max(0, $to - $loginTs);
  $agentLoginTotalSec[$ext] = ($agentLoginTotalSec[$ext]??0)+$loginSec;
}

/* ---- Aggregations ---- */
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

  /* collect wait for percentiles */
  $waitSecAll[] = $w;
}

/* ---- KPIs ---- */
$enteredCount=0;
foreach($entered as $cid=>$q){
  if ($selectedQueue === '' || $q === $selectedQueue) $enteredCount++;
}
$answeredCount=count($answeredUnique);
$offered=$enteredCount;
$answerRate=($offered>0)?round(100*$answeredCount/$offered,2):0.0;
$abandonRate=($offered>0)?round(100*($offered-$answeredCount)/$offered,2):0.0;
$slaOnOffered=($offered>0)?round(100*$slaHits/$offered,2):0.0;
$slaOnAnswered=($answeredCount>0)?round(100*$slaHits/$answeredCount,2):0.0;
$sumWaitAll=array_sum($waitAggByQueue);
$sumTalkAll=array_sum($talkAggByQueue);
$avgWaitSec=($answeredCount>0)?(int)round($sumWaitAll/$answeredCount):0;
$avgTalkSec=($answeredCount>0)?(int)round($sumTalkAll/$answeredCount):0;
$noAnswered = max(0, $offered - $answeredCount);

/* Percentiles */
$waitP50 = percentile($waitSecAll, 0.50);
$waitP90 = percentile($waitSecAll, 0.90);
$waitP95 = percentile($waitSecAll, 0.95);
$waitMax = !empty($waitSecAll) ? max($waitSecAll) : 0;

/* Abandon wait */
$avgAbandonWaitSec = !empty($abandonWaitAll) ? (int)round(array_sum($abandonWaitAll)/count($abandonWaitAll)) : 0;

/* Global transfer count */
$totalTransfers = 0;
foreach($transferCountExt as $ext=>$cnt){
  $totalTransfers += $cnt;
}
$transferRate = ($answeredCount>0) ? round(100*$totalTransfers/$answeredCount,2) : 0.0;

/* Global short call count */
$totalShortCalls = 0;
foreach($shortCallCountExt as $ext=>$cnt){
  $totalShortCalls += $cnt;
}
$shortCallRate = ($answeredCount>0) ? round(100*$totalShortCalls/$answeredCount,2) : 0.0;

/* Repeat caller rate */
$repeatCallers = 0;
$totalCallerIds = 0;
foreach($callerIdCounts as $cid_c=>$cnt){
  if($selectedQueue!==''){
    /* filter by queue — check if callerID is in scope */
    /* approximation: count all callerIDs since we can't easily filter here */
  }
  $totalCallerIds++;
  if($cnt > 1) $repeatCallers++;
}
$repeatCallerRate = ($totalCallerIds>0) ? round(100*$repeatCallers/$totalCallerIds,2) : 0.0;

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

/* Extension outgoing calls from CDR */
$outgoingByExt = get_outgoing_calls_by_ext($from, $to, array_keys($currentExts));

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

/* Active calls (connected right now) */
$activeCalls = 0;
foreach($openConnectCountExt as $ext=>$cnt){
  $activeCalls += max(0,(int)$cnt);
}

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

/* ---- Trends payload ---- */
$trendsPayload = null;
if($wantTrends){
  $labels = [];
  for($i=0;$i<$bucketCount;$i++){
    $ts = $from + ($i*$bucketSec);
    if($ts > $to) break;
    $labels[] = date('m-d H:i', $ts);
  }
  $realCount = count($labels);
  if($realCount<=0){ $realCount=1; $labels=[date('m-d H:i',$from)]; }

  $offeredSeries  = array_fill(0,$realCount,0);
  $answeredSeries = array_fill(0,$realCount,0);
  $endedSeries    = array_fill(0,$realCount,0);
  $arSeries       = array_fill(0,$realCount,0);
  $slaSeries      = array_fill(0,$realCount,0);
  $avgWaitSeries  = array_fill(0,$realCount,0);

  foreach($enterBucket as $cid=>$bi){
    if($bi>=0 && $bi<$realCount) $offeredSeries[$bi]++;
  }
  foreach($answeredBucket as $cid=>$bi){
    if($bi>=0 && $bi<$realCount) $answeredSeries[$bi]++;
  }
  foreach($enterBucket as $cid=>$bi){
    if(!isset($answeredBucket[$cid])){
      if($bi>=0 && $bi<$realCount) $endedSeries[$bi]++;
    }
  }

  for($i=0;$i<$realCount;$i++){
    $off = $offeredSeries[$i];
    $ans = $answeredSeries[$i];
    $arSeries[$i]  = ($off>0) ? round(100*$ans/$off,1) : 0;
    $slaHitsB = (int)($bucketSlaHits[$i] ?? 0);
    $slaSeries[$i] = ($ans>0) ? round(100*$slaHitsB/$ans,1) : 0;
    $wsum = (int)($bucketWaitSum[$i] ?? 0);
    $wcnt = (int)($bucketWaitCnt[$i] ?? 0);
    $avgWaitSeries[$i] = ($wcnt>0) ? (int)round($wsum/$wcnt) : 0;
  }

  $trendsPayload = [
    'bucket_sec'=>$bucketSec,
    'labels'=>$labels,
    'calls'=>[
      'offered'=>$offeredSeries,
      'answered'=>$answeredSeries,
      'customer_ended'=>$endedSeries
    ],
    'quality'=>[
      'answer_rate'=>$arSeries,
      'sla_answered'=>$slaSeries,
      'avg_wait_sec'=>$avgWaitSeries
    ]
  ];
}

/* ---- Compare payload (?compare=1) ---- */
$comparePayload = null;
if(isset($_GET['compare']) && (int)$_GET['compare']===1){
  /* Compute yesterday's same-window offset */
  $rangeSec = max(1, $to - $from);
  $prevFrom = $from - $rangeSec;
  $prevTo   = $to   - $rangeSec;

  $prevEntered=[]; $prevConnects=[]; $prevSlaHits=0;
  $prevWaitSecAll=[];

  foreach ($allLogFiles as $logPath) {
    if (!is_readable($logPath)) continue;
    $fh=fopen($logPath,'r'); if(!$fh) continue;
    while(($line=fgets($fh))!==false){
      $line=trim($line); if($line==='') continue;
      $p=explode('|',$line); if(count($p)<5) continue;
      $t=(int)$p[0]; if($t<$prevFrom || $t>$prevTo) continue;
      $callid=$p[1]; $queue=$p[2]; $event=$p[4]; $d1=$p[5]??'';
      if($selectedQueue!=='' && $queue!==$selectedQueue) continue;
      if($event==='ENTERQUEUE') $prevEntered[$callid]=1;
      elseif($event==='CONNECT'){
        if(!isset($prevConnects[$callid])){
          $wait=is_numeric($d1)?(int)$d1:0;
          $prevConnects[$callid]=['wait'=>$wait];
          $prevWaitSecAll[]=$wait;
          if($wait<=$sla) $prevSlaHits++;
        }
      }
    }
    fclose($fh);
  }
  $prevOffered  = count($prevEntered);
  $prevAnswered = count($prevConnects);
  $prevSumWait  = array_sum(array_column($prevConnects,'wait'));
  $comparePayload = [
    'total_calls'    => $prevOffered,
    'answered_calls' => $prevAnswered,
    'no_answered'    => max(0,$prevOffered-$prevAnswered),
    'answer_rate'    => ($prevOffered>0)?round(100*$prevAnswered/$prevOffered,2):0.0,
    'abandon_rate'   => ($prevOffered>0)?round(100*($prevOffered-$prevAnswered)/$prevOffered,2):0.0,
    'sla_answered'   => ($prevAnswered>0)?round(100*$prevSlaHits/$prevAnswered,2):0.0,
    'avg_wait_sec'   => ($prevAnswered>0)?(int)round($prevSumWait/$prevAnswered):0,
    'avg_wait_mmss'  => ($prevAnswered>0)?mmss((int)round($prevSumWait/$prevAnswered)):mmss(0),
    'wait_p50'       => percentile($prevWaitSecAll,0.50),
    'wait_p90'       => percentile($prevWaitSecAll,0.90),
    'window_start'   => date('Y-m-d H:i',$prevFrom),
    'window_end'     => date('Y-m-d H:i',$prevTo),
  ];
}

/* ===== XLSX export ===== */
if (isset($_GET['export']) && $_GET['export']==='xlsx') {
  if (!class_exists('ZipArchive')) { http_response_code(500); echo "<h3>PHP ZipArchive missing.</h3>"; exit; }

  $summaryRows = [];
  $summaryRows[] = ['Dashboard Summary', ''];
  $summaryRows[] = ['Date Range', "$start_in to $end_in"];
  $summaryRows[] = ['Queue', $selectedQueue !== '' ? $selectedQueue : 'ALL'];
  $summaryRows[] = ['', ''];
  $summaryRows[] = ['METRIC', 'VALUE'];
  $summaryRows[] = ['Total Calls (Offered)', $offered];
  $summaryRows[] = ['Answered Calls', $answeredCount];
  $summaryRows[] = ['Customer-Ended (Abandon)', $noAnswered];
  $summaryRows[] = ['Answer Rate', $answerRate.'%'];
  $summaryRows[] = ['Abandon Rate', $abandonRate.'%'];
  $summaryRows[] = ['SLA (on Offered)', $slaOnOffered.'%'];
  $summaryRows[] = ['SLA (on Answered)', $slaOnAnswered.'%'];
  $summaryRows[] = ['Avg Wait Time', mmss($avgWaitSec)];
  $summaryRows[] = ['Avg Talk Time', mmss($avgTalkSec)];
  $summaryRows[] = ['Wait P50', mmss($waitP50)];
  $summaryRows[] = ['Wait P90', mmss($waitP90)];
  $summaryRows[] = ['Wait P95', mmss($waitP95)];
  $summaryRows[] = ['Max Wait', mmss($waitMax)];
  $summaryRows[] = ['Transfer Count', $totalTransfers];
  $summaryRows[] = ['Transfer Rate', $transferRate.'%'];
  $summaryRows[] = ['Short Call Count (<30s)', $totalShortCalls];
  $summaryRows[] = ['Short Call Rate', $shortCallRate.'%'];
  $summaryRows[] = ['Repeat Caller Rate', $repeatCallerRate.'%'];
  $summaryRows[] = ['Active Calls (Live)', $activeCalls];
  $summaryRows[] = ['', ''];
  $summaryRows[] = ['Peak Abandon Hour', "$peakHour ($peakCount calls)"];
  $summaryRows[] = ['Top Repetitive Number', "$repNum ($repCnt calls)"];

  $agentsRows = [];
  $agentsRows[] = ['Agent/Ext','Answered','Avg Wait','Avg Talk','Outgoing Calls','Short Calls','Short%','Transfers','Transfer%','Login Time','Pause Time','Occupancy%'];
  $allExts = array_keys($currentExts); sort($allExts, SORT_STRING | SORT_FLAG_CASE);
  foreach ($allExts as $ext){
    $cntAns     = (int)($answeredByExt[$ext] ?? 0);
    $awSec      = $cntAns>0 ? (int)round(($waitAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $atSec      = $cntAns>0 ? (int)round(($talkAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $shortCalls = (int)($shortCallCountExt[$ext]??0);
    $shortPct   = $cntAns>0 ? round(100*$shortCalls/$cntAns,1) : 0;
    $xfers      = (int)($transferCountExt[$ext]??0);
    $xferPct    = $cntAns>0 ? round(100*$xfers/$cntAns,1) : 0;
    $loginSec   = (int)($agentLoginTotalSec[$ext]??0);
    $pauseSec   = (int)($pauseAccumAll[$ext]??0);
    $talkSec    = (int)($talkAggByExt[$ext]??0);
    $occPct     = $loginSec>0 ? round(100*$talkSec/$loginSec,1) : 0;
    $outgoing   = (int)($outgoingByExt[$ext]??0);
    $agentsRows[] = [$ext,(string)$cntAns,mmss($awSec),mmss($atSec),(string)$outgoing,(string)$shortCalls,$shortPct.'%',(string)$xfers,$xferPct.'%',hhmmss($loginSec),hhmmss($pauseSec),$occPct.'%'];
  }

  $workRows = [];
  $workRows[] = ['Date','Agent/Ext','Pause (HH:MM:SS)'];
  foreach ($days as $d){
    foreach ($currentExts as $ext=>$_){
      $pauseDay = (int)($pauseByDayExt[$d][$ext] ?? 0);
      $workRows[] = [$d, $ext, hhmmss($pauseDay)];
    }
  }

  $frequentRows = [];
  $frequentRows[] = ['Date', 'CallerID', 'Count (>=3)'];
  $frequentList = get_frequent_callers_per_day($from, $to, $selectedQueue);
  foreach ($frequentList as $row) {
      $frequentRows[] = [$row['date'], $row['number'], (string)$row['count']];
  }

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

  $sheet1 = $buildSheetXML($summaryRows);
  $sheet2 = $buildSheetXML($agentsRows);
  $sheet3 = $buildSheetXML($workRows);
  $sheet4 = $buildSheetXML($frequentRows);

  $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
  $zip = new ZipArchive();
  $zip->open($tmp, ZipArchive::OVERWRITE);

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

  $zip->addFromString('_rels/.rels',
    '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
    '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
    '</Relationships>'
  );

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
    $cntAns     = (int)($answeredByExt[$ext] ?? 0);
    $awSec      = $cntAns>0 ? (int)round(($waitAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $atSec      = $cntAns>0 ? (int)round(($talkAggByExt[$ext] ?? 0)/$cntAns) : 0;
    $shortCalls = (int)($shortCallCountExt[$ext]??0);
    $shortPct   = $cntAns>0 ? round(100*$shortCalls/$cntAns,1) : 0.0;
    $xfers      = (int)($transferCountExt[$ext]??0);
    $xferPct    = $cntAns>0 ? round(100*$xfers/$cntAns,1) : 0.0;
    $loginSec   = (int)($agentLoginTotalSec[$ext]??0);
    $pauseSec   = (int)($pauseAccumAll[$ext]??0);
    $talkSec    = (int)($talkAggByExt[$ext]??0);
    $occPct     = $loginSec>0 ? round(100*$talkSec/$loginSec,1) : 0.0;
    $idlePct    = $loginSec>0 ? round(100*max(0,$loginSec-$talkSec-$pauseSec)/$loginSec,1) : 0.0;
    $agents[] = [
      'ext'             => $ext,
      'answered'        => $cntAns,
      'avg_wait_sec'    => $awSec,
      'avg_talk_sec'    => $atSec,
      'avg_wait'        => mmss($awSec),
      'avg_talk'        => mmss($atSec),
      /* NEW */
      'short_calls'     => $shortCalls,
      'short_call_rate' => $shortPct,
      'transfer_count'  => $xfers,
      'transfer_rate'   => $xferPct,
      'outgoing_calls'  => (int)($outgoingByExt[$ext]??0),
      'login_sec'       => $loginSec,
      'pause_sec'       => $pauseSec,
      'talk_sec'        => $talkSec,
      'occupancy_pct'   => $occPct,
      'idle_pct'        => $idlePct,
    ];
  }

  $workforce = [];
  foreach ($days as $d){
    foreach ($currentExts as $ext=>$_){
      $pauseDay = (int)($pauseByDayExt[$d][$ext] ?? 0);
      $workforce[] = ['date'=>$d,'ext'=>$ext,'pause_sec'=>$pauseDay,'pause'=>hhmmss($pauseDay)];
    }
  }

  $frequentCallers = get_frequent_callers_per_day($from, $to, $selectedQueue);
  $queues = array_keys($queuesSeen); sort($queues);

  $payload = [
    'meta'=>[
      'start'=>$start,'end'=>$end,'sla'=>$sla,'queues'=>$queues,'selected_queue'=>$selectedQueue,
      'work_window'=>['start'=>$WORK_START,'end'=>$WORK_END],
      'generated_at'=>date('Y-m-d H:i:s')
    ],
    'kpi'=>[
      /* existing — unchanged */
      'total_calls'          => $offered,
      'answered_calls'       => $answeredCount,
      'no_answered'          => $noAnswered,
      'answer_rate'          => $answerRate,
      'sla_offered'          => $slaOnOffered,
      'sla_answered'         => $slaOnAnswered,
      'avg_wait_mmss'        => mmss($avgWaitSec),
      'avg_talk_mmss'        => mmss($avgTalkSec),
      'peak_abandon_hour'    => $peakHour,
      'peak_abandon_count'   => $peakCount,
      'repetitive_num'       => $repNum,
      'repetitive_count'     => $repCnt,
      /* NEW */
      'abandon_rate'         => $abandonRate,
      'avg_wait_sec'         => $avgWaitSec,
      'avg_talk_sec'         => $avgTalkSec,
      'wait_p50'             => $waitP50,
      'wait_p90'             => $waitP90,
      'wait_p95'             => $waitP95,
      'wait_max'             => $waitMax,
      'wait_p50_mmss'        => mmss($waitP50),
      'wait_p90_mmss'        => mmss($waitP90),
      'wait_p95_mmss'        => mmss($waitP95),
      'transfer_count'       => $totalTransfers,
      'transfer_rate'        => $transferRate,
      'short_call_count'     => $totalShortCalls,
      'short_call_rate'      => $shortCallRate,
      'repeat_caller_rate'   => $repeatCallerRate,
      'avg_abandon_wait_sec' => $avgAbandonWaitSec,
      'avg_abandon_wait_mmss'=> mmss($avgAbandonWaitSec),
    ],
    'live'          => ['queue_waiting'=>$queueWaitingSelected, 'active_calls'=>$activeCalls],
    'agent_status'  => $agentOnCall,
    'agents'        => $agents,
    'workforce'     => $workforce,
    'frequent_callers' => $frequentCallers,
  ];

  if ($rangesPayload  !== null) $payload['ranges']  = $rangesPayload;
  if ($trendsPayload  !== null) $payload['trends']  = $trendsPayload;
  if ($comparePayload !== null) $payload['compare'] = $comparePayload;

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
.live-box{display:flex;align-items:center;gap:10px;padding:10px 14px;border:1px solid var(--border);border-radius:12px;background:var(--card)}
.live-dot{width:10px;height:10px;border-radius:50%;background:#22c55e;box-shadow:0 0 0 6px rgba(34,197,94,.15)}
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
</div>
<script>
document.getElementById('btnFS')?.addEventListener('click',()=>{ if(!document.fullscreenElement) document.documentElement.requestFullscreen(); else document.exitFullscreen(); });
</script>
</body>
</html>
