<?php
// ======================================================================
//  FILE: generateDocuments_v2.1.php
//  PURPOSE: Skyesoft™ Codex-Driven Document Generator (refined visuals)
//  VERSION: v2.1.0  (PHP 5.6 Compatible)
//  AUTHOR: CPAP-01 Parliamentarian Integration
// ======================================================================

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

// ----------------------------------------------------------------------
//  STEP 0 – Load Environment (.env) (expanded path coverage)
// ----------------------------------------------------------------------
$envPaths = array(
    __DIR__ . '/.env',
    __DIR__ . '/../.env',
    '/home/notyou64/.env',
    '/home/notyou64/secure/.env',
    '/home/notyou64/public_html/skyesoft/.env'
);
foreach ($envPaths as $envFile) {
    if (!file_exists($envFile)) continue;
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = str_replace("\r", '', trim($line));
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $k = trim($k); $v = trim($v);
        putenv($k . '=' . $v);
        $_ENV[$k] = $v; $_SERVER[$k] = $v;
    }
    break;
}

// ----------------------------------------------------------------------
//  STEP 1 – Input validation (slug required)
// ----------------------------------------------------------------------
$isCli = (php_sapi_name() === 'cli');
$input = $isCli ? array('slug' => isset($argv[1]) ? trim($argv[1]) : '') : array_merge($_POST, $_GET);
if (!isset($input['slug']) || trim($input['slug']) === '') {
    http_response_code(400);
    echo json_encode(array('error'=>'Missing slug parameter.')); exit;
}
$slug = preg_replace('/[^a-zA-Z0-9_\-]/', '', $input['slug']);

// ----------------------------------------------------------------------
//  STEP 2 – Locate root + codex + TCPDF
// ----------------------------------------------------------------------
$root = realpath(dirname(__DIR__));
$codexPath = $root.'/assets/data/codex.json';
$tcpdfFlat = $root.'/libs/tcpdf.php';
$tcpdfNested = $root.'/libs/tcpdf/tcpdf.php';
$tcpdfPath = file_exists($tcpdfFlat) ? $tcpdfFlat : $tcpdfNested;
if(!file_exists($codexPath)||!file_exists($tcpdfPath)){
    http_response_code(500);
    echo json_encode(array('error'=>'Missing core files.')); exit;
}
require_once($tcpdfPath);
$codex=json_decode(file_get_contents($codexPath),true);
if(!isset($codex[$slug])){http_response_code(404);echo json_encode(array('error'=>"Slug $slug not found."));exit;}
$module=$codex[$slug];

// ----------------------------------------------------------------------
//  STEP 3 – OpenAI enrichment
// ----------------------------------------------------------------------
$apiKey=getenv('OPENAI_API_KEY');
function getAIEnrichment($prompt,$apiKey){
 if(!$apiKey) return '[AI enrichment unavailable – API key not set]';
 $url='https://api.openai.com/v1/chat/completions';
 $data=array('model'=>'gpt-3.5-turbo','messages'=>array(array('role'=>'user','content'=>$prompt)),'max_tokens'=>250,'temperature'=>0.7);
 $ch=curl_init($url);
 curl_setopt($ch,CURLOPT_RETURNTRANSFER,true);
 curl_setopt($ch,CURLOPT_POST,true);
 curl_setopt($ch,CURLOPT_POSTFIELDS,json_encode($data));
 curl_setopt($ch,CURLOPT_HTTPHEADER,array('Content-Type: application/json','Authorization: Bearer '.$apiKey));
 curl_setopt($ch,CURLOPT_TIMEOUT,25);
 $resp=curl_exec($ch);$err=curl_error($ch);$code=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
 if($err||$code!==200)return '[AI enrichment failed: '.($err?$err:$code).']';
 $decoded=json_decode($resp,true);
 return isset($decoded['choices'][0]['message']['content'])?trim($decoded['choices'][0]['message']['content']):'[AI response empty]';
}

