import {html} from 'lit';
import {unsafeHTML} from 'lit/directives/unsafe-html.js';
import Modal from '@typo3/backend/modal.js';

document.addEventListener('click', (e: Event): void => {
  const target = e.target as HTMLElement | null;
  const btn = target?.closest<HTMLButtonElement>('.hn-instruction-show');
  if (!btn) return;
  e.preventDefault();
  const tplId = btn.dataset.contentTarget;
  if (!tplId) return;
  const tpl = document.getElementById(tplId) as HTMLTemplateElement | null;
  if (!tpl) return;
  Modal.advanced({
    type: Modal.types.default,
    title: btn.dataset.modalTitle ?? '',
    content: html`<div class="hn-instruction-content">${unsafeHTML(tpl.innerHTML)}</div>`,
    size: Modal.sizes.medium,
    buttons: [{
      text: btn.dataset.closeLabel ?? 'Close',
      btnClass: 'btn-default',
      active: true,
      trigger: (_, m) => m.hideModal(),
    }],
  });
});
