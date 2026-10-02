const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../public/dashboard.js'), 'utf8');
function fixture() {
  const nodes = Object.fromEntries(['release-upload', 'release-upload-progress', 'release-upload-status', 'release-upload-feedback'].map(id => [id, {dataset:{}, value:0, hidden:true, setAttribute(k,v){this[k]=v;}, removeAttribute(k){delete this[k];}}]));
  const controls = [{disabled:false}, {disabled:true}]; const events={}; const requests=[]; const redirects=[];
  nodes['release-upload'].querySelectorAll=()=>controls;
  nodes['release-upload'].reportValidity=()=>true;
  nodes['release-upload'].addEventListener=(event, fn)=>events[event]=fn;
  class XHR {constructor(){this.upload={};requests.push(this);} open(){} setRequestHeader(){} send(body){this.body=body;} }
  vm.runInNewContext(script, {document:{querySelector:s=>nodes[s.slice(1)]||null,querySelectorAll:()=>[]},window:{addEventListener:(e,fn)=>events[e]=fn},location:{assign:u=>redirects.push(u)},XMLHttpRequest:XHR,FormData:class {constructor(){assert.equal(controls[0].disabled,false);this.files=['zip','manifest'];}get(){return 'myapp';}}});
  return {nodes,controls,events,requests,redirects,submit:()=>events.submit({preventDefault(){}})};
}
test('真实上传进度与服务端校验分开，重复点击只提交一次',()=>{
 const f=fixture(); f.submit();f.submit();assert.equal(f.requests.length,1);
 assert.equal(f.controls[0].disabled,true);assert.equal(f.requests[0].body.files.length,2);
 f.requests[0].upload.onprogress({lengthComputable:true,loaded:25,total:100});
 assert.equal(f.nodes['release-upload-progress'].value,25);assert.match(f.nodes['release-upload-status'].textContent,/25%/);
 f.requests[0].upload.onload();assert.equal(f.nodes['release-upload-feedback'].dataset.state,'validating');
 assert.equal(f.nodes['release-upload-progress'].value,undefined);assert.equal(f.redirects.length,0);
 f.requests[0].status=201;f.requests[0].responseText='{"ok":true}';f.requests[0].onload();
 assert.equal(f.nodes['release-upload-feedback'].dataset.state,'success');assert.equal(f.nodes['release-upload-progress'].value,100);
 assert.deepEqual(f.redirects,['/?view=releases&project=myapp#release-project-myapp']);assert.equal(f.controls[0].disabled,false);assert.equal(f.controls[1].disabled,true);
});
test('校验错误显示原因且不跳转或冒充成功',()=>{
 const f=fixture();f.submit();const x=f.requests[0];x.upload.onload();x.status=400;x.responseText='{"error":"ZIP SHA-256 不一致"}';x.onload();
 assert.match(f.nodes['release-upload-status'].textContent,/SHA-256/);assert.equal(f.nodes['release-upload-feedback'].dataset.state,'failed');assert.equal(f.redirects.length,0);assert.equal(f.controls[0].disabled,false);
});
for(const event of ['onerror','ontimeout','onabort']) test(event+' 显示未确认状态，允许恢复操作',()=>{
 const f=fixture();f.submit();f.requests[0][event]();assert.equal(f.nodes['release-upload-feedback'].dataset.state,'failed');assert.equal(f.redirects.length,0);assert.equal(f.controls[0].disabled,false);
});
test('非JSON/登录过期/错误成功结构不被当作上传成功',()=>{
 for(const [status,body] of [[401,'请先登录'],[413,'<html>too large</html>'],[200,'{"ok":true}'],[201,'null']]){
 const f=fixture();f.submit();const x=f.requests[0];x.status=status;x.responseText=body;x.onload();assert.equal(f.nodes['release-upload-feedback'].dataset.state,'failed');assert.equal(f.redirects.length,0);
 }
});
test('无法计算进度时不虚构百分比，上传中离开页面有提醒',()=>{
 const f=fixture();f.submit();f.requests[0].upload.onprogress({lengthComputable:false});assert.equal(f.nodes['release-upload-progress'].value,undefined);
 let prevented=false;f.events.beforeunload({preventDefault(){prevented=true;}});assert.equal(prevented,true);
 f.requests[0].onerror();prevented=false;f.events.beforeunload({preventDefault(){prevented=true;}});assert.equal(prevented,false);
});
