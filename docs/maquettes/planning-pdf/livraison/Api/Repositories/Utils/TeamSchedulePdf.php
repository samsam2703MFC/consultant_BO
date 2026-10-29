<?php
namespace App\Api\Repositories\Utils;
use App\Api\Services\Employee\TeamSchedulePdfService;
/**
 * Team schedule, one A4 landscape sheet per week, in the L'Atelier by design system (global.css / marque.css):
 * Vank title, Gotham Light/Medium text, Rubis accents, beige-doux bands, warm hairlines, rounded rectangles.
 * Hours run left to right; each day is its own card, split by section on the whole-shop sheet,
 * and grows with the number of shifts side by side.
 * Fonts and logo are read from ASSETS; without them the sheet falls back to DejaVu and stays complete.
 */
final class TeamSchedulePdf extends \TCPDF
{
    private const ASSETS=__DIR__.'/../../../assets/schedule_pdf/';
    private const RUBIS=[141,29,44],INK=[34,34,34],MUTED=[102,102,102],SOFT=[139,129,119],BAND=[244,239,232],WEEKEND=[250,247,243],FRAME=[222,214,201],HOUR=[232,226,217],HALF=[244,240,235],WHITE=[255,255,255];
    /** Border and fill per department, in repository order. A single department stays Rubis; an unknown one is neutral. */
    private const PALETTE=[[[141,29,44],[247,234,236]],[[120,85,75],[242,236,233]],[[193,122,42],[251,240,226]],[[47,110,165],[232,240,247]],[[45,122,62],[230,241,232]],[[154,122,18],[248,241,215]],[[34,34,34],[236,236,236]]];
    private const NEUTRAL=[[154,148,140],[243,241,238]];
    /** Section key of the shifts whose department is not in the list. */
    private const OTHER=-1;
    // Geometry (mm): page frame, hour band top/height, last card bottom, gap between days, day / section / totals
    // columns, smallest day, tallest shift, corner radius of the cards.
    private const X=10,W=277,TOP=29,HEAD=7,BOTTOM=196.5,GAP=1.8,DAYS=23,SECTIONS=24,TOTALS=20,ROW=9,BAR=11,RADIUS=1.8;
    private array $copy;private string $decimal;private string $shop='';private string $footer='';private int $sheets=1;
    private array $typefaces=['display'=>['dejavusans','B'],'medium'=>['dejavusans','B'],'light'=>['dejavusans','']];
    public function __construct(private array $plan,string $language)
    {
        parent::__construct('L','mm','A4',true,'UTF-8',false);
        $language=in_array($language,['pl','fr','nl','en','it'],true)?$language:'fr';
        $this->copy=json_decode(file_get_contents(__DIR__.'/../../../lang/'.$language.'/schedule_pdf.json'),true,512,JSON_THROW_ON_ERROR)+['hour_unit'=>'h','generated'=>'','week'=>''];
        $this->decimal=$language==='en'?'.':',';
        foreach(['display'=>'vank','medium'=>'gothammedium','light'=>'gothamlight'] as $role=>$file)if(is_file(self::ASSETS.$file.'.php')&&$this->AddFont($file,'',self::ASSETS.$file.'.php'))$this->typefaces[$role]=[$file,''];
        $this->tcpdflink=false;
        $this->setPrintHeader(false);$this->setPrintFooter(true);$this->SetMargins(self::X,10,self::X);$this->SetAutoPageBreak(false);$this->setFontSubsetting(true);
        $this->SetTitle($this->copy['title']);$this->SetCreator("L'Atelier by");
        $this->font('light',8); // so pages open on a brand font, not TCPDF's default Helvetica
    }
    public function render(): void
    {
        $p=$this->plan;$this->shop=(string)($p['shop']['representative_name']?:$p['shop']['name']);
        $colors=[];$departments=$p['departments']??[];$i=0;
        foreach($departments as $id=>$area)$colors[$id]=count($departments)>1?self::PALETTE[$i++%count(self::PALETTE)]:self::PALETTE[0];
        // The whole-shop sheet groups each day by section, in repository order, the shifts without one last.
        $sections=$p['layout']==='shop'&&count($departments)>1?$departments+[self::OTHER=>$this->copy['unassigned']]:[];
        // One sheet per week (the service cuts them); a plan without weeks is a single sheet.
        $weeks=$p['weeks']??[['dates'=>$p['dates'],'minimum'=>$p['minimum'],'maximum'=>$p['maximum']]];
        $this->sheets=count($weeks)*count($p['pages']);
        foreach($weeks as $week)foreach($p['pages'] as $page){
            $name=$p['layout']==='shop'?$this->copy['whole_shop']:(!empty($page['unassigned'])?$this->copy['unassigned']:$page['name']);
            $events=array_values(array_filter($page['events'],fn($e)=>in_array($e['date'],$week['dates'],true)));
            $this->AddPage();$this->setCellPaddings(0,0,0,0);
            // Set after AddPage: TCPDF draws the previous sheet's footer inside AddPage.
            $this->footer=$this->copy['title'].' · '.$name;
            $this->head($name,$week['dates'],$events,$sections,$colors);
            $this->grid($week,$events,$colors,$sections);
        }
    }
    /** Vank title with the page in Rubis, shop and week below; on the right the logo, then week, head count, hours and sections. */
    private function head(string $name,array $dates,array $events,array $sections,array $colors): void
    {
        $x=$this->display($this->copy['title'],self::X,18.2,25,self::INK);
        $this->display($name,$x+3.2,18.2,25,self::RUBIS);
        $x=$this->put('medium',8.5,self::INK,$this->shop,self::X,24.6);
        $limit=$this->put('light',8.5,self::MUTED,date('d.m.Y',strtotime($dates[0])).' – '.date('d.m.Y',strtotime(end($dates))),$x+2.4,24.6)+8;
        if(is_file(self::ASSETS.'logo.png'))$this->Image(self::ASSETS.'logo.png',0,10.6,0,6.2,'PNG','','',true,300,'R');
        $tags=[];
        if($this->copy['week']!==''){$a=(int)date('W',strtotime($dates[0]));$b=(int)date('W',strtotime(end($dates)));$tags[]=[$this->copy['week'].' '.($a===$b?$a:$a.'–'.$b),self::RUBIS,self::WHITE,null,null,''];}
        $tags[]=[(string)count(array_unique(array_column($events,'employee_id'))),self::BAND,self::INK,'person',null,''];
        $tags[]=[$this->hours(array_sum(array_map(fn($e)=>$e['end']-$e['start'],$events))),self::BAND,self::INK,'clock',null,''];
        $minutes=[];foreach($events as $e){$key=$this->section($e,$sections);$minutes[$key]=($minutes[$key]??0)+$e['end']-$e['start'];}
        foreach($sections as $key=>$label)if(isset($minutes[$key]))$tags[]=[$label,self::BAND,self::INK,null,$colors[$key]??self::NEUTRAL,$this->hours($minutes[$key])];
        // Right-aligned under the logo; section tags drop from the end before they would reach the subtitle.
        do{$width=0;foreach($tags as $t)$width+=$this->tagWidth($t[0],$t[3]||$t[4],$t[5])+1.6;}while(self::X+self::W-$width+1.6<$limit&&count($tags)>3&&array_pop($tags));
        $x=self::X+self::W-$width+1.6;
        foreach($tags as [$strong,$fill,$color,$icon,$swatch,$soft])$x=$this->tag($x,21.2,$strong,$fill,$color,$icon,$swatch,$soft);
    }
    private function grid(array $week,array $events,array $colors,array $sections): void
    {
        $min=$week['minimum'];$max=$week['maximum'];$split=$sections!==[];
        $x0=self::X+self::DAYS+($split?self::SECTIONS:0);$x1=self::X+self::W-self::TOTALS;$scale=($x1-$x0)/($max-$min);
        // Each day: its sections (only those working that day), each with its own lanes.
        $days=[];
        foreach($week['dates'] as $date){
            $today=array_values(array_filter($events,fn($e)=>$e['date']===$date));$groups=[];
            foreach($split?$sections:[null=>''] as $key=>$label){
                $part=$split?array_values(array_filter($today,fn($e)=>$this->section($e,$sections)===$key)):$today;
                if($part||!$split)$groups[]=['key'=>$key,'label'=>$label,'lanes'=>TeamSchedulePdfService::lanes($part)];
            }
            if(!$groups)$groups[]=['key'=>null,'label'=>'','lanes'=>TeamSchedulePdfService::lanes([])];
            $days[]=['date'=>$date,'events'=>$today,'groups'=>$groups,'units'=>array_sum(array_map(fn($g)=>$g['lanes']['count'],$groups))];
        }
        $y=self::TOP+self::HEAD+self::GAP;
        $heights=$this->rows(array_column($days,'units'),self::BOTTOM-$y-self::GAP*(count($days)-1));
        // The hour band: its own rounded rectangle above the days, a tick under each hour.
        $this->RoundedRect(self::X,self::TOP,self::W,self::HEAD,self::RADIUS,'1111','F',[],self::BAND);
        $this->spaced('medium',5.4,self::SOFT,mb_strtoupper($this->copy['time']),self::X+3,self::TOP+4.4,$x0-self::X-4,'L');
        $this->SetDrawColorArray(self::FRAME);$this->SetLineWidth(.16);
        for($minute=(int)(ceil($min/60)*60);$minute<=$max;$minute+=60){
            $x=$x0+($minute-$min)*$scale;$this->Line($x,self::TOP+self::HEAD-1.3,$x,self::TOP+self::HEAD);
            if($minute<$max)$this->put('light',6.4,self::MUTED,self::clock($minute),$x+.9,self::TOP+4.5);
        }
        foreach($days as $i=>$day){
            $h=$heights[$i];$n=(int)date('N',strtotime($day['date']));
            // The day card: white (weekend faintly tinted), totals on a beige-doux strip at its right.
            $this->RoundedRect(self::X,$y,self::W,$h,self::RADIUS,'1111','F',[],$n>=6?self::WEEKEND:self::WHITE);
            $this->RoundedRect($x1,$y,self::TOTALS,$h,self::RADIUS,'1100','F',[],self::BAND);
            for($minute=$min+30;$minute<$max;$minute+=30){
                $x=$x0+($minute-$min)*$scale;$hour=$minute%60===0;
                $this->SetDrawColorArray($hour?self::HOUR:self::HALF);$this->SetLineWidth($hour?.16:.1);$this->Line($x,$y,$x,$y+$h);
            }
            $this->SetDrawColorArray(self::FRAME);$this->SetLineWidth(.16);$this->Line($x0,$y,$x0,$y+$h);$this->Line($x1,$y,$x1,$y+$h);
            $this->spaced('medium',6.2,$n>=6?self::RUBIS:self::SOFT,mb_strtoupper($this->copy['weekdays'][$n-1]),self::X+3,$y+$h/2-.9,self::DAYS-4.5,'L');
            $this->display(date('d.m',strtotime($day['date'])),self::X+3,$y+$h/2+3.6,12.5,self::INK);
            $this->total($x1,$y,$h,count(array_unique(array_column($day['events'],'employee_id'))),array_sum(array_map(fn($e)=>$e['end']-$e['start'],$day['events'])));
            $top=$y;
            foreach($day['groups'] as $j=>$group){
                $gh=$h*$group['lanes']['count']/$day['units'];
                if($j>0){$this->SetDrawColorArray(self::HOUR);$this->SetLineWidth(.16);$this->Line(self::X+self::DAYS,$top,$x1,$top);}
                if($split)$this->sectionTag(self::X+self::DAYS,$top,$gh,$group['label'],$colors[$group['key']]??self::NEUTRAL);
                // A department sheet, or a shop without sections: Rubis, unless the shift's department is unknown.
                foreach($group['lanes']['events'] as $e)$this->shift($e,$x0,$scale,$min,$top,$gh,$split?$colors[$group['key']]??self::NEUTRAL:$colors[$e['department_id']]??($colors?self::NEUTRAL:self::PALETTE[0]));
                $top+=$gh;
            }
            $this->RoundedRect(self::X,$y,self::W,$h,self::RADIUS,'1111','D',['width'=>.22,'color'=>self::FRAME]);
            $y+=$h+self::GAP;
        }
    }
    /** The section a shift is filed under: its department when listed, OTHER otherwise. */
    private function section(array $e,array $listed): int {return isset($listed[$e['department_id']])&&$e['department_id']!==self::OTHER?$e['department_id']:self::OTHER;}
    /** Share the height between days in proportion to their lanes, never below ROW. */
    private function rows(array $lanes,float $height): array
    {
        $fixed=[];
        do{
            $units=0;foreach($lanes as $i=>$n)if(!isset($fixed[$i]))$units+=$n;
            $unit=$units?($height-count($fixed)*self::ROW)/$units:0;$changed=false;
            foreach($lanes as $i=>$n)if(!isset($fixed[$i])&&$n*$unit<self::ROW){$fixed[$i]=true;$changed=true;}
        }while($changed&&count($fixed)<count($lanes));
        return array_map(fn($i)=>isset($fixed[$i])?self::ROW:$lanes[$i]*$unit,array_keys($lanes));
    }
    /** The section beside its shifts: a rounded rectangle of its color spanning the group, the name inside. */
    private function sectionTag(float $x,float $top,float $h,string $label,array $color): void
    {
        $y=$top+.25;$h-=.5;$w=self::SECTIONS-1.4;
        $this->RoundedRect($x,$y,$w,$h,min(.8,$h*.22),'1111','DF',['width'=>.25,'color'=>$color[0]],$color[1]);
        $size=min(5.6,($h-.45)/.3528);if($size<4.2)return;
        $this->font('medium',$size);
        $this->put('medium',$size,self::INK,$this->fit($label,$w-2.4),$x+1.2,$y+$h/2+$size*.3528*.36);
    }
    /** One shift: a rounded rectangle, filled and bordered in its section color, name then hours. */
    private function shift(array $e,float $x0,float $scale,int $min,float $rowTop,float $rowHeight,array $color): void
    {
        [$border,$fill]=$color;
        $slot=$rowHeight/$e['lane_count'];$h=min(self::BAR,$slot-min(1.8,max(.55,$slot*.2)));
        $top=$rowTop+$e['lane']*$slot+($slot-$h)/2;$left=$x0+($e['start']-$min)*$scale+.3;$w=max(.6,($e['end']-$e['start'])*$scale-.6);
        // A small radius whatever the height: a rectangle with rounded corners, never a pill.
        $this->RoundedRect($left,$top,$w,$h,min(.8,$h*.22,$w/2),'1111','DF',['width'=>.25,'color'=>$border],$fill);
        // An overnight shift is cut at midnight: a small arrow marks the end that continues on the next day.
        $cy=$top+$h/2;$this->SetFillColorArray($border);$after=!empty($e['cut_after'])&&$w>5&&$h>2.4;$before=!empty($e['cut_before'])&&$w>5&&$h>2.4;
        if($after)$this->Polygon([$left+$w-1.7,$cy-.85,$left+$w-1.7,$cy+.85,$left+$w-.75,$cy],'F');
        if($before)$this->Polygon([$left+.75,$cy-.85,$left+.75,$cy+.85,$left+1.7,$cy],'F');
        $size=min(7.4,($h-.45)/.3528);
        if($size>=4.4)$this->label($e,$left+($before?2.3:1.1),$cy,$w-($before?2.3:1.1)-($after?2.3:1.1),$h,$size);
    }
    /** Name in Gotham Medium with the hours in Gotham Light, under it when the bar is tall enough, beside it otherwise. */
    private function label(array $e,float $x,float $cy,float $w,float $h,float $size): void
    {
        if($w<2)return;
        $timeSize=$size-1;$cap=.3528*.72;$range=$this->range($e);
        $this->font('light',$timeSize);$tw=$this->GetStringWidth($range);
        $this->font('medium',$size);$nw=$this->GetStringWidth($e['name']);
        if($h>=6.2&&$tw<=$w){
            $gap=1.15;$block=$size*$cap+$gap+$timeSize*$cap;$base=$cy-$block/2+$size*$cap;
            $this->put('medium',$size,self::INK,$this->fit($e['name'],$w),$x,$base);
            $this->put('light',$timeSize,self::MUTED,$range,$x,$base+$gap+$timeSize*$cap);
            return;
        }
        $base=$cy+$size*$cap/2;
        $end=$this->put('medium',$size,self::INK,$this->fit($e['name'],$w),$x,$base);
        if($nw+1.6+$tw<=$w)$this->put('light',$timeSize,self::MUTED,$range,$end+1.6,$base);
    }
    private function total(float $x,float $y,float $h,int $people,float $minutes): void
    {
        $base=$y+$h/2+.85;
        if(!$people){$this->put('light',6.6,self::SOFT,'—',$x,$base,self::TOTALS,'C');return;}
        $count=(string)$people;$hours=$this->hours($minutes);
        $this->font('medium',6.6);$wc=$this->GetStringWidth($count);$this->font('light',6.6);$wh=$this->GetStringWidth($hours);
        $x+=(self::TOTALS-(2+.9+$wc+2.2+$wh))/2;
        $this->person($x,$base,2,self::INK);$x=$this->put('medium',6.6,self::INK,$count,$x+2.9,$base);
        $this->put('light',6.6,self::MUTED,$hours,$x+2.2,$base);
    }
    /** Same footer as the report PDFs (src/rapports.php): hairline, shop in ink, document, generation date and sheet. */
    public function Footer(): void
    {
        $this->setCellPaddings(0,0,0,0);
        $this->SetDrawColorArray(self::FRAME);$this->SetLineWidth(.15);$this->Line(self::X,200.6,self::X+self::W,200.6);
        $x=$this->put('medium',6.6,self::INK,$this->shop,self::X,204.6);
        $this->put('light',6.6,self::SOFT,' · '.$this->footer,$x,204.6);
        $this->put('light',6.6,self::SOFT,trim($this->copy['generated'].' '.date('d.m.Y H:i')).'  ·  '.$this->getPage().'/'.$this->sheets,self::X,204.6,self::W,'R');
    }
    private function tagWidth(string $strong,bool $lead,string $soft): float
    {
        $this->font('medium',7);$w=$this->GetStringWidth($strong);
        if($soft!==''){$this->font('light',7);$w+=$this->GetStringWidth($soft)+1.5;}
        return 4+($lead?2.9:0)+$w;
    }
    /** A rounded rectangle with an optional icon or color swatch, a strong text and a soft one. */
    private function tag(float $x,float $y,string $strong,array $fill,array $color,?string $icon=null,?array $swatch=null,string $soft=''): float
    {
        $h=5;$pad=2;$lead=$icon||$swatch?2.9:0;$w=$this->tagWidth($strong,(bool)$lead,$soft);$base=$y+3.42;
        $this->RoundedRect($x,$y,$w,$h,1.1,'1111','F',[],$fill);
        if($icon==='person')$this->person($x+$pad,$base,2.1,$color);
        if($icon==='clock')$this->clockIcon($x+$pad,$base,2.1,$color);
        if($swatch)$this->RoundedRect($x+$pad,$y+$h/2-1,2,2,.45,'1111','DF',['width'=>.25,'color'=>$swatch[0]],$swatch[1]);
        $end=$this->put('medium',7,$color,$strong,$x+$pad+$lead,$base);
        if($soft!=='')$this->put('light',7,self::MUTED,$soft,$end+1.5,$base);
        return $x+$w+1.6;
    }
    private function person(float $x,float $base,float $s,array $color): void
    {
        $this->SetFillColorArray($color);
        $this->Circle($x+$s/2,$base-$s*.8,$s*.23,0,360,'F');
        $this->RoundedRect($x+$s*.1,$base-$s*.48,$s*.8,$s*.48,$s*.3,'1001','F');
    }
    private function clockIcon(float $x,float $base,float $s,array $color): void
    {
        $cx=$x+$s/2;$cy=$base-$s*.42;
        $this->SetDrawColorArray($color);$this->SetLineWidth(.2);$this->Circle($cx,$cy,$s/2-.1,0,360,'D');
        $this->Line($cx,$cy,$cx,$cy-$s*.27);$this->Line($cx,$cy,$cx+$s*.2,$cy);
    }
    private function font(string $role,float $size): void {[$family,$style]=$this->typefaces[$role];$this->SetFont($family,$style,$size);}
    /** Text on a baseline (mm). Returns the x where the text ends, or the right edge of the box when a width is given. */
    private function put(string $role,float $size,array $color,string $text,float $x,float $baseline,float $w=0,string $align='L'): float
    {
        $this->font($role,$size);$this->SetTextColorArray($color);
        $width=$w>0?$w:$this->GetStringWidth($text)+.01;
        $this->SetXY($x,$baseline-$this->FontAscent);$this->Cell($width,$this->FontAscent+$this->FontDescent,$text,0,0,$align,false,'',0,true,'T','T');
        return $x+$width;
    }
    /** Uppercase labels tracked out like the cockpit's (letter-spacing .07em). */
    private function spaced(string $role,float $size,array $color,string $text,float $x,float $baseline,float $w,string $align): void
    {
        $this->font($role,$size);$this->setFontSpacing($size*.3528*.07);
        $this->put($role,$size,$color,$this->fit($text,$w),$x,$baseline,$w,$align);
        $this->setFontSpacing(0);
    }
    /** Vank carries base ASCII only (global.css unicode-range U+0020-007E): other characters fall through to Gotham Medium. */
    private function display(string $text,float $x,float $baseline,float $size,array $color): float
    {
        $vank=$this->typefaces['display'][0]==='vank';
        foreach($vank?preg_split('/([\x20-\x7E]+)/u',$text,-1,PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY):[$text] as $run)$x=$this->put(!$vank||preg_match('/^[\x20-\x7E]+$/',$run)?'display':'medium',$size,$color,$run,$x,$baseline);
        return $x;
    }
    /** The longest start of the text that fits, with an ellipsis when there is room for one. */
    private function fit(string $text,float $w): string
    {
        if($this->GetStringWidth($text)<=$w)return $text;
        for($n=mb_strlen($text)-1;$n>0;$n--){$part=rtrim(mb_substr($text,0,$n));if($this->GetStringWidth($part.'…')<=$w)return $part.'…';}
        for($n=mb_strlen($text)-1;$n>0;$n--){$part=mb_substr($text,0,$n);if($this->GetStringWidth($part)<=$w)return $part;}
        return '';
    }
    /** The real hours of the shift: both halves of a night shift read 22:00–06:00. */
    private function range(array $e): string {return self::clock(fmod($e['shift_start']??$e['start'],1440)).'–'.self::clock(fmod($e['shift_end']??$e['end'],1440));}
    private function hours(float $minutes): string
    {
        $h=round($minutes/60,1);
        return (fmod($h,1.0)==0.0?number_format($h,0,'',''):number_format($h,1,$this->decimal,'')).' '.$this->copy['hour_unit'];
    }
    private static function clock(float $minute): string {return sprintf('%02d:%02d',floor($minute/60),(int)$minute%60);}
}
