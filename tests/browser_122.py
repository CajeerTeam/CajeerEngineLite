#!/usr/bin/env python3
"""Chromium chat UI acceptance. OpenAI HTTP response is simulated, not live delivery."""
import json
import shutil
import subprocess
from pathlib import Path
from playwright.sync_api import sync_playwright

root = Path(__file__).resolve().parent.parent
render = r'''require "bootstrap.php";
$site=["enabled"=>true,"model"=>"gpt-5-mini","max_input_chars"=>3000];
$chat=["id"=>str_repeat("a",32),"title"=>"Диалог", "messages"=>[["role"=>"assistant","content"=>"Предыдущий ответ <script>alert(1)</script>","at"=>time()]],"pending"=>null];
echo \CajeerEngine\AI\ChatView::render([["id"=>$chat["id"],"title"=>"Диалог","updated"=>time()]],$chat,str_repeat("b",64),$site,true);'''
markup = subprocess.check_output(['php', '-r', render], cwd=root, text=True)
assert '&lt;script&gt;' in markup
source = (root/'public/assets/ai-chat.js').read_text().replace('window.location.reload();',
    'window.__reloadCount=(window.__reloadCount||0)+1;')
with sync_playwright() as pw:
    browser = pw.chromium.launch(executable_path=shutil.which('chromium') or pw.chromium.executable_path,
        headless=True, args=['--no-sandbox','--disable-gpu'])
    page = browser.new_page()
    errors = []
    page.on('pageerror', lambda e: errors.append(str(e)))
    page.set_content('<!doctype html><html><body>'+markup+'</body></html>')
    page.evaluate('''() => {
      window.__requests=[];
      window.fetch=async(url,opts)=>{
        window.__requests.push({url,body:[...opts.body.entries()],accept:opts.headers?.Accept});
        const events=[{type:'delta',text:'Стрим '},{type:'delta',text:'от OpenAI'},
          {type:'done',model:'gpt-5-mini',input_tokens:17,output_tokens:5}];
        const encoder=new TextEncoder();
        const stream=new ReadableStream({start(controller){
          for (const event of events) controller.enqueue(encoder.encode(JSON.stringify(event)+'\\n'));
          controller.close();
        }});
        return {ok:true,body:stream};
      }
    }''')
    page.evaluate('src => {const script=document.createElement("script");script.textContent=src;document.body.append(script)}',source)
    page.locator('#ai-chat-message').fill('<b>Тестовый запрос</b>')
    page.locator('#ai-chat-submit').click()
    page.wait_for_function('window.__reloadCount===1')
    assert page.locator('.ai-message-user p').last.text_content()=='<b>Тестовый запрос</b>'
    assert page.locator('.ai-message-assistant p').last.text_content()=='Стрим от OpenAI'
    assert page.locator('.ai-message-assistant b').count()==0, 'injected HTML executed'
    requests = page.evaluate('window.__requests')
    assert len(requests)==1 and requests[0]['url']=='/admin/ai' and requests[0]['accept']=='application/x-ndjson'
    assert ['message','<b>Тестовый запрос</b>'] in [list(pair) for pair in requests[0]['body']]
    assert errors==[], errors
    browser.close()
print('Lite 1.2.2 Chromium streaming chat, safe text and POST interaction OK (provider mocked)')
