<?php
/**
 * Stub for TCPDF to suppress Intelephense false errors
 * Location: .vscode/tcpdf.stub.php
 * Not loaded at runtime — IDE only
 */
class TCPDF
{
    public function __construct() {}
    public function AddPage() {}
    public function SetFont($family, $style = '', $size = null) {}
    public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0) {}
    public function MultiCell() {}
    public function Ln($h = null) {}
    public function Image() {}
    public function SetXY() {}
    public function SetX() {}
    public function SetY() {}
    public function GetX() {}
    public function GetY() {}
    public function SetTextColor() {}
    public function SetFillColor() {}
    public function SetDrawColor() {}
    public function Line() {}
    public function Output($name = '', $dest = '') {}
    public function getNumPages() {}
    public function getPageWidth() {}
    public function getPageHeight() {}
    public function getMargins() {}
    public function SetCreator($creator) {}
    public function SetAuthor($author) {}
    public function SetMargins($left, $top, $right = -1, $keepmargins = false) {}
    public function SetAutoPageBreak($auto, $margin = 0) {}
    public function SetHeaderMargin($margin) {}
    public function SetFooterMargin($margin) {}
    public function deletePage($page = null) {}
}
