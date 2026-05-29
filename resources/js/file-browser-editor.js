import { EditorState } from '@codemirror/state';
import {
    EditorView,
    keymap,
    lineNumbers,
    highlightActiveLine,
    drawSelection,
} from '@codemirror/view';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { searchKeymap, search } from '@codemirror/search';
import {
    syntaxHighlighting,
    defaultHighlightStyle,
    bracketMatching,
    foldGutter,
    foldKeymap,
    indentOnInput,
} from '@codemirror/language';
import { autocompletion } from '@codemirror/autocomplete';
import { json } from '@codemirror/lang-json';
import { php } from '@codemirror/lang-php';
import { xml } from '@codemirror/lang-xml';
import { yaml } from '@codemirror/lang-yaml';
import { StreamLanguage } from '@codemirror/language';
import { shell } from '@codemirror/legacy-modes/mode/shell';
import { nginx } from '@codemirror/legacy-modes/mode/nginx';
import { properties } from '@codemirror/legacy-modes/mode/properties';

function languageFor(mime, path) {
    const lower = (path || '').toLowerCase();
    if (lower.endsWith('.env') || /\.env\.[\w-]+$/.test(lower)) {
        return StreamLanguage.define(properties);
    }
    if (mime === 'application/json' || lower.endsWith('.json')) return json();
    if (mime === 'application/xml' || lower.endsWith('.xml') || lower.endsWith('.svg'))
        return xml();
    if (mime === 'application/x-yaml' || lower.endsWith('.yml') || lower.endsWith('.yaml'))
        return yaml();
    if (
        mime === 'application/x-php' ||
        mime === 'application/x-httpd-php' ||
        lower.endsWith('.php')
    )
        return php();
    if (
        mime === 'application/x-sh' ||
        lower.endsWith('.sh') ||
        lower.endsWith('.bash')
    )
        return StreamLanguage.define(shell);
    if (lower.endsWith('.conf') || lower.includes('nginx'))
        return StreamLanguage.define(nginx);
    return [];
}

function completionSource(items) {
    if (!Array.isArray(items) || items.length === 0) {
        return [];
    }

    const options = items
        .filter((item) => item && typeof item.label === 'string' && typeof item.insert === 'string')
        .map((item) => ({
            label: item.label,
            type: item.type || 'snippet',
            detail: item.detail || undefined,
            apply: item.insert,
        }));

    if (options.length === 0) {
        return [];
    }

    return autocompletion({
        override: [
            (context) => {
                const word = context.matchBefore(/\w*/);
                if (!word || (word.from === word.to && !context.explicit)) {
                    return null;
                }

                return {
                    from: word.from,
                    options,
                };
            },
        ],
    });
}

window.dplyFileBrowserMountEditor = function (mount, opts = {}) {
    const initial = opts.content ?? '';
    const langExt = languageFor(opts.mime, opts.path);
    const readOnly = Boolean(opts.readOnly);
    const completionExt = completionSource(opts.completions);

    while (mount.firstChild) mount.removeChild(mount.firstChild);

    const updateListener = EditorView.updateListener.of((v) => {
        if (v.docChanged && typeof opts.onChange === 'function') {
            opts.onChange(v.state.doc.toString());
        }
    });

    const state = EditorState.create({
        doc: initial,
        extensions: [
            lineNumbers(),
            foldGutter(),
            drawSelection(),
            history(),
            indentOnInput(),
            bracketMatching(),
            highlightActiveLine(),
            syntaxHighlighting(defaultHighlightStyle, { fallback: true }),
            search({ top: true }),
            keymap.of([
                ...defaultKeymap,
                ...historyKeymap,
                ...searchKeymap,
                ...foldKeymap,
            ]),
            EditorView.lineWrapping,
            EditorState.readOnly.of(readOnly),
            EditorView.editable.of(!readOnly),
            updateListener,
            ...(Array.isArray(langExt) ? langExt : [langExt]),
            ...(Array.isArray(completionExt) ? completionExt : [completionExt]),
        ],
    });

    const view = new EditorView({ state, parent: mount });

    return {
        view,
        setContent(next) {
            const current = view.state.doc.toString();
            if (current === next) return;
            view.dispatch({
                changes: { from: 0, to: current.length, insert: next },
            });
        },
        destroy() {
            view.destroy();
        },
    };
};
