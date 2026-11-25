// Mock S3 + Azure Blob server that VERIFIES signatures independently. Fault injection via /__mode?m=truncate|ok
const http=require('http'),crypto=require('crypto');
const AK='AKTEST',SK='secretkeytest',AZACCT='devacct',AZKEY=Buffer.from('azure-account-key-test-0123456789').toString('base64');
const store=new Map(); let mode='ok'; const log=[];
const hmac=(k,d)=>crypto.createHmac('sha256',k).update(d).digest(); const sha=d=>crypto.createHash('sha256').update(d).digest('hex');
function s3ok(req,body,path){const a=req.headers.authorization||'';const m=/Credential=([^\/]+)\/(\d{8})\/([^\/]+)\/s3\/aws4_request, SignedHeaders=([^,]+), Signature=(\w+)/.exec(a);if(!m)return false;
 if(m[1]!==AK)return false;const [_,ak,date,region,signed,sig]=m;const amz=req.headers['x-amz-date'];
 if(req.headers['x-amz-content-sha256']!==sha(body))return false;
 const ch=signed.split(';').map(h=>h+':'+String(req.headers[h]).trim()+'\n').join('');
 const creq=[req.method,path,'',ch,signed,sha(body)].join('\n');
 const sts=['AWS4-HMAC-SHA256',amz,`${date}/${region}/s3/aws4_request`,sha(creq)].join('\n');
 const k=hmac(hmac(hmac(hmac('AWS4'+SK,date),region),'s3'),'aws4_request');
 return crypto.createHmac('sha256',k).update(sts).digest('hex')===sig;}
function azok(req,body,urlpath){const a=req.headers.authorization||'';const m=/^SharedKey (\w+):(.+)$/.exec(a);if(!m||m[1]!==AZACCT)return false;
 const xms=Object.keys(req.headers).filter(h=>h.startsWith('x-ms-')).sort().map(h=>h+':'+req.headers[h]+'\n').join('');
 const cl=body.length>0?String(body.length):'';const ct=req.headers['content-type']||'';
 const sts=`${req.method}\n\n\n${cl}\n\n${ct}\n\n\n\n\n\n\n${xms}/${AZACCT}${urlpath}`;
 return crypto.createHmac('sha256',Buffer.from(AZKEY,'base64')).update(sts).digest('base64')===m[2];}
http.createServer((req,res)=>{const chunks=[];req.on('data',c=>chunks.push(c));req.on('end',()=>{const body=Buffer.concat(chunks);
 const u=new URL(req.url,'http://x');
 if(u.pathname==='/__mode'){mode=u.searchParams.get('m');res.end('ok');return;}
 if(u.pathname==='/__state'){res.setHeader('content-type','application/json');res.end(JSON.stringify({keys:[...store.keys()],log}));return;}
 if(u.pathname==='/__reset'){store.clear();log.length=0;mode='ok';res.end('ok');return;}
 const isAz=!!req.headers['x-ms-version'];const raw=u.pathname;let okSig=isAz?azok(req,body,decodeURIComponent(raw)):s3ok(req,body,raw);
 log.push(req.method+' '+raw+' '+(isAz?'az':'s3')+' sig='+okSig);
 if(!okSig){res.statusCode=403;res.end('<Error>SignatureDoesNotMatch</Error>');return;}
 const key=decodeURIComponent(raw);
 if(req.method==='PUT'){ if(mode==='truncate'&&!key.endsWith('.txt')&&body.length>10)store.set(key,{b:body.subarray(0,body.length-5),t:req.headers['content-type']});else store.set(key,{b:body,t:req.headers['content-type']});res.statusCode=isAz?201:200;res.end();return;}
 const o=store.get(key);
 if(req.method==='DELETE'){store.delete(key);res.statusCode=isAz?202:204;res.end();return;}
 if(!o){res.statusCode=404;res.end('<Error>NotFound</Error>');return;}
 res.setHeader('content-length',o.b.length);if(req.method==='HEAD'){res.end();return;}res.end(o.b);
});}).listen(+process.env.PORT||9500,'0.0.0.0',()=>console.log('mock up'));
