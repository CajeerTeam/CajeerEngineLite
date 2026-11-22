'use strict';
(() => {
    const form = document.getElementById('ai-chat-send');
    if (!form) return;
    const messages = document.getElementById('ai-chat-messages');
    const status = document.getElementById('ai-chat-status');
    const sendButton = document.getElementById('ai-chat-submit');
    const stopButton = document.getElementById('ai-chat-stop');
    let active = null;

    document.querySelectorAll('[data-confirm]').forEach(element => {
        element.addEventListener('submit', event => {
            if (!window.confirm(element.getAttribute('data-confirm'))) event.preventDefault();
        });
    });
    const append = (role, content) => {
        const item = document.createElement('article');
        item.className = `ai-message ai-message-${role}`;
        const who = document.createElement('strong');
        who.textContent = role === 'user' ? 'Вы' : 'Ассистент';
        const text = document.createElement('p');
        text.textContent = content;
        item.append(who, text);
        messages.append(item);
        messages.scrollTop = messages.scrollHeight;
        return text;
    };
    const setBusy = busy => {
        sendButton.disabled = busy;
        stopButton.hidden = !busy;
        form.elements.message.disabled = busy;
    };
    stopButton.addEventListener('click', async () => {
        status.textContent = 'Останавливаем запрос…';
        const data = new FormData(form);
        data.set('action', 'cancel');
        data.delete('message');
        try { await fetch('/admin/ai', { method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store' }); }
        catch (_) { /* The reload will show the authoritative server state. */ }
        if (active) active.abort();
        window.location.reload();
    });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (active) return;
        const text = form.elements.message.value;
        if (!text.trim()) return;
        const data = new FormData(form);
        const controller = new AbortController();
        active = controller;
        setBusy(true);
        append('user', text);
        form.elements.message.value = '';
        const assistant = append('assistant', '');
        status.textContent = 'Генерация ответа…';
        let completed = false;
        try {
            const response = await fetch('/admin/ai', {
                method: 'POST', body: data, headers: { Accept: 'application/x-ndjson' },
                credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
            });
            if (!response.ok || !response.body) throw new Error(`HTTP ${response.status}`);
            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8', { fatal: true });
            let buffer = '';
            const consume = eventData => {
                const info = JSON.parse(eventData);
                if (info.type === 'delta' && typeof info.text === 'string') {
                    assistant.textContent += info.text;
                    messages.scrollTop = messages.scrollHeight;
                } else if (info.type === 'done') {
                    completed = true;
                    status.textContent = `Готово · ${info.model} · ${info.input_tokens} + ${info.output_tokens} токенов`;
                } else if (info.type === 'error') {
                    throw new Error(info.message || 'Не удалось завершить запрос');
                }
            };
            for (;;) {
                const { value, done } = await reader.read();
                if (done) break;
                buffer += decoder.decode(value, { stream: true });
                if (buffer.length > 200000) throw new Error('Слишком большой ответ');
                let end;
                while ((end = buffer.indexOf('\n')) >= 0) {
                    const line = buffer.slice(0, end);
                    buffer = buffer.slice(end + 1);
                    if (line.trim()) consume(line);
                }
            }
            if (!completed) throw new Error('Ответ OpenAI оборвался');
            window.location.reload();
        } catch (error) {
            if (!controller.signal.aborted) {
                status.textContent = `Ошибка: ${error.message}. Обновите диалог и повторите запрос.`;
                assistant.textContent ||= 'Ответ не получен.';
            }
        } finally {
            active = null;
            setBusy(false);
        }
    });
})();
