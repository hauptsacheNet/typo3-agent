function buildThumbnailUrl(ref, size = "large") {
  const base = window.top?.TYPO3?.settings?.Resource?.thumbnailUrl;
  if (!base) return "";
  if (ref === void 0 || ref === null || ref === "") return "";
  const url = new URL(base, window.location.origin);
  url.searchParams.set("identifier", String(ref));
  url.searchParams.set("size", size);
  url.searchParams.set("keepAspectRatio", "false");
  return url.toString();
}
function buildPreviewUrl(ref) {
  const base = window.TYPO3?.settings?.ajaxUrls?.typo3_agent_tasks_attachment_preview ?? window.top?.TYPO3?.settings?.ajaxUrls?.typo3_agent_tasks_attachment_preview;
  if (!base) return "";
  if (ref === void 0 || ref === null || ref === "") return "";
  const url = new URL(base, window.location.origin);
  url.searchParams.set("identifier", String(ref));
  return url.toString();
}
export {
  buildPreviewUrl,
  buildThumbnailUrl
};
//# sourceMappingURL=thumbnail.js.map
