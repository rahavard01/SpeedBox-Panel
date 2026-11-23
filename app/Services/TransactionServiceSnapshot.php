<?php
namespace App\Services;
use Illuminate\Support\Facades\DB;

final class TransactionServiceSnapshot
{
    public function attach(iterable $rows): void
    {
        $rows=collect($rows);
        $metadata=[];$groupIds=[];$userIds=[];
        foreach($rows as $n=>$row) {
            $meta=is_string($row->meta??null)?json_decode($row->meta,true):($row->meta??[]);
            $meta=is_array($meta)?$meta:[];
            $metadata[$n]=$meta;
            if(!empty($meta['tariff_group_id']))$groupIds[]=(int)$meta['tariff_group_id'];
            foreach((array)($meta['user_ids']??[]) as $id)$userIds[]=(int)$id;
            if(($row->reference_type??null)==='v2_user' && !empty($row->reference_id))$userIds[]=(int)$row->reference_id;
        }
        $groups=DB::table('panel_tariff_groups')->whereIn('id',array_unique($groupIds))->get(['id','name','service_type'])->keyBy('id');
        $users=DB::table('v2_user')->whereIn('id',array_unique($userIds))->get(['id','service_type'])->keyBy('id');
        foreach($rows as $n=>$row) {
            $meta=$metadata[$n];$group=$groups->get((int)($meta['tariff_group_id']??0));
            $ids=(array)($meta['user_ids']??[]);
            if(!$ids && ($row->reference_type??null)==='v2_user')$ids=[$row->reference_id??0];
            $types=collect($ids)->map(fn($id)=>$users->get((int)$id)?->service_type)->filter()->unique()->values();
            $type=$meta['service_type']??($types->count()===1?$types->first():($group->service_type??null));
            $row->service_type=in_array($type,['v2ray','wireguard'],true)?$type:null;
            $row->account_count=count($ids)?:max(1,(int)($meta['account_count']??1));
            $meta['tariff_group_name']=$meta['tariff_group_name']??($group->name??null);
            $row->meta=json_encode($meta,JSON_UNESCAPED_UNICODE);
        }
    }
}
