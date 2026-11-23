/* CajeerEngine Lite 1.3.3 visual block editor. All generated DOM uses textContent. */
(() => {
  'use strict';
  const form=document.querySelector('form[data-block-editor]');
  if (!form) return;
  const source=form.querySelector('#cms-block-json');
  const host=form.querySelector('#cms-blocks');
  const live=form.querySelector('#cms-live-preview');
  const status=form.querySelector('#cms-draft-status');
  const addType=form.querySelector('#cms-add-type');
  const addButton=form.querySelector('#cms-add-block');
  const undoButton=form.querySelector('#cms-undo');
  const redoButton=form.querySelector('#cms-redo');
  const previewButton=form.querySelector('#cms-preview-button');
  const saveButton=form.querySelector('#cms-save-button');
  const initial=JSON.parse(source.value);
  let blocks=initial;
  let history=[JSON.stringify(blocks)], cursor=0;
  const key='cajeer:editor:133:'+form.elements.old.value+':'+form.elements.revision.value;
  const make=(tag,cls,content) => {
    const node=document.createElement(tag);
    if (cls) node.className=cls;
    if (content!==undefined) node.textContent=content;
    return node;
  };
  const draft=()=>{
    try { sessionStorage.setItem(key,JSON.stringify(blocks)); }
    catch (_) { /* storage disabled, editing still works */ }
  };
  const updateButtons=()=>{
    undoButton.disabled=cursor===0;
    redoButton.disabled=cursor>=history.length-1;
    addButton.disabled=blocks.length>=120;
    source.value=JSON.stringify(blocks);
    status.textContent=blocks.length+' блок(ов). Локальный черновик сохраняется в этой вкладке.';
  };
  const remember=()=>{
    const state=JSON.stringify(blocks);
    if (history[cursor]===state) {updateButtons();return;}
    history=history.slice(0,cursor+1);history.push(state);
    if (history.length>30) history.shift();
    cursor=history.length-1;
    draft(); updateButtons();
  };
  const safeURL=(url)=> /^\/(?!\/)[^\s\\]*$/.test(url) || /^https:\/\/[^\s\\]+$/i.test(url);
  const textInput=(name,value,onInput,rows=3)=>{
    const label=make('label','cms-block-field',name);
    const control=rows>1?make('textarea'):make('input');
    if (rows>1) control.rows=rows;
    control.value=value;
    control.maxLength=rows>1?60000:2048;
    control.addEventListener('input',()=>{onInput(control.value); drawPreview(); updateButtons();});
    control.addEventListener('change',remember);
    label.append(control);return label;
  };
  const select=(name,options,current,onChange)=>{
    const label=make('label','cms-block-field',name);
    const control=make('select');
    for (const [value,title] of options) {
      const option=make('option','',title);option.value=String(value);
      if (String(value)===String(current)) option.selected=true;
      control.append(option);
    }
    control.addEventListener('change',()=>onChange(control.value));
    label.append(control);return label;
  };
  const defaults=(type,text='')=>{
    if (type==='heading') return {type,level:2,text};
    if (type==='list') return {type,ordered:false,items:text?text.split('\n'):['']};
    if (type==='link'||type==='button') return {type,text,url:'/'};
    if (type==='divider') return {type};
    return {type,text};
  };
  const types=[['paragraph','Абзац'],['heading','Заголовок'],['list','Список'],
    ['quote','Цитата'],['link','Ссылка'],['button','Кнопка'],['divider','Разделитель']];
  function drawEditor() {
    host.replaceChildren();
    blocks.forEach((block,i)=>{
      const card=make('div','cms-edit-block');
      card.dataset.index=String(i);
      const heading=make('div','cms-edit-block-head');
      const number=make('strong','',`Блок ${i+1}`);
      heading.append(number,select('Тип',types,block.type,(type)=>{
        const txt=block.type==='list'?block.items.join('\n'):(block.text||'');
        blocks[i]=defaults(type,txt);remember();drawEditor();drawPreview();
      }));
      for(const [symbol,label,run,disabled] of [
        ['↑','Переместить выше',()=>{[blocks[i-1],blocks[i]]=[blocks[i],blocks[i-1]];},i===0],
        ['↓','Переместить ниже',()=>{[blocks[i+1],blocks[i]]=[blocks[i],blocks[i+1]];},i===blocks.length-1],
        ['×','Удалить блок',()=>{blocks.splice(i,1);},blocks.length===1]]) {
        const button=make('button','cms-block-action',symbol);
        button.type='button';button.title=label;button.setAttribute('aria-label',label+' '+(i+1));
        button.disabled=disabled;
        button.addEventListener('click',()=>{run();remember();drawEditor();drawPreview();});
        heading.append(button);
      }
      card.append(heading);
      if (block.type==='heading') card.append(select('Уровень',[2,3,4,5,6].map(x=>[x,'H'+x]),block.level,(level)=>{
        block.level=Number(level);remember();drawPreview();
      }));
      if(block.type==='list'){
        const label=make('label','cms-block-checkbox');
        const checkbox=make('input');checkbox.type='checkbox';checkbox.checked=block.ordered;
        checkbox.addEventListener('change',()=>{block.ordered=checkbox.checked;remember();drawPreview();});
        label.append(checkbox,document.createTextNode(' Нумерованный список'));
        card.append(label,textInput('Пункты (каждый с новой строки)',block.items.join('\n'),value=>{
          block.items=value.split('\n');
        },4));
      } else if(block.type==='link'||block.type==='button') {
        card.append(textInput('Подпись',block.text,value=>{block.text=value;},1),
          textInput('Адрес (внутренний /... или HTTPS)',block.url,value=>{block.url=value;},1));
      } else if(block.type!=='divider') {
        card.append(textInput('Текст',block.text,value=>{block.text=value;},block.type==='heading'?1:4));
      }
      host.append(card);
    });
  }
  function drawPreview() {
    live.replaceChildren();
    for (const block of blocks) {
      let node;
      if(block.type==='divider') node=make('hr');
      else if(block.type==='heading') node=make('h'+block.level,'cms-text',block.text);
      else if(block.type==='quote') node=make('blockquote','cms-text',block.text);
      else if(block.type==='list'){
        node=make(block.ordered?'ol':'ul');
        for(const item of block.items) node.append(make('li','cms-text',item));
      }else if(block.type==='link'||block.type==='button'){
        node=make('p');
        const label=make('span',block.type==='button'?'cms-button':'cms-link',block.text);
        label.title=safeURL(block.url)?block.url:'Неверный URL';
        node.append(label);
      }else node=make('p','cms-text',block.text);
      live.append(node);
    }
    updateButtons();
  }
  addButton.addEventListener('click',()=>{
    if (blocks.length>=120) return;
    blocks.push(defaults(addType.value));remember();drawEditor();drawPreview();
    host.lastElementChild?.scrollIntoView({block:'nearest',behavior:'smooth'});
  });
  undoButton.addEventListener('click',()=>{
    if(cursor>0){blocks=JSON.parse(history[--cursor]);drawEditor();drawPreview();draft();}
  });
  redoButton.addEventListener('click',()=>{
    if(cursor<history.length-1){blocks=JSON.parse(history[++cursor]);drawEditor();drawPreview();draft();}
  });
  form.addEventListener('submit',event=>{
    source.value=JSON.stringify(blocks);
    const button=event.submitter;
    if(button===saveButton && !form.checkValidity()) return;
    if (button===saveButton) {
      // Clear only after successful redirect; leaving it here protects edits on server errors.
      draft();
    }
  });
  const saved=(()=>{try{return sessionStorage.getItem(key);}catch(_){return null;}})();
  if(saved && saved!==JSON.stringify(initial)) {
    const restore=make('button','cms-draft-restore','Восстановить локальный черновик');restore.type='button';
    restore.addEventListener('click',()=>{
      try {const candidate=JSON.parse(saved);if(!Array.isArray(candidate)||candidate.length>120) return;
        blocks=candidate;history=[JSON.stringify(blocks)];cursor=0;drawEditor();drawPreview();restore.remove();}
      catch(_) {restore.remove();}
    });
    status.after(restore);
  }
  drawEditor();drawPreview();
  previewButton.disabled=false;saveButton.disabled=false;
})();
