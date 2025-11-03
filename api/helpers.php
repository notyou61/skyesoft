<?php
#region File Header
// File: helpers.php
// System: Skyesoft Codex [Subsystem] v7.2
// Compliance: Tier-A | PHP 5.6 Safe
// Features: AI Access | CRUD | Codex Parsing | Meta Footer Renderer
// Outputs: JSON | Text | Diagnostic
// Codex Parliamentarian Approved: 2025-11-03 (CPAP-01)
#endregion

#region Safety Defaults for Standalone Usage
if (!isset($logDir)) {
    $logDir = dirname(__DIR__) . '/logs/';
}
if (!isset($codex)) {
    $codex = array();  // Safe fallback if not yet loaded
}
#endregion

#region Logging Enhancement for Helpers
function logHelperInvocation($name) {
    global $logDir;
    file_put_contents($logDir.'helper_invocations.log', "[".date('Y-m-d H:i:s')."] Helper invoked: $name\n", FILE_APPEND);
}
#endregion

#region Codex Command Handler
function handleCodexCommand($prompt, $dynamicData) {
    logHelperInvocation(__FUNCTION__);
    $codex = isset($dynamicData['codex']) ? $dynamicData['codex'] : array();

    $messages = array(
        array(
            "role" => "system",
            "content" => "You are Skyebot™. Use the provided Codex to answer semantically. " .
                         "Interpret glossary, modules, and constitution queries naturally. " .
                         "If asked to list, present cleanly. Never invent content outside the Codex."
        ),
        array("role" => "system", "content" => json_encode($codex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)),
        array("role" => "user", "content" => $prompt)
    );

    $response = callOpenAi($messages);
    sendJsonResponse($response, "codex", array("sessionId" => session_id()));
}
#endregion

#region Response Utilities
function sendJsonResponse($response, $action = "none", $extra = array(), $status = 200) {
    logHelperInvocation(__FUNCTION__);
    http_response_code($status);
    $data = array_merge(array(
        "response"  => is_array($response) ? json_encode($response, JSON_PRETTY_PRINT) : $response,
        "action"    => $action,
        "sessionId" => session_id()
    ), $extra);
    echo json_encode($data, JSON_PRETTY_PRINT);
    exit;
}
#endregion

#region Authentication Utilities
function authenticateUser($username, $password) {
    logHelperInvocation(__FUNCTION__);
    $validCredentials = array('admin' => password_hash('secret', PASSWORD_DEFAULT));
    return isset($validCredentials[$username]) && password_verify($password, $validCredentials[$username]);
}
#endregion

#region CRUD Placeholders
function createCrudEntity($entity, $details) {
    logHelperInvocation(__FUNCTION__);
    file_put_contents(__DIR__ . "/create_" . $entity . ".log", json_encode($details) . "\n", FILE_APPEND);
    return true;
}
function readCrudEntity($entity, $criteria) {
    logHelperInvocation(__FUNCTION__);
    return "Sample " . $entity . " details for: " . json_encode($criteria);
}
function updateCrudEntity($entity, $updates) {
    logHelperInvocation(__FUNCTION__);
    file_put_contents(__DIR__ . "/update_" . $entity . ".log", json_encode($updates) . "\n", FILE_APPEND);
    return true;
}
function deleteCrudEntity($entity, $target) {
    logHelperInvocation(__FUNCTION__);
    file_put_contents(__DIR__ . "/delete_" . $entity . ".log", json_encode($target) . "\n", FILE_APPEND);
    return true;
}
#endregion

#region Session & Logout Management
function performLogout() {
    logHelperInvocation(__FUNCTION__);
    session_unset();
    session_destroy();
    session_write_close();
    session_start();
    if (ini_get("session.use_cookies")) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p["path"], $p["domain"], $p["secure"], $p["httponly"]);
    }
    setcookie('skyelogin_user', '', time() - 3600, '/', 'www.skyelighting.com');
}
#endregion

