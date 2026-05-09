/**
 * Global TYPO3 namespace.
 *
 * Based on .typo3-core-types/Build/types/TYPO3/index.d.ts but without
 * typeof import() references that pull in all backend modules.
 * Module-level types come from the real TYPO3 sources via paths.
 *
 * Update this file when upgrading the TYPO3 version.
 */
declare namespace TYPO3 {
  export const lang: Record<string, string>;
  export const configuration: {
    showRefreshLoginPopup: boolean;
    username: string;
  };
  export namespace settings {
    export const ajaxUrls: Record<string, string>;
    export const cssUrls: Record<string, string>;
    export namespace cache {
      export const iconCacheIdentifier: string | undefined;
    }
  }
}

interface Window {
  TYPO3: Partial<typeof TYPO3>;
}