/**
 * AI Chat backend module — sends messages via fetch and renders responses.
 * Supports SSE streaming for real-time updates.
 */
class ChatModule {
    constructor() {
        this.container = document.getElementById('chat-container');
        if (!this.container) return;

        this.sendUri = this.container.dataset.sendUri;
        this.streamUri = this.container.dataset.streamUri;
        this.messagesEl = document.getElementById('chat-messages');
        this.form = document.getElementById('chat-form');
        this.input = document.getElementById('chat-input');
        this.sendBtn = document.getElementById('chat-send-btn');
        this.errorEl = document.getElementById('chat-error');

        this.form.addEventListener('submit', (e) => this.onSubmit(e));
        this.input.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.form.requestSubmit();
            }
        });

        this.scrollToBottom();

        if (this.container.dataset.autoStart === '1' && this.streamUri) {
            this.autoStart();
        }
    }

    async autoStart() {
        const prompt = this.container.dataset.initialPrompt || '';
        if (prompt) {
            this.appendMessage({ role: 'user', content: prompt });
        }
        this.setLoading(true);
        await this.sendStreaming('');
        this.setLoading(false);
        this.input.focus();
    }

    async onSubmit(event) {
        event.preventDefault();
        const message = this.input.value.trim();
        if (!message) return;

        this.errorEl.style.display = 'none';
        this.appendMessage({ role: 'user', content: message });
        this.input.value = '';
        this.setLoading(true);

        if (this.streamUri) {
            await this.sendStreaming(message);
        } else {
            await this.sendBlocking(message);
        }

        this.setLoading(false);
        this.input.focus();
    }

    async sendBlocking(message) {
        try {
            const formData = new FormData();
            formData.append('message', message);

            const response = await fetch(this.sendUri, {
                method: 'POST',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok || data.error) {
                this.showError(data.error || `Request failed (${response.status})`);
            }

            if (Array.isArray(data.messages)) {
                this.renderAll(data.messages);
            }
        } catch (err) {
            this.showError(err.message || String(err));
        }
    }

    async sendStreaming(message) {
        const toolElements = {};

        try {
            const formData = new FormData();
            formData.append('message', message);

            const response = await fetch(this.streamUri, {
                method: 'POST',
                body: formData,
            });

            if (!response.ok) {
                this.showError(`Request failed (${response.status})`);
                return;
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });

                const result = this.parseSseBuffer(buffer);
                buffer = result.remainder;

                for (const evt of result.parsed) {
                    this.handleSseEvent(evt.event, evt.data, toolElements);
                }
            }
        } catch (err) {
            this.removeThinkingIndicator();
            this.showError(err.message || String(err));
        }
    }

    parseSseBuffer(buffer) {
        const parsed = [];
        const blocks = buffer.split('\n\n');
        const remainder = blocks.pop();

        for (const block of blocks) {
            if (!block.trim()) continue;
            let event = 'message';
            let data = '';
            for (const line of block.split('\n')) {
                if (line.startsWith('event: ')) {
                    event = line.slice(7);
                } else if (line.startsWith('data: ')) {
                    data = line.slice(6);
                }
            }
            if (data) {
                try {
                    parsed.push({ event, data: JSON.parse(data) });
                } catch (e) {
                    // skip malformed
                }
            }
        }
        return { parsed, remainder };
    }

    handleSseEvent(event, data, toolElements) {
        switch (event) {
            case 'llm_start':
                this.showThinkingIndicator();
                break;

            case 'assistant_message':
                this.removeThinkingIndicator();
                this.appendMessage(data.message);
                break;

            case 'tool_start': {
                const el = this.appendToolProgress(data.tool_name, data.tool_call_id);
                toolElements[data.tool_call_id] = el;
                break;
            }

            case 'tool_result': {
                const el = toolElements[data.tool_call_id];
                if (el) {
                    this.updateToolResult(el, data.tool_name, data.content);
                }
                break;
            }

            case 'done':
                this.removeThinkingIndicator();
                break;

            case 'error':
                this.removeThinkingIndicator();
                this.showError(data.error || 'Unknown error');
                break;
        }
    }

    showThinkingIndicator() {
        this.removeThinkingIndicator();
        const el = document.createElement('div');
        el.className = 'chat-msg chat-msg-assistant chat-thinking';
        el.innerHTML = '<div class="chat-msg-role">assistant</div><div class="chat-thinking-dots">Thinking\u2026</div>';
        this.messagesEl.appendChild(el);
        this.scrollToBottom();
    }

    removeThinkingIndicator() {
        const el = this.messagesEl.querySelector('.chat-thinking');
        if (el) el.remove();
    }

    appendToolProgress(toolName, toolCallId) {
        const wrapper = document.createElement('div');
        wrapper.className = 'chat-msg chat-msg-tool';
        wrapper.dataset.toolCallId = toolCallId;

        const roleEl = document.createElement('div');
        roleEl.className = 'chat-msg-role';
        roleEl.textContent = 'tool';
        wrapper.appendChild(roleEl);

        const status = document.createElement('div');
        status.className = 'chat-tool-status';
        status.textContent = '\u2699\uFE0F Executing: ' + toolName + '\u2026';
        wrapper.appendChild(status);

        this.messagesEl.appendChild(wrapper);
        this.scrollToBottom();
        return wrapper;
    }

    updateToolResult(el, toolName, content) {
        const status = el.querySelector('.chat-tool-status');
        if (status) status.remove();

        const det = document.createElement('details');
        det.className = 'chat-toolcall';
        const sum = document.createElement('summary');
        sum.textContent = '\u2705 ' + toolName;
        det.appendChild(sum);
        const pre = document.createElement('pre');
        pre.textContent = content;
        det.appendChild(pre);
        el.appendChild(det);
        this.scrollToBottom();
    }

    setLoading(loading) {
        this.sendBtn.disabled = loading;
        this.input.disabled = loading;
        this.sendBtn.textContent = loading ? 'Thinking\u2026' : 'Send';
    }

    showError(msg) {
        this.errorEl.textContent = msg;
        this.errorEl.style.display = '';
    }

    renderAll(messages) {
        this.messagesEl.innerHTML = '';
        for (const msg of messages) {
            this.appendMessage(msg);
        }
    }

    appendMessage(msg) {
        const role = msg.role || 'unknown';
        const wrapper = document.createElement('div');
        wrapper.className = 'chat-msg chat-msg-' + role;

        const roleEl = document.createElement('div');
        roleEl.className = 'chat-msg-role';
        roleEl.textContent = role === 'user' ? 'you' : role;
        wrapper.appendChild(roleEl);

        if (msg.content) {
            const pre = document.createElement('pre');
            pre.textContent = msg.content;
            wrapper.appendChild(pre);
        }

        if (Array.isArray(msg.tool_calls)) {
            for (const tc of msg.tool_calls) {
                const det = document.createElement('details');
                det.className = 'chat-toolcall';
                const sum = document.createElement('summary');
                sum.textContent = '\uD83D\uDD27 ' + (tc.function?.name ?? 'unknown');
                det.appendChild(sum);
                const args = document.createElement('div');
                args.innerHTML = 'args: <code></code>';
                args.querySelector('code').textContent = tc.function?.arguments ?? '';
                det.appendChild(args);
                wrapper.appendChild(det);
            }
        }

        this.messagesEl.appendChild(wrapper);
        this.scrollToBottom();
    }

    scrollToBottom() {
        if (this.messagesEl) {
            this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
        }
    }
}

new ChatModule();

export default ChatModule;
