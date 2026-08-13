/**
 * Build a URL to TYPO3 Core's backend thumbnail endpoint
 * (`TYPO3.settings.Resource.thumbnailUrl`) for a FAL file, referenced by its
 * sys_file UID or combined identifier. Returns '' when the endpoint or the
 * reference is unavailable. The endpoint streams a processed thumbnail through
 * the backend, so it also works for files in non-public storages (e.g. the
 * agent scratch storage).
 *
 * Shared by the attachment chips and the image-choice card.
 */
export function buildThumbnailUrl(ref: number | string | undefined | null, size = 'large'): string {
    const base = (window.top as unknown as {TYPO3?: {settings?: {Resource?: {thumbnailUrl?: string}}}})
        ?.TYPO3?.settings?.Resource?.thumbnailUrl;
    if (!base) return '';
    if (ref === undefined || ref === null || ref === '') return '';
    const url = new URL(base, window.location.origin);
    url.searchParams.set('identifier', String(ref));
    url.searchParams.set('size', size);
    url.searchParams.set('keepAspectRatio', 'false');
    return url.toString();
}

/**
 * Build a URL to the extension's attachment-preview endpoint
 * (typo3_agent_tasks_attachment_preview) for a large rendition of a FAL image,
 * used by the chat lightbox. Core's thumbnail endpoint caps at 96px, so the
 * extension ships its own endpoint for big previews. Returns '' when the
 * route or the reference is unavailable.
 */
export function buildPreviewUrl(ref: number | string | undefined | null): string {
    type AjaxSettings = {TYPO3?: {settings?: {ajaxUrls?: Record<string, string>}}};
    const base = (window as unknown as AjaxSettings).TYPO3?.settings?.ajaxUrls?.typo3_agent_tasks_attachment_preview
        ?? (window.top as unknown as AjaxSettings)?.TYPO3?.settings?.ajaxUrls?.typo3_agent_tasks_attachment_preview;
    if (!base) return '';
    if (ref === undefined || ref === null || ref === '') return '';
    const url = new URL(base, window.location.origin);
    url.searchParams.set('identifier', String(ref));
    return url.toString();
}