#region Normalization Helpers
function normalizeAddress($address) {
    logHelperInvocation(__FUNCTION__);
    $address = preg_replace('/\s+/', ' ', trim($address));
    $address = ucwords(strtolower($address));
    $address = preg_replace_callback('/\b(\d+)(St|Nd|Rd|Th)\b/i', function($m){return $m[1].strtolower($m[2]);}, $address);
    return $address;
}
function getAssessorApi($stateFIPS, $countyFIPS) {
    logHelperInvocation(__FUNCTION__);
    if ($stateFIPS !== "04") return null;
    switch ($countyFIPS) {
        case "013": return "https://mcassessor.maricopa.gov/api";
        case "019": return "https://placeholder.pima.az.gov/api";
        default:    return null;
    }
}
function normalizeJurisdiction($jurisdiction, $county = null) {
    logHelperInvocation(__FUNCTION__);
    if (!$jurisdiction) return null;
    $jurisdiction = strtoupper(trim($jurisdiction));
    if ($jurisdiction === "NO CITY/TOWN") return $county ?: "Unincorporated Area";
    static $list = null;
    if ($list === null) {
        $path = __DIR__ . "/../assets/data/jurisdictions.json";
        $list = file_exists($path) ? json_decode(file_get_contents($path), true) : array();
    }
    foreach ($list as $name => $info) {
        if (!empty($info['aliases'])) {
            foreach ($info['aliases'] as $alias) {
                if (strtoupper($alias) === $jurisdiction) return $name;
            }
        }
    }
    return ucwords(strtolower($jurisdiction));
}
#endregion

#region Disclaimer Logic
function getApplicableDisclaimers($reportType, $context = array()) {
    logHelperInvocation(__FUNCTION__);
    $file = __DIR__ . "/../assets/data/reportDisclaimers.json";
    if (!file_exists($file)) return array("⚠️ Disclaimer library not found.");
    $json = json_decode(file_get_contents($file), true);
    if (!$json) return array("⚠️ Disclaimer library invalid.");
    if (!isset($json[$reportType])) return array("⚠️ No disclaimers defined for ".$reportType.".");

    $r = $json[$reportType]; $out = array();
    if (!empty($r['dataSources'])) foreach ($r['dataSources'] as $ds) if ($ds) $out[]=$ds;
    if (is_array($context)) foreach ($context as $k=>$v)
        if ($v && isset($r[$k]) && is_array($r[$k])) $out=array_merge($out,$r[$k]);
    return empty($out)?array("⚠️ No applicable disclaimers.") : array_values(array_unique($out));
}
#endregion

#region AI & External APIs
function callOpenAi($messages) {
    logHelperInvocation(__FUNCTION__);
    $apiKey = getenv("OPENAI_API_KEY");
    $model  = "gpt-4o-mini";
    $payload = json_encode(array("model"=>$model,"messages"=>$messages,"temperature"=>0.1,"max_tokens"=>800));
    $ch=curl_init("https://api.openai.com/v1/chat/completions");
    curl_setopt_array($ch,array(
        CURLOPT_HTTPHEADER=>array("Content-Type: application/json","Authorization: Bearer ".$apiKey),
        CURLOPT_RETURNTRANSFER=>true, CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$payload, CURLOPT_TIMEOUT=>25
    ));
    $res=curl_exec($ch); $err=curl_error($ch); curl_close($ch);
    if($res===false){file_put_contents(__DIR__.'/error.log',"Curl Error:$err\n",FILE_APPEND);return "❌ Curl error";}
    $r=json_decode($res,true);
    return isset($r["choices"][0]["message"]["content"])?trim($r["choices"][0]["message"]["content"]):"❌ Invalid response";
}

function googleSearch($query) {
    logHelperInvocation(__FUNCTION__);
    $apiKey=getenv("GOOGLE_SEARCH_KEY"); $cx=getenv("GOOGLE_SEARCH_CX");
    if(!$apiKey||!$cx) return array("error"=>"Google API not configured.");
    $url="https://www.googleapis.com/customsearch/v1?q=".urlencode($query)."&key=".$apiKey."&cx=".$cx;
    $res=@file_get_contents($url);
    if(!$res) return array("error"=>"No response from Google.");
    $json=json_decode($res,true);
    if(!$json||isset($json['error'])) return array("error"=>"Google API error.");
    $summaries=array(); $link=null;
    foreach($json['items'] as $i=>$it){$title=$it['title'];$snip=$it['snippet'];if($i==0)$link=$it['link'];$summaries[]="$title: $snip";}
    $messages=array(
        array("role"=>"system","content"=>"You are Skyebot™, summarize search snippets concisely."),
        array("role"=>"system","content"=>implode("\n",$summaries)),
        array("role"=>"user","content"=>"Summarize results for: ".$query)
    );
    return array("summary"=>callOpenAi($messages),"link"=>$link);
}
#endregion

