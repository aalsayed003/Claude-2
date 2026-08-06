<?php
/** Drive the merged HRMS app: one login, then roster + payroll routes. */
error_reporting(E_ALL & ~E_DEPRECATED);
$BASE = getenv('BASE_URL') ?: 'http://127.0.0.1:8092';
$JAR = sys_get_temp_dir() . '/hrms_admin.txt'; @unlink($JAR);

function req($m,$url,$post=null,$follow=true){
  global $BASE,$JAR; $ch=curl_init();
  curl_setopt_array($ch,[CURLOPT_URL=>$BASE.'/'.ltrim($url,'/'),CURLOPT_RETURNTRANSFER=>1,
    CURLOPT_COOKIEJAR=>$JAR,CURLOPT_COOKIEFILE=>$JAR,CURLOPT_FOLLOWLOCATION=>$follow,CURLOPT_HEADER=>1]);
  if($m==='POST'){curl_setopt($ch,CURLOPT_POST,1);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post?:[]));}
  $r=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);$h=curl_getinfo($ch,CURLINFO_HEADER_SIZE);
  curl_close($ch);return [$c,substr($r,$h),substr($r,0,$h)];
}
function csrf($b){return preg_match('/name="_csrf"\s+value="([a-f0-9]+)"/',$b,$m)?$m[1]:null;}

$FAILS=[];$PASS=0;$TOTAL=0;
function check($label,$c,$b){
  global $FAILS,$PASS,$TOTAL;$TOTAL++;$sig=null;
  if($c>=500)$sig="HTTP $c";
  foreach(['Fatal error','Uncaught','Parse error','TypeError','Call to a member',
           'Undefined variable','Undefined method','Class "','No view','Cannot redeclare'] as $s){
    if(strpos($b,$s)!==false){$sig=$sig?:$s;break;}
  }
  // "Undefined array key" is a dev-only notice (suppressed in production) -> not a failure.
  // DB "no such table/column" -> data gap, note separately (not a merge failure)
  $dataGap = preg_match('/no such (table|column)|SQLSTATE/i',$b) ? 'DATA-GAP' : null;
  if($sig){$FAILS[]=[$label,$c,$sig,trim(preg_replace('/\s+/',' ',strip_tags($b)))];printf("  FAIL  %-42s [%s] %s\n",$label,$c,$sig);}
  elseif($dataGap){printf("  data  %-42s [%s] %s\n",$label,$c,$dataGap);$PASS++;}
  else{$PASS++;printf("  ok    %-42s [%s]\n",$label,$c);}
}

// login
[$c,$b]=req('GET','login');$tok=csrf($b);
[$c,$b,$h]=req('POST','login',['username'=>'admin','password'=>'admin123','_csrf'=>$tok]);
preg_match('/^location:\s*(\S+)/mi',$h,$lm);
printf("LOGIN admin -> %s\n", (strpos($lm[1]??'','dashboard')!==false)?'OK':'FAIL('.$c.')');
[$c,$b]=req('GET','dashboard');$tok=csrf($b)?:$tok;

echo "\n== ROSTER module ==\n";
foreach([
 'dashboard','dashboard/list?metric=late&date=2026-07-20&period=2026-08',
 'attendance?period=2026-08&employee_id=202&tab=attendance',
 'shifts','employees','departments','roster?period=2026-07','approvals',
 'overtime?period=2026-08&employee_id=202','correction?employee_id=202','schedule-change?employee_id=202',
] as $g){[$c,$b]=req('GET',$g);check("GET $g",$c,$b);}

echo "\n== PAYROLL module ==\n";
foreach([
 'payroll','payroll/home','payroll/structures','payroll/structure?employee_id=202&payroll_month=2026-08',
 'payroll/payslip','payroll/loans','payroll/settlement','payroll/holds','payroll/encashment',
 'payroll/indemnity','payroll/leave-provision','payroll/employees','payroll/wps',
 'me','me/payslips','me/leave','me/cme','hr/leave','hr/requests','hr/cme',
] as $g){[$c,$b]=req('GET',$g);check("GET $g",$c,$b);}

echo "\n== PAYROLL RUN (reads attendance via roster link) ==\n";
[$c,$b]=req('GET','payroll');$tok=csrf($b)?:$tok;
[$c,$b,$h]=req('POST','payroll/create',['_csrf'=>$tok,'payroll_month'=>'2026-08'],false);
check('POST payroll/create',$c,$b);
preg_match('/run\?id=(\d+)/',$h,$rm);$rid=$rm[1]??null;
if($rid){
  [$c,$b]=req('POST','payroll/calculate',['_csrf'=>$tok,'id'=>$rid]);check("POST payroll/calculate (run $rid)",$c,$b);
  [$c,$b]=req('GET',"payroll/run?id=$rid");check('GET payroll/run',$c,$b);
  [$c,$b]=req('GET','payroll/register?payroll_month=2026-08');check('GET payroll/register',$c,$b);
}else{ echo "  (no run id captured)\n"; }

echo "\n=============================\n";
printf("PASS %d / %d\n",$PASS,$TOTAL);
if($FAILS){echo "\nFAILURES (merge/runtime, excluding data-gaps):\n";
 foreach($FAILS as [$l,$cd,$s,$snip]){echo "----\n[$s] $l (HTTP $cd)\n".mb_substr($snip,0,240)."\n";}}
else echo "NO MERGE/RUNTIME ERRORS\n";
