<?php

declare(strict_types=1);

namespace AccessAnalyticsPlus;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Stages new page views until a human-presence signal is received. */
final class Shadow_Diagnostics {
	public const RETENTION_DAYS = 7;
	public const PENDING_GRACE_SECONDS = 300;
	public const VISIBLE_SECONDS = 3;
	public const INTERACTION_SCROLL = 1;
	public const INTERACTION_POINTER = 2;
	public const INTERACTION_TOUCH = 4;
	public const INTERACTION_KEY = 8;
	private const TOKEN_TTL = 2700;
	private const FLAG_WEBDRIVER = 1;
	private const FLAG_ORIGIN_MISSING = 2;
	private const FLAG_DEVICE_MISMATCH = 4;
	private const FLAG_MANY_VISITORS_IP = 8;
	private const FLAG_VISIBLE_MISSING = 16;
	private const FLAG_INTERACTION_MISSING = 32;
	private const FLAG_ENGAGEMENT_MISSING = 64;
	private const FLAG_MANY_VISITORS_UA = 128;
	private const FLAG_REPEATED_UA_PATH = 256;
	private const FLAG_REGULAR_INTERVAL = 512;
	private const SUSPICIOUS_SCORE = 6;

	public static function enabled(): bool { return true; }

	/** @param array{type:string,host:string,search_source:string} $referrer @return array{token:string,class:string,country_code:string}|false */
	public static function stage(string $visitor_id,string $session_id,string $path,string $title,array $referrer,string $user_agent,string $reported_device,int $webdriver_state,bool $origin_present) {
		try {
			global $wpdb; $table=Database::tables()['shadow_events']; $now=current_time('mysql',true); $ip=Settings::current_ip();
			$ip_key=''!==$ip?self::identifier_hmac($ip,'ip'):''; $visitor_key=self::identifier_hmac(strtolower($visitor_id),'visitor');
			$session_key=self::identifier_hmac(strtolower($session_id),'session'); $ua_hash=self::identifier_hmac($user_agent,'user-agent');
			$ua_device=self::device_from_user_agent($user_agent); $reported=in_array($reported_device,array('mobile','desktop','tablet','other'),true)?$reported_device:'other';
			$country=Country_Resolver::resolve();
			$visitor_is_new=0===(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE visitor_key=%s AND recorded_at>=%s",$visitor_key,gmdate('Y-m-d H:i:s',time()-self::RETENTION_DAYS*DAY_IN_SECONDS)));
			$class=Country_Resolver::is_allowed($country['code'])?'pending':'geo_excluded';
			$inserted=$wpdb->insert($table,array(
				'recorded_at'=>$now,'updated_at'=>$now,'ip_key'=>$ip_key,'visitor_key'=>$visitor_key,'session_key'=>$session_key,'ua_hash'=>$ua_hash,
				'path_hash'=>hash('sha256',$path),'path'=>$path,'title'=>$title,'referrer_type'=>$referrer['type'],'referrer_host'=>$referrer['host'],'search_source'=>$referrer['search_source'],
				'ua_family'=>self::user_agent_family($user_agent),'ua_device_type'=>$ua_device,'reported_device_type'=>$reported,'country_code'=>$country['code'],'country_source'=>$country['source'],
				'device_mismatch'=>('other'!==$reported&&'other'!==$ua_device&&$reported!==$ua_device)?1:0,'webdriver_state'=>in_array($webdriver_state,array(-1,0,1),true)?$webdriver_state:-1,
				'origin_present'=>$origin_present?1:0,'visitor_is_new'=>$visitor_is_new?1:0,'shadow_class'=>$class,'finalized'=>'geo_excluded'===$class?1:0,'aggregation_mode'=>'staged'
			));
			if(false===$inserted){return false;} $event_id=(int)$wpdb->insert_id;
			self::refresh_group_signals($event_id,$ip_key,$ua_hash,hash('sha256',$path)); self::evaluate($event_id);
			if('geo_excluded'===$class){self::flush_exclusions();}
			return array('token'=>self::create_token($event_id),'class'=>$class,'country_code'=>$country['code']);
		} catch (\Throwable $error) { return false; }
	}

	/** @return WP_REST_Response|WP_Error */
	public static function record_signal(WP_REST_Request $request) {
		$token=(string)$request->get_param('token'); $data=self::verify_token($token);
		if(false===$data){$legacy=Tracker::verify_pageview_token($token);if(false===$legacy){return new WP_Error('aap_invalid_shadow_token',__('診断情報の有効期限が切れています。','access-analytics-plus'),array('status'=>403));}self::update_legacy_signal($legacy['pageview_id'],(bool)$request->get_param('visible_confirmed'),absint($request->get_param('interaction_mask')));return new WP_REST_Response(array('accepted'=>true),200);}
		self::update_signal($data['event_id'],(bool)$request->get_param('visible_confirmed'),absint($request->get_param('interaction_mask')),false);
		return new WP_REST_Response(array('accepted'=>true),200);
	}

	/** @return array{pageview_id:int,issued_at:int}|false */
	public static function accept_engagement(string $token) {
		$data=self::verify_token($token); if(false===$data){return false;} self::update_signal($data['event_id'],false,0,true);
		global $wpdb; $row=$wpdb->get_row($wpdb->prepare('SELECT pageview_id FROM '.Database::tables()['shadow_events'].' WHERE id=%d',$data['event_id']),ARRAY_A);
		return $row&&(int)$row['pageview_id']>0?array('pageview_id'=>(int)$row['pageview_id'],'issued_at'=>$data['issued_at']):false;
	}

	public static function mark_engagement_received(int $pageview_id):void { if($pageview_id<1){return;} global $wpdb;$wpdb->update(Database::tables()['shadow_events'],array('engagement_received'=>1),array('pageview_id'=>$pageview_id)); }

	public static function finalize_pending(int $limit=1000):int {
		global $wpdb;$table=Database::tables()['shadow_events'];$ids=$wpdb->get_col($wpdb->prepare("SELECT id FROM {$table} WHERE aggregation_mode='staged' AND finalized=0 AND recorded_at<=%s ORDER BY id ASC LIMIT %d",gmdate('Y-m-d H:i:s',time()-self::PENDING_GRACE_SECONDS),max(1,min(5000,$limit))));
		foreach($ids as $id){self::evaluate((int)$id);} self::flush_exclusions(); return count($ids);
	}

	/** @return array<string,mixed> */
	public static function summary():array {
		$empty=array('total'=>0,'human_like'=>0,'unconfirmed'=>0,'suspected'=>0,'geo_excluded'=>0,'signals'=>array('visible'=>0,'interaction'=>0,'engagement'=>0,'webdriver'=>0,'origin_missing'=>0,'device_mismatch'=>0),'reasons'=>array(),'ips'=>array());
		try { self::finalize_pending(5000);global $wpdb;$table=Database::tables()['shadow_events'];$cutoff=gmdate('Y-m-d H:i:s',time()-self::RETENTION_DAYS*DAY_IN_SECONDS);$summary=$empty;
			foreach((array)$wpdb->get_results($wpdb->prepare("SELECT shadow_class,COUNT(*) total FROM {$table} WHERE recorded_at>=%s GROUP BY shadow_class",$cutoff),ARRAY_A) as $row){$count=(int)$row['total'];$summary['total']+=$count;if(in_array($row['shadow_class'],array('confirmed','human_like'),true)){$summary['human_like']+=$count;}elseif(in_array($row['shadow_class'],array('bot','suspected'),true)){$summary['suspected']+=$count;}elseif('geo_excluded'===$row['shadow_class']){$summary['geo_excluded']+=$count;}else{$summary['unconfirmed']+=$count;}}
			$s=$wpdb->get_row($wpdb->prepare("SELECT SUM(visible_confirmed=1) visible,SUM(interaction_mask>0) interaction_count,SUM(engagement_received=1) engagement,SUM(webdriver_state=1) webdriver,SUM(origin_present=0) origin_missing,SUM(device_mismatch=1) device_mismatch FROM {$table} WHERE recorded_at>=%s",$cutoff),ARRAY_A);
			foreach(array('visible'=>'visible','interaction'=>'interaction_count','engagement'=>'engagement','webdriver'=>'webdriver','origin_missing'=>'origin_missing','device_mismatch'=>'device_mismatch') as $key=>$column){$summary['signals'][$key]=(int)($s[$column]??0);}
			$labels=self::reason_labels();$selects=array();foreach(array_keys($labels) as $flag){$selects[]="SUM(CASE WHEN (risk_flags & {$flag})<>0 THEN 1 ELSE 0 END) flag_{$flag}";}$r=$wpdb->get_row($wpdb->prepare('SELECT '.implode(',',$selects)." FROM {$table} WHERE recorded_at>=%s AND shadow_class IN ('bot','suspected')",$cutoff),ARRAY_A);foreach($labels as $flag=>$label){$value=(int)($r['flag_'.$flag]??0);if($value){$summary['reasons'][]=array('label'=>$label,'value'=>$value);}}
			$rows=$wpdb->get_results($wpdb->prepare("SELECT ip_key,COUNT(*) total,COUNT(DISTINCT visitor_key) visitors,SUM(visible_confirmed=1) visible,SUM(engagement_received=1) engagement,SUM(webdriver_state=1) webdriver,SUM(shadow_class IN ('bot','suspected')) suspected FROM {$table} WHERE recorded_at>=%s AND ip_key<>'' GROUP BY ip_key HAVING COUNT(*)>1 ORDER BY total DESC LIMIT 5",$cutoff),ARRAY_A);foreach((array)$rows as $row){$summary['ips'][]=array('key'=>strtoupper(substr((string)$row['ip_key'],0,4)).'…','total'=>(int)$row['total'],'visitors'=>(int)$row['visitors'],'visible'=>(int)$row['visible'],'engagement'=>(int)$row['engagement'],'webdriver'=>(int)$row['webdriver'],'suspected'=>(int)$row['suspected']);}return $summary;
		} catch(\Throwable $error){return $empty;}
	}

	private static function update_signal(int $event_id,bool $visible,int $interactions,bool $engagement):void {global $wpdb;$table=Database::tables()['shadow_events'];$wpdb->query($wpdb->prepare("UPDATE {$table} SET visible_confirmed=GREATEST(visible_confirmed,%d),interaction_mask=interaction_mask | %d,engagement_received=GREATEST(engagement_received,%d),updated_at=%s WHERE id=%d AND aggregation_mode='staged'",$visible?1:0,min(15,$interactions),$engagement?1:0,current_time('mysql',true),$event_id));self::evaluate($event_id);}

	private static function evaluate(int $event_id):void {
		global $wpdb;$table=Database::tables()['shadow_events'];$wpdb->query('START TRANSACTION');$row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id=%d FOR UPDATE",$event_id),ARRAY_A);
		if(!$row||'staged'!==$row['aggregation_mode']){$wpdb->query('ROLLBACK');return;}if((int)$row['finalized']===1){$wpdb->query('COMMIT');return;}$age=max(0,time()-strtotime((string)$row['recorded_at'].' UTC'));$diagnosis=self::diagnose($row,$age);$pageview_id=0;$session_id=0;$promoted_at=null;
		if('confirmed'===$diagnosis['class']){$promoted=Tracker::promote_shadow_row($row);if(false===$promoted){$wpdb->query('ROLLBACK');return;}$pageview_id=$promoted['pageview_id'];$session_id=$promoted['session_id'];$promoted_at=current_time('mysql',true);}
		$finalized=in_array($diagnosis['class'],array('confirmed','bot','unconfirmed','geo_excluded'),true)?1:0;$updated=$wpdb->update($table,array('pageview_id'=>$pageview_id?:null,'session_id'=>$session_id?:null,'risk_score'=>$diagnosis['score'],'risk_flags'=>$diagnosis['flags'],'shadow_class'=>$diagnosis['class'],'finalized'=>$finalized,'promoted_at'=>$promoted_at,'updated_at'=>current_time('mysql',true)),array('id'=>$event_id));$wpdb->query(false===$updated?'ROLLBACK':'COMMIT');
	}

	/** @param array<string,mixed> $row @return array{class:string,score:int,flags:int} */
	private static function diagnose(array $row,int $age):array {
		if('geo_excluded'===$row['shadow_class']){return array('class'=>'geo_excluded','score'=>0,'flags'=>0);}$flags=0;$score=0;
		if(1===(int)$row['webdriver_state']){$flags|=self::FLAG_WEBDRIVER;$score+=4;}if(0===(int)$row['origin_present']){$flags|=self::FLAG_ORIGIN_MISSING;$score+=1;}if(1===(int)$row['device_mismatch']){$flags|=self::FLAG_DEVICE_MISMATCH;$score+=2;}if((int)$row['ip_visitors_10m']>=5){$flags|=self::FLAG_MANY_VISITORS_IP;$score+=4;}if((int)$row['ua_visitors_10m']>=10){$flags|=self::FLAG_MANY_VISITORS_UA;$score+=2;}if((int)$row['ua_path_requests_10m']>=10){$flags|=self::FLAG_REPEATED_UA_PATH;$score+=2;}if(1===(int)$row['regular_interval']){$flags|=self::FLAG_REGULAR_INTERVAL;$score+=1;}
		$human=1===(int)$row['visible_confirmed']||(int)$row['interaction_mask']>0||1===(int)$row['engagement_received'];if($human){return array('class'=>$score>=self::SUSPICIOUS_SCORE?'bot':'confirmed','score'=>$score,'flags'=>$flags);}if($age<self::PENDING_GRACE_SECONDS){return array('class'=>'pending','score'=>$score,'flags'=>$flags);}$flags|=self::FLAG_VISIBLE_MISSING|self::FLAG_INTERACTION_MISSING|self::FLAG_ENGAGEMENT_MISSING;$score+=3;return array('class'=>$score>=self::SUSPICIOUS_SCORE?'bot':'unconfirmed','score'=>$score,'flags'=>$flags);
	}

	private static function refresh_group_signals(int $id,string $ip,string $ua,string $path):void {global $wpdb;$table=Database::tables()['shadow_events'];$start=gmdate('Y-m-d H:i:s',time()-10*MINUTE_IN_SECONDS);$ip_count=''===$ip?0:(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_key) FROM {$table} WHERE ip_key=%s AND recorded_at>=%s",$ip,$start));$ua_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT visitor_key) FROM {$table} WHERE ua_hash=%s AND recorded_at>=%s",$ua,$start));$path_count=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE ua_hash=%s AND path_hash=%s AND recorded_at>=%s",$ua,$path,$start));$times=$wpdb->get_col($wpdb->prepare("SELECT recorded_at FROM {$table} WHERE ua_hash=%s AND path_hash=%s AND id<>%d ORDER BY recorded_at DESC LIMIT 2",$ua,$path,$id));$regular=0;if(count($times)===2){$now=time();$gap1=$now-strtotime($times[0].' UTC');$gap2=strtotime($times[0].' UTC')-strtotime($times[1].' UTC');$regular=($gap1>=30&&$gap2>=30&&abs($gap1-$gap2)<=15)?1:0;}$wpdb->update($table,array('ip_visitors_10m'=>$ip_count,'ua_visitors_10m'=>$ua_count,'ua_path_requests_10m'=>$path_count,'regular_interval'=>$regular),array('id'=>$id));}