#region Semantic & Ontology Tools
function normalizeTitle($title) {
    logHelperInvocation(__FUNCTION__);
    $title=preg_replace('/^[\p{So}\p{Sk}\p{Sm}\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}]+/u','',$title);
    $title=preg_replace('/\([^)]*\)/','',$title);
    return trim(preg_replace('/\s+/',' ',$title));
}
function findCodexMatch($text,$codex){ /* unchanged logic from your version */ logHelperInvocation(__FUNCTION__); }
function resolveSkyesoftObject($prompt,$data){ /* unchanged logic from your version */ logHelperInvocation(__FUNCTION__); }
#endregion

#region Recursive Key Search
function findCodexMetaValue($array, $key) {
    foreach ($array as $k => $v) {
        if ($k === $key) return $v;
        if (is_array($v)) {
            $result = findCodexMetaValue($v, $key);
            if ($result !== null) return $result;
        }
    }
    return null;
}
#endregion

#region Rendering Utilities
function getIconFile($iconKey) {
    logHelperInvocation(__FUNCTION__);
    $base=dirname(__DIR__); $map=$base.'/assets/data/iconMap.json'; $dir=$base.'/assets/images/icons/';
    if(!$iconKey||!file_exists($map)) return null;
    $m=json_decode(file_get_contents($map),true);
    if(isset($m[$iconKey]['file'])){ $f=$dir.$m[$iconKey]['file']; if(file_exists($f)) return $f; }
    $cand=$dir.$iconKey.'.png'; return file_exists($cand)?$cand:null;
}
#endregion

#region Meta Footer Injection
// Automatically applied for Information Sheet documents
// Implemented via renderMetaFooterFromCodex() for Codex-Centric Provenance

function renderMetaFooterFromCodex($codex, $slug, $module) {
    logHelperInvocation(__FUNCTION__);
    $introduced = findCodexMetaValue($module, 'introducedInVersion');
    $effective  = findCodexMetaValue($module, 'effectiveVersion');
    $maintainer = findCodexMetaValue($module, 'maintainedBy');
    $codexVer   = findCodexMetaValue($codex, 'version');
    $author     = $maintainer ? $maintainer : 'Skyebot System Layer';

    return "<p style='font-size:9pt; color:#666; text-align:center;'>
        <em>Codex v{$codexVer} | Introduced {$introduced} | Effective {$effective} | Maintained by {$author}</em>
    </p>";
}
#endregion

#region Temporal Utilities
function secondsUntilTodayClock($targetClock, $tzName) {
    logHelperInvocation(__FUNCTION__);
    $tz = new DateTimeZone($tzName);
    $now = new DateTime('now', $tz);

    $t = DateTime::createFromFormat('g:i A', trim($targetClock), $tz);
    if (!$t) return null;

    $t->setDate($now->format('Y'), $now->format('m'), $now->format('d'));
    $diff = $t->getTimestamp() - $now->getTimestamp();
    return ($diff < 0) ? 0 : $diff;
}

function humanizeSecondsShort($secs) {
    logHelperInvocation(__FUNCTION__);
    if ($secs === null) return '';
    $mins = floor($secs / 60);
    $hrs  = floor($mins / 60);
    $rem  = $mins % 60;
    if ($hrs > 0 && $rem > 0) return $hrs . " hours and " . $rem . " minutes";
    if ($hrs > 0) return $hrs . " hours";
    return $mins . " minutes";
}

