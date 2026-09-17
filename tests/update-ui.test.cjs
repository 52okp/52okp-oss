const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '../public/update.js'), 'utf8');
const flush = () => new Promise(resolve => setImmediate(resolve));
const details = {
  idle: [0, '等待开始', false], queued: [10, '已提交，等待本地服务处理', true],
  checking: [null, '正在连接 GitHub、查询最新版本', true], downloading: [25, '下载校验', true],
  validating: [60, '检查代码', true], switching: [85, '切换版本', true],
  checked: [100, '版本检查完成', false], success: [100, '程序更新完成', false], failed: [0, '任务失败', false],
};
function status(phase = 'idle', job = '', options = {}) {
  const [percent, label, active] = details[phase];
  return {connected: true, current: 'build-100-1', state: {job, phase, task: phase === 'checking' ? 'check' : 'install', message: `状态：${phase}`, started: Math.floor(Date.now() / 1000) - 5, updated: Math.floor(Date.now() / 1000), latest: {tag: 'build-200-1'}, progress: {percent, label, active}}, ...options};
}
function fixture() {
  class Element {
    constructor() { this.textContent = ''; this.disabled = false; this.value = 0; this.dataset = {}; this.events = {}; this.children = []; this.attributes = {}; this.hasValue = true; }
    addEventListener(name, callback) { this.events[name] = callback; }
    append(child) { this.children.push(child); }
    setAttribute(name, value) { this.attributes[name] = value; }
    removeAttribute(name) { if (name === 'value') this.hasValue = false; }
  }
  const nodes = Object.fromEntries(['update-form','check-update','install-update','update-message','update-tag','update-progress','update-stage','update-percent','update-task','update-elapsed','update-job','current-version','latest-version','refresh-update-status'].map(id => [id, new Element()]));
  const calls = []; const intervals = []; const timeouts = [];
  const context = {document: {hidden: false, querySelector: selector => nodes[selector.slice(1)], querySelectorAll: () => [], createElement: () => new Element()},
    AbortController, Date, console,
    FormData: class { constructor() { this.data = new Map(); } set(key, value) { this.data.set(key, value); } },
    confirm: () => true,
    setTimeout: callback => { timeouts.push(callback); return timeouts.length; }, clearTimeout: () => {},
    setInterval: callback => { intervals.push(callback); },
    fetch: (url, options) => new Promise((resolve, reject) => { calls.push({url, options, resolve, reject}); }),
  };
  vm.runInNewContext(script, context);
  function reply(index, data, code = 200, type = 'application/json') { calls[index].resolve({ok: code >= 200 && code < 300, status: code, headers: {get: () => type}, json: async () => data}); }
  async function submit(action) { return nodes['update-form'].events.submit({preventDefault() {}, submitter: {value: action}}); }
  return {nodes, calls, intervals, timeouts, reply, submit};
}

test('检查阶段显示动态进度，完成后显示100%', async () => {
  const f = fixture(); f.reply(0, status('checking', 'job-one')); await flush();
  assert.equal(f.nodes['update-progress'].hasValue, false);
  assert.equal(f.nodes['update-percent'].textContent, '处理中');
  assert.equal(f.nodes['check-update'].disabled, true);
  assert.match(f.nodes['update-elapsed'].textContent, /已等待/);
  f.intervals[0](); f.reply(1, status('checked', 'job-one')); await flush();
  assert.equal(f.nodes['update-progress'].value, 100);
  assert.equal(f.nodes['install-update'].disabled, false);
});

test('点击立即显示提交进度，服务响应后显示队列和任务编号', async () => {
  const f = fixture(); f.reply(0, status()); await flush();
  const pending = f.submit('check_update');
  assert.match(f.nodes['update-stage'].textContent, /正在提交/);
  assert.equal(f.nodes['check-update'].disabled, true);
  assert.equal(f.calls[1].options.body.data.get('action'), 'check_update');
  f.reply(1, {job: 'new-job', status: status('queued', 'new-job')}, 202); await pending;
  assert.equal(f.nodes['update-progress'].value, 10);
  assert.match(f.nodes['update-job'].textContent, /new-job/);
});

test('提交前发出的旧轮询不能覆盖提交反馈', async () => {
  const f = fixture(); const pending = f.submit('check_update');
  f.reply(0, status('checked', 'old-job')); await flush();
  assert.match(f.nodes['update-stage'].textContent, /正在提交/);
  f.reply(1, {job: 'new-job', status: status('queued', 'new-job')}, 202); await pending;
  f.intervals[0](); const stale = status('checked', 'old-job'); stale.state.updated = 1; f.reply(2, stale); await flush();
  assert.match(f.nodes['update-job'].textContent, /new-job/);
});

test('网络同步失败显示错误，不错误宣称服务器任务失败', async () => {
  const f = fixture(); f.reply(0, status('downloading', 'job-one')); await flush();
  f.intervals[0](); f.calls[1].reject(new Error('连接失败')); await flush();
  assert.match(f.nodes['update-message'].textContent, /可能仍在执行/);
  assert.equal(f.nodes['update-progress'].value, 25);
  assert.equal(f.nodes['check-update'].disabled, true);
  f.intervals[0](); f.reply(2, status('success', 'job-one')); await flush();
  assert.equal(f.nodes['update-progress'].value, 100);
  assert.equal(f.nodes['update-task'].children[0].hidden, false);
});

test('任务失败显示错误且不显示100%成功', async () => {
  const f = fixture(); const data = status('failed', 'job-one'); data.state.message = '下载失败';
  f.reply(0, data); await flush();
  assert.equal(f.nodes['update-progress'].value, 0);
  assert.match(f.nodes['update-message'].textContent, /下载失败/);
  assert.equal(f.nodes['update-task'].children[0].hidden, true);
});

test('非JSON和登录失效响应均显示明确反馈', async () => {
  for (const [code, type, pattern] of [[200, 'text/html', /未返回更新状态/], [401, 'text/plain', /重新登录/]]) {
    const f = fixture(); f.reply(0, {}, code, type); await flush();
    assert.match(f.nodes['update-message'].textContent, pattern);
  }
});

test('无法连接本地服务时禁用任务按钮但允许刷新状态', async () => {
  const f = fixture(); f.reply(0, status('idle', '', {connected: false})); await flush();
  assert.equal(f.nodes['check-update'].disabled, true);
  assert.equal(f.nodes['install-update'].disabled, true);
  assert.equal(f.nodes['refresh-update-status'].disabled, false);
});

test('等待任务时重复点击不会创建第二个任务', async () => {
  const f = fixture(); f.reply(0, status('queued', 'job-one')); await flush();
  await f.submit('check_update');
  assert.equal(f.calls.length, 1);
  f.nodes['refresh-update-status'].events.click();
  assert.equal(f.calls[1].url, '/?api=updates');
});
