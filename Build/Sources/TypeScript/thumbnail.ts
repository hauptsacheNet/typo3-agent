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