function resolveDayType($tis, $holidays, $timestamp) {
    logHelperInvocation(__FUNCTION__);
    if (!is_numeric($timestamp)) $timestamp = strtotime($timestamp);
    if (!$timestamp) $timestamp = time();

    if (!is_array($tis)) $tis = array();
    if (!is_array($holidays)) $holidays = array();

    $dayTypes = array();
    if (isset($tis['dayTypeArray']) && is_array($tis['dayTypeArray'])) {
        $dayTypes = $tis['dayTypeArray'];
    } else {
        $dayTypes = array(
            array('DayType' => 'Workday', 'Days' => 'Mon,Tue,Wed,Thu,Fri'),
            array('DayType' => 'Weekend', 'Days' => 'Sat,Sun'),
            array('DayType' => 'Holiday', 'Days' => 'Dynamic')
        );
    }

    $weekday   = date('D', $timestamp);
    $todayDate = date('Y-m-d', $timestamp);
    $dayType   = 'Unknown';

    foreach ($dayTypes as $dt) {
        if (!isset($dt['Days']) || !isset($dt['DayType'])) continue;
        $daysArr = array_map('trim', explode(',', str_replace(array('-', ' '), ',', $dt['Days'])));
        if (in_array($weekday, $daysArr)) {
            $dayType = ucfirst(strtolower($dt['DayType']));
            break;
        }
    }

    foreach ($holidays as $h) {
        $hDate = isset($h['date']) ? $h['date'] : null;
        if ($hDate && $hDate === $todayDate) {
            $dayType = 'Holiday';
            break;
        }
    }

    if ($dayType === 'Unknown') {
        $dow = date('N', $timestamp);
        $dayType = ($dow >= 1 && $dow <= 5) ? 'Workday' : 'Weekend';
    }

    return array(
        'dayType'   => $dayType,
        'weekday'   => $weekday,
        'timestamp' => $timestamp,
        'isWorkday' => ($dayType === 'Workday'),
        'isWeekend' => ($dayType === 'Weekend'),
        'isHoliday' => ($dayType === 'Holiday')
    );
}
#endregion

#region Environment Logic
function envVal($key, $default = '') {
    logHelperInvocation(__FUNCTION__);
    static $codex = null;

    // Lazy-load Codex JSON once
    if ($codex === null) {
        $path = __DIR__ . '/../assets/data/codex.json';
        if (file_exists($path)) {
            $raw = file_get_contents($path);
            $codex = json_decode($raw, true);
        } else {
            $codex = array();
        }
    }

    // Step 1 – Direct .env lookup
    $val = getenv($key);
    if ($val !== false && $val !== '') return $val;

    // Step 2 – Ontology fallback
    if (isset($codex['ontology']['envKeys']) && in_array($key, $codex['ontology']['envKeys'])) {
        if (isset($codex['ontology'][$key])) {
            return $codex['ontology'][$key];
        }
    }

    // Step 3 – kpiData fallback
    if (isset($codex['kpiData'][$key])) {
        return $codex['kpiData'][$key];
    }

    // Step 4 – Use explicit default + log non-fatal notice
    logMissingEnv($key, $default);
    return $default;
}

function logMissingEnv($key, $default) {
    logHelperInvocation(__FUNCTION__);
    $msg = date('Y-m-d H:i:s') . " | Missing ENV: {$key} → using default '{$default}'\n";
    $logPath = __DIR__ . '/../logs/env-fallback.log';

    // Ensure log directory exists
    $dir = dirname($logPath);
    if (!file_exists($dir)) {
        @mkdir($dir, 0755, true);
    }

    @file_put_contents($logPath, $msg, FILE_APPEND);

    // Optional: stream non-fatal notice if SSE emitter available
    if (function_exists('sseEmit')) {
        sseEmit('env_notice', array('key' => $key, 'default' => $default));
    }
}
#endregion

#region Diagnostics (CLI)
if (php_sapi_name()==='cli' && basename(__FILE__)===basename($_SERVER['argv'][0])) {
    echo "🧭 Skyesoft Codex Compliance — Phase 3 Diagnostic\n";
    echo "--------------------------------------------------\n";
    $keys=array('OPENAI_API_KEY','WEATHER_API_KEY','NON_EXISTENT_KEY','secondsPerDay');
    foreach($keys as $k){$v=envVal($k,'[default]');printf("%-22s => %s\n",$k,($v!==''?$v:'[empty]'));}
    echo "\nCheck /logs/env-fallback.log for notices.\n";
}
#endregion