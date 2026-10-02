const test = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const path = require('node:path');
test('删除确认包含项目版本，取消时阻止请求', () => {
  for (const confirmed of [false, true]) {
    let submit, message, prevented = false;
    const form = {dataset: {project:'myapp',version:'1.0.0'},addEventListener: (_, fn) => { submit = fn; }};
    vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../public/dashboard.js'), 'utf8'), {
      document: {querySelector: () => null, querySelectorAll: selector => selector === '.release-delete-form' ? [form] : []},
      confirm: text => { message = text; return confirmed; }
    });
    submit({preventDefault(){prevented=true;}});
    assert.equal(prevented, !confirmed); assert.match(message, /myapp v1.0.0/); assert.match(message, /无法撤销/);
  }
});
