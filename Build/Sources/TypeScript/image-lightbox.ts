import {html} from 'lit';
import Modal from '@typo3/backend/modal.js';

/**
 * Opens a chat image in a TYPO3 backend modal as a large preview ("lightbox").
 * Closes via the modal's own X button, Escape or backdrop click.
 *
 * Shared by the attachment chips, the image-choice tiles and the delegated
 * image click handler in ChatElement (tool-result + markdown images).
 *
 * The modal renders in the top frame, where agent-chat.css is not loaded —
 * the sizing therefore has to live inline on the <img>.
 */
export function openImageLightbox(src: string, alt = ''): void {
    if (!src) return;
    Modal.advanced({
        type: Modal.types.default,
        title: alt || 'Bildvorschau',
        content: html`<img class="hn-agent-lightbox-img" src=${src} alt=${alt}
                          style="display:block;margin:0 auto;max-width:100%;max-height:calc(100vh - 14rem);object-fit:contain"/>`,
        size: Modal.sizes.large,
    });
}
