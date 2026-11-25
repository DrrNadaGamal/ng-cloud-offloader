<?php
// Needs tests/mock-server.js running (node tests/mock-server.js) and WordPress able to reach it.
require '/wordpress/wp-load.php';
require_once ABSPATH.'wp-admin/includes/plugin.php'; require_once ABSPATH.'wp-admin/includes/image.php';
require_once ABSPATH.'wp-admin/includes/file.php'; require_once ABSPATH.'wp-admin/includes/media.php'; require_once ABSPATH.'wp-admin/includes/template.php';
header('Content-Type: text/plain'); wp_set_current_user(1);
$MOCK=isset($_GET['mock'])?$_GET['mock']:'http://127.0.0.1:9500';
activate_plugin('ng-cloud-offloader/ng-cloud-offloader.php');
$pass=0;$fail=0; $t=function($n,$c) use(&$pass,&$fail){ if($c){$pass++;echo "PASS $n\n";}else{$fail++;echo "FAIL $n\n";} };
$mockget=function($p) use($MOCK){ return wp_remote_retrieve_body(wp_remote_get($MOCK.$p)); };
$state=function() use($mockget){ return json_decode($mockget('/__state'),true); };
$cfg=function($o) use($MOCK){ $b=array('provider'=>'s3','endpoint'=>$MOCK,'region'=>'eu-west-1','bucket'=>'media','account'=>'','public_base'=>'https://cdn.example.com','prefix'=>'wp','path_style'=>1,'rewrite'=>1,'delete_local'=>0,'auto'=>1,'key_enc'=>NGCO::enc('AKTEST'),'secret_enc'=>NGCO::enc('secretkeytest')); update_option(NGCO::OPT,array_merge($b,$o)); };
$mk=function(){ $im=imagecreatetruecolor(1400,900); for($i=0;$i<300;$i++){imagefilledrectangle($im,rand(0,1400),rand(0,900),rand(0,1400),rand(0,900),imagecolorallocate($im,rand(0,255),rand(0,255),rand(0,255)));} $f=tempnam(sys_get_temp_dir(),'t').'.jpg'; imagejpeg($im,$f,90); return $f; };
$up=function() use($mk){ return media_handle_sideload(array('name'=>'p'.rand(1000,9999).'.jpg','tmp_name'=>$mk()),0); };
$mockget('/__reset');
$t('mock reachable',$state()['keys']===array());
// encryption
$cfg(array()); $raw=get_option(NGCO::OPT); $t('credentials stored encrypted, not plaintext',strpos(json_encode($raw),'secretkeytest')===false && NGCO::dec($raw['secret_enc'])==='secretkeytest');
$t('wrong decrypt input returns empty',NGCO::dec('AAAA')==='');
// connection test through provider directly (S3)
$p=NGCO::provider(); $tmp=tempnam(sys_get_temp_dir(),'c'); file_put_contents($tmp,'hello');
$r=$p->put('wp/test.txt',$tmp,'text/plain'); $t('S3 SigV4 PUT accepted by signature-verifying server',!is_wp_error($r));
$t('S3 HEAD returns size 5',$p->head('wp/test.txt')===5);
$d=tempnam(sys_get_temp_dir(),'d'); $g=$p->get('wp/test.txt',$d); $t('S3 GET restores content',!is_wp_error($g)&&file_get_contents($d)==='hello');
$p->delete('wp/test.txt'); $t('S3 DELETE removes object',is_wp_error($p->head('wp/test.txt')));
// wrong secret
$cfg(array('secret_enc'=>NGCO::enc('wrong'))); $r=NGCO::provider()->put('wp/x.txt',$tmp,'text/plain'); $t('wrong secret rejected (403)',is_wp_error($r)&&strpos($r->get_error_message(),'403')!==false);
// auto offload on upload
$mockget('/__reset'); $cfg(array()); $id=$up(); $m=get_post_meta($id,NGCO::META,true);
$t('upload auto-offloaded, state offloaded',!empty($m['state'])&&$m['state']==='offloaded');
$files=NGCO::rel_files($id); $keys=$state()['keys']; $all=true; foreach($files as $rel=>$pth){ if(!in_array('/media/wp/'.$rel,$keys,true)) $all=false; }
$t('every size exists remotely under prefix',$all && count($files)>=4);
$t('local files kept when delete_local off',file_exists(get_attached_file($id)));
$u=wp_get_attachment_url($id); $t('URL rewritten to CDN base with prefix',strpos($u,'https://cdn.example.com/wp/')===0);
$src=wp_get_attachment_image_src($id,'medium'); $t('image src rewritten',strpos($src[0],'https://cdn.example.com/wp/')===0);
$h=wp_get_attachment_image($id,'large'); $t('srcset rewritten',strpos($h,'srcset')!==false && substr_count($h,'cdn.example.com')>=2 && strpos($h,'127.0.0.1')===false);
// restore
$orig=get_attached_file($id); $sz=filesize($orig); @unlink($orig); $r=NGCO::restore_one($id);
$t('restore re-downloads missing local file with same size',$r[0]&&file_exists($orig)&&filesize($orig)==$sz);
$t('after restore URL is local again',strpos(wp_get_attachment_url($id),'cdn.example.com')===false);
// delete attachment removes remote objects
$id2=$up(); $before=count($state()['keys']); wp_delete_attachment($id2,true); $t('deleting attachment removes remote objects',count($state()['keys'])<$before);
// verified-delete: delete_local on, success
$mockget('/__reset'); $cfg(array('delete_local'=>1)); $id3=$up(); $m3=get_post_meta($id3,NGCO::META,true); $f3=get_attached_file($id3);
$t('delete_local: local removed after verified upload',!empty($m3['local_deleted'])&&!file_exists($f3));
$t('delete_local: URL points to cloud',strpos(wp_get_attachment_url($id3),'cdn.example.com')!==false);
// truncated upload => verification fails => nothing deleted, no rewrite
$mockget('/__reset'); $mockget('/__mode?m=truncate'); $cfg(array('delete_local'=>1)); $id4=$up(); $m4=get_post_meta($id4,NGCO::META,true); $f4=get_attached_file($id4);
$t('corrupt upload detected: state partial',!empty($m4['state'])&&$m4['state']==='partial');
$t('corrupt upload: local original NOT deleted',file_exists($f4));
$t('corrupt upload: URL NOT rewritten',strpos(wp_get_attachment_url($id4),'cdn.example.com')===false);
$mockget('/__mode?m=ok');
// bad credentials on upload => no rewrite, local kept
$mockget('/__reset'); $cfg(array('delete_local'=>1,'secret_enc'=>NGCO::enc('bad'))); $id5=$up(); $t('bad credentials: local kept and URL local',file_exists(get_attached_file($id5))&&strpos(wp_get_attachment_url($id5),'cdn.example.com')===false);
// R2 provider
$mockget('/__reset'); $cfg(array('provider'=>'r2','region'=>'whatever')); $id6=$up(); $t('R2 provider offloads (region forced to auto)',NGCO::is_off($id6)&&strpos(implode(',',$state()['log']),'sig=true')!==false);
// Azure
$mockget('/__reset'); $cfg(array('provider'=>'azure','account'=>'devacct','key_enc'=>NGCO::enc('devacct'),'secret_enc'=>NGCO::enc('YXp1cmUtYWNjb3VudC1rZXktdGVzdC0wMTIzNDU2Nzg5'),'region'=>'auto'));
$id7=$up(); $m7=get_post_meta($id7,NGCO::META,true); $t('Azure SharedKey upload verified',!empty($m7['state'])&&$m7['state']==='offloaded');
$t('Azure signatures all valid',strpos(implode(',',$state()['log']),'sig=false')===false && count($state()['keys'])>=4);
$orig7=get_attached_file($id7); $s7=filesize($orig7); @unlink($orig7); $r7=NGCO::restore_one($id7); $t('Azure restore works',$r7[0]&&filesize($orig7)==$s7);
wp_delete_attachment($id7,true); $t('Azure delete removes blobs',count($state()['keys'])===0);
// no credentials
$cfg(array('provider'=>'s3','key_enc'=>'','secret_enc'=>'')); $r=NGCO::offload($up()); $t('missing credentials: clean error',$r[0]===false);
// admin page languages and XSS
foreach(array('en_US'=>'Cloud Offloader','ar'=>'نقل الملفات للسحابة','fr_FR'=>'Déport cloud','de_DE'=>'Cloud-Auslagerung') as $loc=>$w){ add_filter('ngco_locale',function()use($loc){return $loc;}); ob_start(); NGCO::page(); $o=ob_get_clean(); $t("admin page $loc",strpos($o,$w)!==false); remove_all_filters('ngco_locale'); }
$_GET['msg']='<script>alert(1)</script>'; ob_start(); NGCO::page(); $o=ob_get_clean(); $t('msg param escaped',strpos($o,'<script>alert')===false);
$t('secret never printed in settings page',strpos($o,'secretkeytest')===false&&strpos($o,'YXp1')===false);
echo "\nTOTAL pass=$pass fail=$fail\n";
