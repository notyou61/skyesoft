<?php
// ======================================================================
//  FILE: pdf_framework.php
//  PURPOSE: Skyesoft™ Document Framework (Header, Body, Footer)
//  VERSION: v2.3.1 (Slug-Deterministic Codex Edition, Stable Footer)
//  AUTHOR: CPAP-01 Parliamentarian Integration
//  PHP 5.6 Compatible
// ======================================================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

// --------------------------------------------------
//  REGION: DEPENDENCIES
// --------------------------------------------------
require_once(__DIR__ . '/../../libs/tcpdf/tcpdf.php');

// --------------------------------------------------
//  REGION: SKYESOFT PDF CLASS
// --------------------------------------------------
class SkyesoftPDF extends TCPDF {

    public $docTitle    = '📄 Untitled Document';
    public $docType     = 'Skyesoft™ Information Sheet';
    public $generatedAt = '';
    public $metaFooter  = '© Christy Signs / Skyesoft, All Rights Reserved | 3145 N 33rd Ave, Phoenix AZ 85017 | (602) 242-4488 | christysigns.com';
    public $codexMeta   = array();
    private $root       = '';

    public function __construct($orientation='P',$unit='mm',$format='Letter',$unicode=true,$encoding='UTF-8',$diskcache=false){
        parent::__construct($orientation,$unit,$format,$unicode,$encoding,$diskcache);
        $this->root = dirname(__DIR__,2);
    }

    public function bindContext($ctx=array()){
        foreach($ctx as $k=>$v){
            if(property_exists($this,$k)) $this->$k=$v;
        }
    }

    // --------------------------------------------------
    //  HEADER
    // --------------------------------------------------
    public function Header(){
        $logoPath    = $this->root.'/assets/images/christyLogo.png';
        $iconMapPath = $this->root.'/assets/data/iconMap.json';
        $iconBase    = $this->root.'/assets/images/icons/';

        $yBase=8; $logoW=36; $gap=9; $textW=120;
        $pageW=$this->getPageWidth()-$this->lMargin-$this->rMargin;
        $xOffset=($pageW-($logoW+$gap+$textW))/2+$this->lMargin;

        if(file_exists($logoPath))
            $this->Image($logoPath,$xOffset,$yBase,$logoW,0,'PNG');

        $rawTitle=$this->docTitle;
        $emoji=function_exists('mb_substr')?mb_substr($rawTitle,0,2,'UTF-8'):substr($rawTitle,0,2);
        $titleText=trim(preg_replace('/^\X\s*/u','',$rawTitle));

        $iconFile=null;
        if(file_exists($iconMapPath)){
            $map=json_decode(@file_get_contents($iconMapPath),true);
            foreach($map as $entry){
                if(isset($entry['icon'])&&$entry['icon']===$emoji&&isset($entry['file'])){
                    $candidate=$iconBase.$entry['file'];
                    if(file_exists($candidate)) $iconFile=$candidate;
                }
            }
        }

        $textX=$xOffset+$logoW+$gap;
        $titleY=$yBase+1.5;

        if($iconFile){
            $this->Image($iconFile,$textX,$titleY+0.3,6.5,6.5,'','','',true);
            $textX+=8.5;
        }

        $this->SetFont('helvetica','B',12.5);
        $this->SetTextColor(0,0,0);
        $this->SetXY($textX,$titleY);
        $this->Cell(0,5.5,$titleText,0,1,'L');

        $this->SetFont('helvetica','',9);
        $this->SetTextColor(80,80,80);
        $this->SetXY($xOffset+$logoW+$gap,$titleY+5.3);
        $this->Cell(0,4.5,$this->docType,0,1,'L');

        $this->SetFont('helvetica','',7.8);
        $this->SetTextColor(110,110,110);
        $this->SetXY($xOffset+$logoW+$gap,$titleY+10.2);
        $this->Cell(0,4,'Generated '.$this->generatedAt,0,1,'L');

        $this->SetLineWidth(0.4);
        $this->Line($this->lMargin,$yBase+20,$this->getPageWidth()-$this->rMargin,$yBase+20);
    }

