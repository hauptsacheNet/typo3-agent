import { html } from "lit";
import Modal from "@typo3/backend/modal.js";
function openImageLightbox(src, alt = "") {
  if (!src) return;
  Modal.advanced({
    type: Modal.types.default,
    title: alt || "Bildvorschau",
    content: html`<img class="hn-agent-lightbox-img" src=${src} alt=${alt}
                          style="display:block;margin:0 auto;max-width:100%;max-height:calc(100vh - 14rem);object-fit:contain"/>`,
    size: Modal.sizes.large
  });
}
export {
  openImageLightbox
};
//# sourceMappingURL=image-lightbox.js.map