	private static function flush_exclusions():void {
		global $wpdb;$table=Database::tables()['shadow_events'];$wpdb->query('START TRANSACTION');
		$rows=$wpdb->get_results("SELECT id,recorded_at,shadow_class FROM {$table} WHERE aggregation_mode='staged' AND finalized=1 AND exclusion_recorded=0 AND shadow_class IN ('unconfirmed','bot','geo_excluded') ORDER BY id ASC LIMIT 1000 FOR UPDATE",ARRAY_A);
		foreach((array)$rows as $row){$date=(new \DateTimeImmutable($row['recorded_at'],new \DateTimeZone('UTC')))->setTimezone(wp_timezone())->format('Y-m-d');Tracker::record_exclusion_at('bot'===$row['shadow_class']?'automation':$row['shadow_class'],$date);$wpdb->update($table,array('exclusion_recorded'=>1),array('id'=>(int)$row['id']));}
		$wpdb->query('COMMIT');
	}
	private static function create_token(int $id):string{$issued=time();$expires=$issued+self::TOKEN_TTL;$sig=hash_hmac('sha256','s|'.$id.'|'.$issued.'|'.$expires,wp_salt('nonce'));return 's.'.$id.'.'.$issued.'.'.$expires.'.'.$sig;}
	/** @return array{event_id:int,issued_at:int}|false */
	private static function verify_token(string $token){if(1!==preg_match('/^s\.(\d+)\.(\d+)\.(\d+)\.([a-f0-9]{64})$/',$token,$m)){return false;}$id=absint($m[1]);$issued=absint($m[2]);$expires=absint($m[3]);if($id<1||$issued>time()+60||$expires<time()||$expires-$issued!==self::TOKEN_TTL){return false;}$expected=hash_hmac('sha256','s|'.$id.'|'.$issued.'|'.$expires,wp_salt('nonce'));return hash_equals($expected,$m[4])?array('event_id'=>$id,'issued_at'=>$issued):false;}
	private static function update_legacy_signal(int $pageview,bool $visible,int $mask):void {global $wpdb;$wpdb->query($wpdb->prepare("UPDATE ".Database::tables()['shadow_events']." SET visible_confirmed=GREATEST(visible_confirmed,%d),interaction_mask=interaction_mask | %d WHERE pageview_id=%d AND aggregation_mode='legacy'",$visible?1:0,min(15,$mask),$pageview));}
	private static function identifier_hmac(string $value,string $context):string{return hash_hmac('sha256',$context.'|'.$value,wp_salt('secure_auth'));}
	private static function device_from_user_agent(string $ua):string{if(preg_match('/ipad|tablet|kindle|silk/i',$ua)||(false!==stripos($ua,'android')&&false===stripos($ua,'mobile'))){return 'tablet';}if(preg_match('/mobile|iphone|ipod|android/i',$ua)){return 'mobile';}return ''!==$ua?'desktop':'other';}
	private static function user_agent_family(string $ua):string{foreach(array('samsung_internet'=>'SamsungBrowser','edge'=>'EdgA?|EdgiOS','chrome'=>'CriOS|Chrome','firefox'=>'FxiOS|Firefox','safari'=>'Safari') as $name=>$pattern){if(preg_match('/'.$pattern.'/i',$ua)){return $name;}}return ''!==$ua?'other':'unknown';}
	/** @return array<int,string> */
	private static function reason_labels():array{return array(self::FLAG_WEBDRIVER=>__('自動操作ブラウザーの申告','access-analytics-plus'),self::FLAG_ORIGIN_MISSING=>__('Origin情報なし','access-analytics-plus'),self::FLAG_DEVICE_MISMATCH=>__('端末情報の矛盾','access-analytics-plus'),self::FLAG_MANY_VISITORS_IP=>__('同一匿名IPから多数の識別子','access-analytics-plus'),self::FLAG_VISIBLE_MISSING=>__('3秒の表示確認なし','access-analytics-plus'),self::FLAG_INTERACTION_MISSING=>__('操作確認なし','access-analytics-plus'),self::FLAG_ENGAGEMENT_MISSING=>__('閲覧時間の送信なし','access-analytics-plus'),self::FLAG_MANY_VISITORS_UA=>__('同じブラウザー系統から多数の新規識別子','access-analytics-plus'),self::FLAG_REPEATED_UA_PATH=>__('同じブラウザー系統とページへの集中','access-analytics-plus'),self::FLAG_REGULAR_INTERVAL=>__('機械的に一定なアクセス間隔','access-analytics-plus'));}
}
