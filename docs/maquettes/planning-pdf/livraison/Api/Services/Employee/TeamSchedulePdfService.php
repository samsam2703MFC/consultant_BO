<?php
namespace App\Api\Services\Employee;
use App\Api\Repositories\Employee\TeamSchedulePdfRepository;
use App\Api\Repositories\Utils\TeamSchedulePdf;
use DateTimeImmutable;
final class TeamSchedulePdfService
{
    public function __construct(private TeamSchedulePdfRepository $repository) {}
    public static function options(array $query): array
    {
        $date=$query['start_date']??null;$days=$query['days']??'7';$layout=$query['layout']??'shop';
        if (!is_string($date)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date)) throw new \InvalidArgumentException('INVALID_SCHEDULE_RANGE');
        $start=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
        if (!$start||$start->format('Y-m-d')!==$date||$date<'2000-01-01'||$date>'2099-12-17'||!is_string($days)||!in_array($days,['7','14'],true)||!is_string($layout)||!in_array($layout,['shop','departments'],true)) throw new \InvalidArgumentException('INVALID_SCHEDULE_RANGE');
        return ['start'=>$date,'days'=>(int)$days,'layout'=>$layout];
    }
    public function generate(int $shop,array $query,string $language): array
    {
        $o=self::options($query);$start=new DateTimeImmutable($o['start']);$end=$start->modify('+'.$o['days'].' days');
        $source=$this->repository->read($shop,$start->modify('-1 day')->format('Y-m-d'),$end->format('Y-m-d'));
        $plan=self::plan($source,$o);$pdf=new TeamSchedulePdf($plan,$language);$pdf->render();
        return ['pdf'=>$pdf->Output('schedule.pdf','S'),'filename'=>'schedule_'.$shop.'_'.$o['start'].'_'.$o['days'].'_'.$o['layout']];
    }
    /** Split overnight shifts into civil days; color/geometry is independent of DST. */
    public static function plan(array $source,array $options): array
    {
        $start=new DateTimeImmutable($options['start']);$dates=[];
        for($d=0;$d<$options['days'];$d++)$dates[]=$start->modify('+'.$d.' days')->format('Y-m-d');
        $events=[];$minimum=240;$maximum=1200;
        foreach($source['shifts'] as $shift){
            $from=self::minutes($shift['start_hour']);$to=self::minutes($shift['end_hour']);if($from===$to)continue;
            if($to<$from)$to+=1440;
            $name=trim((string)($shift['display_name']??''));if($name==='')$name=trim(($shift['name']??'').' '.($shift['surname']??''));if($name==='')$name='#'.$shift['id_employee'];
            foreach([0,1] as $offset){
                $a=max(0,$from-$offset*1440);$b=min(1440,$to-$offset*1440);if($b<=$a)continue;
                $date=(new DateTimeImmutable($shift['work_date']))->modify('+'.$offset.' days')->format('Y-m-d');if(!in_array($date,$dates,true))continue;
                // shift_start/shift_end keep the real times, so both halves of an overnight shift print 22:00–06:00.
                $event=['date'=>$date,'start'=>$a,'end'=>$b,'name'=>$name,'department_id'=>(int)($shift['department_id']??0),'employee_id'=>(int)$shift['id_employee'],'shift_start'=>$from,'shift_end'=>$to,'cut_before'=>$offset===1,'cut_after'=>$offset===0&&$to>1440];
                $events[]=$event;$minimum=min($minimum,(int)(floor($a/30)*30));$maximum=max($maximum,(int)(ceil($b/30)*30));
            }
        }
        $pages=[];
        if($options['layout']==='shop')$pages[]=['name'=>null,'events'=>$events];
        else {
            $groups=[];foreach($source['departments'] as $area)$groups[(int)$area['id']]=['id'=>(int)$area['id'],'name'=>$area['name'],'events'=>[]];
            foreach($events as $event){$id=$event['department_id'];if(!isset($groups[$id]))$id=0;if(!isset($groups[$id]))$groups[$id]=['id'=>0,'name'=>null,'unassigned'=>true,'events'=>[]];$groups[$id]['events'][]=$event;}
            $pages=array_values($groups);if(!$pages)$pages[]=['id'=>0,'name'=>null,'unassigned'=>true,'events'=>[]];
        }
        // One sheet per week of 7 days from the start date; each week keeps its own hour window (04:00–20:00 at least),
        // so a night shift in week 2 does not stretch week 1.
        $weeks=[];
        foreach(array_chunk($dates,7) as $days){
            $low=240;$high=1200;
            foreach($events as $event)if(in_array($event['date'],$days,true)){$low=min($low,(int)(floor($event['start']/30)*30));$high=max($high,(int)(ceil($event['end']/30)*30));}
            $weeks[]=['dates'=>$days,'minimum'=>$low,'maximum'=>$high];
        }
        // Departments in repository order: the PDF gives each one a stable brand color and a legend chip.
        $departments=[];foreach($source['departments']??[] as $area)$departments[(int)$area['id']]=(string)$area['name'];
        return ['shop'=>$source['shop'],'dates'=>$dates,'minimum'=>$minimum,'maximum'=>$maximum,'pages'=>$pages,'layout'=>$options['layout'],'departments'=>$departments,'weeks'=>$weeks];
    }
    private static function minutes(string $time): float
    {
        if(!preg_match('/^(\d{2}):([0-5]\d)(?::([0-5]\d))?$/D',$time,$m)||(int)$m[1]>23)throw new \InvalidArgumentException('INVALID_SHIFT_TIME');
        return (int)$m[1]*60+(int)$m[2]+(int)($m[3]??0)/60;
    }
    /** Each connected overlap group has its own lane count; disjoint shifts use full width. */
    public static function lanes(array $events): array
    {
        usort($events,fn($a,$b)=>[$a['start'],$a['end'],$a['name']]<=>[$b['start'],$b['end'],$b['name']]);
        $result=[];$group=[];$end=-1;$maximum=1;
        $flush=static function()use(&$group,&$result,&$maximum): void {
            if(!$group)return;$ends=[];
            foreach($group as &$event){$lane=0;while(isset($ends[$lane])&&$ends[$lane]>$event['start'])$lane++;$ends[$lane]=$event['end'];$event['lane']=$lane;}unset($event);
            $count=count($ends);$maximum=max($maximum,$count);
            foreach($group as $event){$event['lane_count']=$count;$result[]=$event;}$group=[];
        };
        foreach($events as $event){if($group&&$event['start']>=$end){$flush();$end=-1;}$group[]=$event;$end=max($end,$event['end']);}
        $flush();return ['count'=>$maximum,'events'=>$result];
    }
}
