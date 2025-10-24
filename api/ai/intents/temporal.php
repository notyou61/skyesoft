<?php
// 📄 File: api/ai/intents/temporal.php
// Version: v4.7 – Integrated day/phase resolver, Codex-aligned rollover logic
// Purpose: Resolve temporal intent into structured JSON (data only)

require_once __DIR__ . '/../../helpers.php';

function handleIntent($prompt, $codexPath, $ssePath)
{
    date_default_timezone_set('America/Phoenix');

    // 🧩 1. Load SSE data
    $endpoint = "https://www.skyelighting.com/skyesoft/api/getDynamicData.php";
    $ctx = stream_context_create(array('http' => array('method'=>'GET','timeout'=>5)));
    $sseRaw = @file_get_contents($endpoint, false, $ctx);
    if (!$sseRaw) return array('domain'=>'temporal','error'=>'SSE unreachable');
    $sse = json_decode($sseRaw, true);
    if (!is_array($sse)) return array('domain'=>'temporal','error'=>'Invalid SSE JSON');

    // 🧩 2. Extract runtime
    $timeData = isset($sse['timeDateArray']) ? $sse['timeDateArray'] : array();
    $weather  = isset($sse['weatherData']) ? $sse['weatherData'] : array();
    $holidays = isset($weather['federalHolidaysDynamic']) && is_array($weather['federalHolidaysDynamic'])
                ? $weather['federalHolidaysDynamic'] : array();
    $sunset   = isset($weather['sunset']) ? $weather['sunset'] : null;

    $nowStr   = isset($timeData['currentLocalTime']) ? $timeData['currentLocalTime'] : date('g:i A');
    $todayStr = isset($timeData['currentDate']) ? $timeData['currentDate'] : date('Y-m-d');
    $tz       = isset($timeData['timeZone']) ? $timeData['timeZone'] : 'America/Phoenix';
    $nowTs    = strtotime("$todayStr $nowStr") ?: time();
    $today    = date('Y-m-d', $nowTs);

    // 🧩 3. Load Codex
    $codex = json_decode(@file_get_contents($codexPath), true) ?: array();
    $tis   = isset($codex['timeIntervalStandards']) ? $codex['timeIntervalStandards'] : array();

    // 🧩 4. Build segment (office/shop)
    $lp = strtolower($prompt);
    $tisKey = (strpos($lp, 'shop') !== false) ? 'segmentsShop' : 'segmentsOffice';
    $segments = array('worktime'=>array('start'=>'7:30 AM','end'=>'3:30 PM','name'=>'Worktime'));
    if (isset($tis[$tisKey]['items']) && is_array($tis[$tisKey]['items'])) {
        foreach ($tis[$tisKey]['items'] as $seg) {
            if (preg_match('/^Worktime$/i', $seg['Interval'])) {
                list($s,$e) = explode(' – ',$seg['Hours']);
                $segments['worktime']=array('start'=>trim($s),'end'=>trim($e),'name'=>$seg['Interval']);
                break;
            }
        }
    }
    if ($sunset) $segments['sundown']=array('start'=>$sunset,'name'=>'Sundown');

    // 🧩 5. Event detection
    $event='time';
    $map=array(
        'sundown'=>'/sun(set|down)|dusk/',
        'worktime'=>'/work(day|time)|office|start|begin|finish|end|close|business hours/',
        'holiday'=>'/holiday/',
        'time'=>'/time|clock|now/'
    );
    foreach($map as $k=>$p){ if(preg_match($p,$lp)){ $event=$k; break; } }

    // 🧩 6. Flags
    $flags=array(
        'useEndTime'=>(strpos($lp,'finish')!==false||strpos($lp,'end')!==false||strpos($lp,'close')!==false),
        'isTomorrow'=>strpos($lp,'tomorrow')!==false,
        'isNext'=>strpos($lp,'next')!==false,
        'isDelayed'=>strpos($lp,'delayed')!==false,
        'isAgo'=>strpos($lp,'ago')!==false
    );

    // 🧩 7. Day & phase resolvers
    $dayInfo = resolveDayType($tis, $holidays, $nowTs);
    $todayType = $dayInfo['dayType'];
    $isWorkdayToday = $dayInfo['isWorkday'];

    // Inline phase resolver
    $seg = $segments['worktime'];
    $startTs = strtotime("$today {$seg['start']}");
    $endTs   = strtotime("$today {$seg['end']}");
    if ($nowTs < $startTs)      $phase = 'before_worktime';
    elseif ($nowTs >= $startTs && $nowTs < $endTs) $phase = 'worktime_active';
    else                        $phase = 'after_worktime';

    // 🧩 8. Compute target date (tomorrow/next)
    $targetDate = $today;
    if ($flags['isTomorrow'] || $flags['isNext']) {
        $candidateTs = strtotime('+1 day', $nowTs);
        $loop = 0;
        while ($loop < 10) {
            $dayCheck = resolveDayType($tis, $holidays, $candidateTs);
            if ($dayCheck['dayType'] === 'Workday') {
                $targetDate = date('Y-m-d', $candidateTs);
                break;
            }
            $candidateTs = strtotime('+1 day', $candidateTs);
            $loop++;
        }
    }

    // 🧩 9. Build target timestamp
    $targetTime = null; $status='pending';
    switch($event){
        case 'worktime':
            $targetTime = $flags['useEndTime'] ? $seg['end'] : $seg['start'];
            $targetTs = strtotime("$targetDate $targetTime");

            // phase aware status
            if ($phase==='before_worktime') $status='upcoming';
            elseif($phase==='worktime_active') $status='ongoing';
            elseif($phase==='after_worktime') $status='ended';
            break;

        case 'sundown':
            if ($sunset) {
                $targetTime=$sunset;
                $targetTs=strtotime("$today $sunset");
            }
            break;

        case 'holiday':
            $nextHoliday=null;
            foreach($holidays as $h){
                $hDate = isset($h['date'])?$h['date']:null;
                $hTs = strtotime($hDate);
                if($hTs && $hTs>$nowTs){ $nextHoliday=$h; break; }
            }
            if($nextHoliday){
                $targetDate=$nextHoliday['date'];
                $targetTime='12:00 AM';
                $status='holiday';
                $targetTs=strtotime("$targetDate $targetTime");
            }
            break;

        default:
            $targetTs=$nowTs; $targetTime=date('g:i A',$nowTs);
    }

    // 🧩 10. Delta
    $delta=$targetTs-$nowTs;
    $abs=abs($delta);
    $totalDays=floor($abs/86400);
    $rem=$abs%86400;
    $hrs=floor($rem/3600);
    $mins=floor(($rem%3600)/60);
    $isPast=($delta<0);
    $direction=$isPast?'ago':'until';

    // 🧩 11. Return structured data
    return array(
        'domain'=>'temporal',
        'codexNode'=>'timeIntervalStandards',
        'prompt'=>$prompt,
        'intent'=>'temporal_query',
        'event'=>$event,
        'status'=>$status,
        'phase'=>$phase,
        'dayType'=>$todayType,
        'isWorkdayToday'=>$isWorkdayToday,
        'data'=>array(
            'runtime'=>array(
                'now'=>date('g:i A',$nowTs),
                'date'=>date('F j, Y',$nowTs),
                'timezone'=>$tz,
                'targetDate'=>$targetDate,
                'targetTime'=>$targetTime,
                'delta'=>array(
                    'hours'=>$hrs,
                    'minutes'=>$mins,
                    'direction'=>$direction,
                    'isPast'=>$isPast
                )
            ),
            'intervals'=>$segments
        ),
        'flags'=>$flags
    );
}
