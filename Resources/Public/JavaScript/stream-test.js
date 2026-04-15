class StreamTest {
    constructor() {
        this.btn = document.getElementById('stream-test-btn');
        if (!this.btn) return;

        this.output = document.getElementById('stream-output');
        this.url = this.btn.dataset.url;

        this.btn.addEventListener('click', () => this.run());
    }

    async run() {
        this.output.style.display = 'block';
        this.output.textContent = '';
        this.btn.disabled = true;

        try {
            const response = await fetch(this.url, {
                headers: { 'Accept': 'text/plain' }
            });
            const reader = response.body.getReader();
            const decoder = new TextDecoder();

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;
                this.output.textContent += decoder.decode(value, { stream: true });
            }
        } catch (err) {
            this.output.textContent += '\n[Error: ' + err.message + ']';
        } finally {
            this.btn.disabled = false;
        }
    }
}

new StreamTest();

export default StreamTest;
