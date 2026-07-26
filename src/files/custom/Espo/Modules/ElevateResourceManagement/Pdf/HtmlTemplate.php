<?php

namespace Espo\Modules\ElevateResourceManagement\Pdf;

use Espo\Tools\Pdf\Template;

final class HtmlTemplate implements Template
{
    public function __construct(private string $body, private string $title) {}
    public function getFontFace(): ?string { return null; }
    public function getBottomMargin(): float { return 15; }
    public function getTopMargin(): float { return 15; }
    public function getLeftMargin(): float { return 15; }
    public function getRightMargin(): float { return 15; }
    public function hasFooter(): bool { return true; }
    public function getFooter(): string { return '<div style="text-align:right">Page <span class="page-number"></span></div>'; }
    public function getFooterPosition(): float { return 8; }
    public function hasHeader(): bool { return false; }
    public function getHeader(): string { return ''; }
    public function getHeaderPosition(): float { return 8; }
    public function getBody(): string { return $this->body; }
    public function getPageOrientation(): string { return Template::PAGE_ORIENTATION_PORTRAIT; }
    public function getPageFormat(): string { return 'A4'; }
    public function getPageWidth(): float { return 210; }
    public function getPageHeight(): float { return 297; }
    public function hasTitle(): bool { return true; }
    public function getTitle(): string { return $this->title; }
    public function getStyle(): string
    {
        return 'body{font-family:DejaVu Sans,sans-serif;color:#222}h1{font-size:20px}.block{margin:14px 0;padding:10px;border:1px solid #bbb}.meta{color:#555}ul{margin-top:5px}.totals{margin-top:18px;font-weight:bold}';
    }
}
