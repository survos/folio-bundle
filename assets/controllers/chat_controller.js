import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['button', 'content', 'source'];

    async copy() {
        const text = this.copyText().trim();

        if (!text) {
            return;
        }

        await this.writeText(text);
        this.showCopied();
    }

    copyText() {
        const source = this.hasSourceTarget ? this.sourceText() : '';

        if (source.trim()) {
            return source;
        }

        const content = this.hasContentTarget
            ? this.contentTarget
            : this.element.querySelector('.prose, .markdown');

        return content ? this.markdownLikeText(content) : '';
    }

    sourceText() {
        if (!this.hasSourceTarget) {
            return '';
        }

        if (this.sourceTarget instanceof HTMLTemplateElement) {
            return this.sourceTarget.content.textContent || '';
        }

        return this.sourceTarget.textContent || '';
    }

    markdownLikeText(node) {
        return this.nodeText(node)
            .replace(/[ \t]+\n/g, '\n')
            .replace(/\n{3,}/g, '\n\n')
            .trim();
    }

    nodeText(node) {
        if (node.nodeType === Node.TEXT_NODE) {
            return node.nodeValue || '';
        }

        if (node.nodeType !== Node.ELEMENT_NODE) {
            return '';
        }

        const element = node;
        const tag = element.tagName.toLowerCase();

        if (tag === 'br') {
            return '\n';
        }

        if (tag === 'img') {
            const alt = element.getAttribute('alt') || 'image';
            const src = element.getAttribute('src') || '';

            return src ? `![${alt}](${src})` : alt;
        }

        if (tag === 'a') {
            const label = this.childrenText(element).trim();
            const href = element.getAttribute('href') || '';

            return href && href !== label ? `[${label || href}](${href})` : label;
        }

        if (tag === 'pre') {
            return `\n\`\`\`\n${element.textContent.trim()}\n\`\`\`\n\n`;
        }

        if (tag === 'code') {
            return `\`${element.textContent.trim()}\``;
        }

        if (/^h[1-6]$/.test(tag)) {
            const level = Number(tag.slice(1));

            return `\n${'#'.repeat(level)} ${this.childrenText(element).trim()}\n\n`;
        }

        if (tag === 'li') {
            return `- ${this.childrenText(element).trim()}\n`;
        }

        if (tag === 'blockquote') {
            const text = this.childrenText(element).trim();

            return text
                ? `\n${text.split(/\n/).map((line) => `> ${line}`).join('\n')}\n\n`
                : '';
        }

        const text = this.childrenText(element);

        if (['div', 'p', 'section', 'article', 'ul', 'ol'].includes(tag)) {
            return `${text.trim()}\n\n`;
        }

        return text;
    }

    childrenText(element) {
        return Array.from(element.childNodes)
            .map((child) => this.nodeText(child))
            .join('');
    }

    async writeText(text) {
        if (navigator.clipboard?.writeText) {
            try {
                await navigator.clipboard.writeText(text);
                return;
            } catch {
                // Fall through to the legacy path when browser permissions block clipboard access.
            }
        }

        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.setAttribute('readonly', '');
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
    }

    showCopied() {
        if (!this.hasButtonTarget) {
            return;
        }

        const button = this.buttonTarget;
        const original = button.dataset.originalContent || button.innerHTML;

        button.dataset.originalContent = original;
        button.innerHTML = button.dataset.copiedContent || 'Copied';
        button.disabled = true;

        window.setTimeout(() => {
            button.innerHTML = original;
            button.disabled = false;
        }, 1200);
    }
}