    // --------------------------------------------------
    //  FOOTER
    // --------------------------------------------------
    public function Footer(){
        // Parliamentarian §3.3.4 – Page Number Disclosure
        $this->SetY(-15.5);
        $this->SetFont('helvetica','',8);
        $this->SetTextColor(80,80,80);

        $left=$this->lMargin;
        $right=$this->getPageWidth()-$this->rMargin;
        $this->Line($left,$this->GetY(),$right,$this->GetY());
        $this->Ln(2.2);

        $codexVer=isset($this->codexMeta['version'])?' • Codex '.$this->codexMeta['version']:'';
        $reviewer=isset($this->codexMeta['reviewedBy'])?' • Reviewed '.$this->codexMeta['reviewedBy']:'';

        $footerText='© Christy Signs / Skyesoft, All Rights Reserved | '.
            '3145 N 33rd Ave, Phoenix AZ 85017 | (602) 242-4488 | christysigns.com'.
            $codexVer.$reviewer.
            ' | Page '.$this->getAliasNumPage().'/'.$this->getAliasNbPages();

        $tempText=str_replace(array($this->getAliasNumPage(),$this->getAliasNbPages()),array('999','999'),$footerText);
        $printableWidth=$this->getPageWidth()-$this->lMargin-$this->rMargin;
        $textWidth=$this->GetStringWidth($tempText,'helvetica','',8);
        $x=round($this->lMargin+(($printableWidth-$textWidth)/2),2);

        $this->SetX($x);
        $this->Cell($textWidth,6,$footerText,0,0,'L');
    }
}

// --------------------------------------------------
//  REGION: DETERMINE SLUG
// --------------------------------------------------
$slug=null;
if(php_sapi_name()==='cli'){
    foreach($argv as $arg){
        if(strpos($arg,'slug=')===0) $slug=trim(substr($arg,5));
    }
}elseif(isset($_GET['slug'])){
    $slug=$_GET['slug'];
}
if(!$slug){
    echo "❌ Missing slug parameter. Example: php -f api/core/pdf_framework.php slug=documentStandards\n";
    exit;
}

// --------------------------------------------------
//  REGION: LOAD CODEX + MODULE
// --------------------------------------------------
$root=dirname(__DIR__,2);
$codexPath=$root.'/assets/data/codex.json';
if(!file_exists($codexPath)){
    echo "❌ Codex not found: $codexPath\n"; exit;
}
$codex=json_decode(file_get_contents($codexPath),true);
if(!isset($codex[$slug])){
    echo "❌ Module '$slug' not found in Codex.\n"; exit;
}
$module=$codex[$slug];
$docTitle=isset($module['title'])?$module['title']:ucfirst($slug);
$docType=isset($module['type'])?'Skyesoft™ '.ucfirst($module['type']):'Skyesoft™ Document';
$family=isset($module['family'])?$module['family']:'general';
$codexMeta=array(
    'version'=>isset($codex['documentStandards']['effectiveVersion'])?$codex['documentStandards']['effectiveVersion']:'vUnknown',
    'reviewedBy'=>isset($codex['documentStandards']['issuedBy'])?$codex['documentStandards']['issuedBy']:'Unverified'
);

// --------------------------------------------------
//  REGION: DOCUMENT INITIALIZATION
// --------------------------------------------------
$pdf=new SkyesoftPDF('P','mm','Letter',true,'UTF-8',false);
$pdf->setPrintFooter(false); // 🔹 disable TCPDF’s built-in footer globally

$pdf->bindContext(array(
    'docTitle'=>$docTitle,
    'docType'=>$docType,
    'generatedAt'=>date('F j, Y • g:i A'),
    'codexMeta'=>$codexMeta
));

$pdf->SetCreator('Skyesoft™ Codex Engine');
$pdf->SetAuthor('Skyebot™ System Layer');
$pdf->SetTitle($docTitle);
$pdf->SetMargins(15,38,15);
$pdf->SetHeaderMargin(8);
$pdf->SetFooterMargin(12);
$pdf->SetAutoPageBreak(true,20);

// --------------------------------------------------
//  REGION: BODY CONTENT PLACEHOLDER
// --------------------------------------------------
$pdf->AddPage();
$pdf->SetFont('helvetica','',10.5);

$body=<<<EOD
<p><strong>$docTitle</strong> is a {$family}-class document defined under the <em>{$docType}</em> family.</p>
<p>This output was generated dynamically from the Codex entry for <code>$slug</code>
and inherits its doctrinal lineage and metadata automatically.</p>
EOD;
$pdf->writeHTML($body,true,false,true,false,'');

// --------------------------------------------------
//  REGION: OUTPUT
// --------------------------------------------------
$saveDir=$root.'/docs/sheets/';
if(!is_dir($saveDir)){ $old=umask(0); mkdir($saveDir,0777,true); umask($old); }
$savePath=$saveDir.'skyesoft_framework_'.$slug.'_v2.3.1.pdf';
$pdf->Output($savePath,'F');

echo "✅ Skyesoft v2.3.1 Framework saved to: $savePath\n";
echo "🪶 Generated for module: $slug ({$docType})\n";