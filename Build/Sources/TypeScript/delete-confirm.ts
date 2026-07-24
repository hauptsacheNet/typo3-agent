import Modal from '@typo3/backend/modal.js';
import Severity from '@typo3/backend/severity.js';

document.addEventListener('click', (e: Event): void => {
  const target = e.target as HTMLElement | null;
  const trigger = target?.closest<HTMLAnchorElement>('a[data-hn-delete-confirm]');
  if (!trigger) return;
  e.preventDefault();
  const href = trigger.href;
  const modal = Modal.advanced({
    type: Modal.types.default,
    title: trigger.dataset.hnDeleteTitle ?? 'Delete',
    content: trigger.dataset.hnDeleteBody ?? 'Delete this record?',
    severity: Severity.warning,
    buttons: [
      {
        text: trigger.dataset.hnDeleteCancel ?? 'Cancel',
        btnClass: 'btn-default',
        name: 'cancel',
        trigger: (): void => modal.hideModal(),
      },
      {
        text: trigger.dataset.hnDeleteOk ?? 'Delete',
        btnClass: 'btn-warning',
        active: true,
        name: 'delete',
        trigger: (): void => {
          modal.hideModal();
          window.location.href = href;
        },
      },
    ],
  });
});
