let fileBrowserEditorLoader = null;

/**
 * CodeMirror + language packs — site Files and configuration editor only.
 * Loaded via @vite on those pages, not from the global app bundle.
 */
export function dplyEnsureFileBrowserEditor() {
    if (typeof window.dplyFileBrowserMountEditor === 'function') {
        return Promise.resolve(window.dplyFileBrowserMountEditor);
    }

    if (!fileBrowserEditorLoader) {
        fileBrowserEditorLoader = import('./file-browser-editor.js').then(
            () => window.dplyFileBrowserMountEditor,
        );
    }

    return fileBrowserEditorLoader;
}

window.dplyEnsureFileBrowserEditor = dplyEnsureFileBrowserEditor;
