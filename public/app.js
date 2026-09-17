document.querySelectorAll('.delete').forEach(form => form.addEventListener('submit', event => { if (!confirm('确定删除该文件？删除后下载链接立即失效。')) event.preventDefault(); }));
document.querySelectorAll('.copy').forEach(button => button.addEventListener('click', async () => { try { await navigator.clipboard.writeText(button.previousElementSibling.value); button.textContent = '已复制'; } catch { button.previousElementSibling.select(); button.textContent = '请手动复制'; } }));
const form = document.querySelector('#upload');
form?.addEventListener('submit', event => {
  event.preventDefault(); const button = form.querySelector('button'); button.disabled = true;
  const result = document.querySelector('#result'); result.textContent = '正在上传…';
  const xhr = new XMLHttpRequest(); xhr.open('POST', '/'); xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
  xhr.upload.onprogress = event => { if (event.lengthComputable) document.querySelector('#progress').value = event.loaded / event.total * 100; };
  xhr.onload = () => { button.disabled = false; if (xhr.status === 200) { try { const data = JSON.parse(xhr.responseText); result.textContent = `上传成功：${data.url}`; const copy = document.createElement('button'); copy.textContent = '复制直链'; copy.onclick = () => navigator.clipboard.writeText(data.url).catch(() => { result.textContent = data.url; }); result.append(copy); const link = document.createElement('a'); link.href = '/'; link.textContent = '刷新文件列表'; result.append(link); } catch { result.textContent = '服务器响应异常，请刷新列表确认。'; } } else result.textContent = '上传失败，请检查大小限制、登录状态或服务器日志。'; };
  xhr.onerror = () => { button.disabled = false; result.textContent = '网络异常，请刷新列表确认后重试。'; }; xhr.send(new FormData(form));
});
