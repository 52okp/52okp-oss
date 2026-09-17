const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const script = fs.readFileSync(require('node:path').join(__dirname, '../public/dashboard.js'), 'utf8');
class Element {
  constructor() { this.value = ''; this.dataset = {}; this.events = {}; this.children = []; this.hidden = false; this.disabled = false; this.textContent = ''; this.classList = {add() {}, remove() {}}; }
  addEventListener(name, fn) { (this.events[name] ||= []).push(fn); }
  async fire(name, extra = {}) { for (const fn of this.events[name] || []) await fn({preventDefault() {}, ...extra}); }
  append(...children) { for (const child of children) { this.children = this.children.filter(old => old !== child); this.children.push(child); } }
  replaceChildren() { this.children = []; }
  setAttribute() {}
}
function fixture(mode) {
  const nodes = {}; const requests = [];
  function node(id) { return nodes[id] ||= new Element(); }
  if (mode === 'table') {
    const rows = Array.from({length: 25}, (_, index) => {
      const row = new Element(); row.dataset = {name: `文件${String(index).padStart(2,'0')}${index % 2 ? '.pdf' : '.jpg'}`,size:String(index + 1),time:`2026-09-${String(index + 1).padStart(2,'0')}T00:00:00Z`,type:index % 2 ? 'document' : 'image'};
      row.querySelector = () => ({dateTime:row.dataset.time}); return row;
    });
    node('file-rows').children = rows; nodes['file-rows'].querySelectorAll = () => rows;
    for (const id of ['file-search','file-type','file-sort','page-size','empty-files','empty-files h3','list-count','page-label','previous-page','next-page']) node(id);
    nodes['file-type'].value = 'all'; nodes['file-sort'].value = 'newest'; nodes['page-size'].value = '10';
  } else {
    for (const id of ['upload','upload-file','dropzone','upload-button','upload-queue','result','selected-files']) node(id);
    nodes.upload.dataset.maxBytes = '100'; nodes['upload-file'].files = [];
  }
  class XHR {
    constructor() { this.upload = {}; requests.push(this); }
    open() {} setRequestHeader() {}
    send(body) { this.body = body; }
  }
  vm.runInNewContext(script, {document:{querySelector:selector => nodes[selector.slice(1)] || null,createElement:() => new Element()},window:{addEventListener(){}},location:{origin:'https://example.com'},URL,Date,Number,navigator:{clipboard:{writeText:async()=>{}}},XMLHttpRequest:XHR,FormData:class {constructor(){this.fields={};} set(key,value){this.fields[key]=value;}}});
  return {nodes,requests};
}
const flush = () => new Promise(resolve => setImmediate(resolve));
test('文件分页、大小排序与类型筛选', async () => {
  const {nodes:n} = fixture('table');
  assert.equal(n['file-rows'].children.filter(row=>!row.hidden).length,10);
  await n['next-page'].fire('click'); assert.equal(n['page-label'].textContent,'2 / 3');
  n['file-sort'].value='largest'; await n['file-sort'].fire('change');
  assert.equal(n['file-rows'].children.filter(row=>!row.hidden)[0].dataset.size,'25');
  n['file-type'].value='document'; await n['file-type'].fire('change');
  assert.equal(n['page-label'].textContent,'1 / 2');
  assert.ok(n['file-rows'].children.filter(row=>!row.hidden).every(row=>row.dataset.type==='document'));
});
test('搜索无结果展示空状态并重置页码', async () => {
  const {nodes:n} = fixture('table'); n['file-search'].value='不存在'; await n['file-search'].fire('input');
  assert.equal(n['empty-files'].hidden,false); assert.equal(n['next-page'].disabled,true);
  n['file-search'].value='文件00'; await n['file-search'].fire('input');
  assert.equal(n['file-rows'].children.filter(row=>!row.hidden).length,1);
});
test('多文件逐个上传且重复提交不会启动第二队列', async () => {
  const {nodes:n,requests:r} = fixture('upload');
  n['upload-file'].files=[{name:'a.txt',size:10},{name:'b.txt',size:20}]; await n['upload-file'].fire('change');
  const submitting=n.upload.fire('submit'); assert.equal(r.length,1);
  await n.upload.fire('submit'); assert.equal(r.length,1);
  r[0].status=200; r[0].responseText=JSON.stringify({url:'https://example.com/d/a'}); r[0].onload(); await flush();
  assert.equal(r.length,2); assert.equal(r[1].body.fields.file.name,'b.txt');
  r[1].status=200; r[1].responseText=JSON.stringify({url:'https://example.com/d/b'}); r[1].onload(); await submitting;
  assert.match(n.result.textContent,/2 个成功/); assert.equal(n['upload-button'].disabled,false);
});
test('超大文件拒绝但继续上传下一文件，错误响应不标成功', async () => {
  const {nodes:n,requests:r} = fixture('upload');
  await n.dropzone.fire('drop',{dataTransfer:{files:[{name:'large.zip',size:101},{name:'small.txt',size:10}]}});
  const submitting=n.upload.fire('submit'); await flush(); assert.equal(r.length,1);
  r[0].status=413; r[0].responseText='too large'; r[0].onload(); await submitting;
  assert.match(n.result.textContent,/0 个成功/); assert.equal(n['upload-queue'].children[0].dataset.state,'failed');
  assert.equal(n['upload-queue'].children[1].dataset.state,'failed');
});