// ----------------------------------------------------------------------
//  STEP 4 – PDF class refinements
// ----------------------------------------------------------------------
class SkyesoftPDF extends TCPDF{
 public $docTitle='Untitled';public $docType='Skyesoft™ Document';public $generatedAt='';private $root='';
 function __construct($root){parent::__construct('P','mm','Letter',true,'UTF-8',false);$this->root=$root;}
 function Header(){
  $logo=$this->root.'/assets/images/christyLogo.png';
  $yBase=8;$logoW=36;$gap=3;$x=$this->lMargin;
  if(file_exists($logo))$this->Image($logo,$x,$yBase,$logoW,0,'PNG');
  $logoH=$logoW/3.5;$textY=$yBase+($logoH/2)-4;$textX=$x+$logoW+$gap;
  $t=trim(preg_replace('/^[^\p{L}\p{N}\(]+/u','',$this->docTitle));
  $this->SetFont('helvetica','B',12.5);$this->SetXY($textX,$textY);$this->Cell(0,5.5,$t,0,1,'L');
  $this->SetFont('helvetica','',8.5);$this->SetXY($textX,$textY+5);$this->Cell(0,4.5,$this->docType,0,1,'L');
  $this->SetFont('helvetica','',7.5);$this->SetXY($textX,$textY+10);$this->Cell(0,4,'Generated '.$this->generatedAt,0,1,'R');
  $this->SetLineWidth(0.3);$this->Line($this->lMargin,$yBase+$logoH+4,$this->getPageWidth()-$this->rMargin,$yBase+$logoH+4);
 }
 function Footer(){
  $this->SetY(-15.5);$this->SetFont('helvetica','',8);$this->SetTextColor(80,80,80);
  $left=$this->lMargin;$right=$this->getPageWidth()-$this->rMargin;
  $this->SetLineWidth(0.25);$this->Line($left,$this->GetY(),$right,$this->GetY());
  $this->Ln(3.0);
  $txt=$this->docTitle.' • © Christy Signs / Skyesoft | Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages();
  $this->Cell(0,6,$txt,0,0,'C');
 }
}

// ----------------------------------------------------------------------
//  STEP 5 – File name cleanup (prevent duplicate suffixes)
// ----------------------------------------------------------------------
function buildFileName($module,$cleanTitle,$displayLabel){
 $type=isset($module['type'])?strtolower($module['type']):'document';
 $map=array('directive'=>'Directive','audit'=>'Audit','report'=>'Report','sheet'=>'Information Sheet');
 $suffix=isset($map[$type])?$map[$type]:ucfirst($displayLabel);
 if(stripos($cleanTitle,$suffix)!==false)$suffix='';
 return 'Skyesoft – '.$cleanTitle.($suffix?' '.$suffix:'').'.pdf';
}

// ----------------------------------------------------------------------
//  STEP 6 – Minor cosmetic tweaks inside body builder
// ----------------------------------------------------------------------
function tidyTableHTML($html){return str_replace('<table','<table style="margin-top:3mm;margin-bottom:3mm;"',$html);}
function grayMetaKey($label){return '<td width="35%" style="color:#555;"><strong>'.$label.'</strong></td>';}

// (Reuse your existing buildBodyFromCodex function here; no logic change needed,
// only replace its <table> builders and <td> label lines with the helper tweaks above.)

// ----------------------------------------------------------------------
//  STEP 7 – Generate document
// ----------------------------------------------------------------------
$pdf=new SkyesoftPDF($root);
$pdf->setPrintHeader(true);$pdf->setPrintFooter(true);
$pdf->SetMargins(15,30,15);$pdf->SetHeaderMargin(8);$pdf->SetFooterMargin(12);
$pdf->SetAutoPageBreak(true,20);
$pdf->docTitle=$module['title'];$pdf->docType='Skyesoft™ Directive';$pdf->generatedAt=date('F j, Y • g:i A');
$pdf->AddPage();$pdf->SetFont('helvetica','',10.5);

// --- Run body builder (assumes existing function) ---
if(function_exists('buildBodyFromCodex')){buildBodyFromCodex($module,getAIEnrichment('Summarize '.$slug,$apiKey),$pdf);}

// Save file
$saveDir=$root.'/documents/';if(!is_dir($saveDir)){mkdir($saveDir,0777,true);}
$saveName=buildFileName($module,$module['title'],'Document');$savePath=$saveDir.$saveName;
$pdf->Output($savePath,'F');

// ----------------------------------------------------------------------
//  STEP 8 – JSON response
// ----------------------------------------------------------------------
echo json_encode(array(
 'status'=>'success',
 'slug'=>$slug,
 'fileName'=>basename($saveName),
 'path'=>str_replace($root,'',$savePath),
 'message'=>'✅ Document generated successfully.',
 'version'=>'v2.1.0'
));
exit;
?>
