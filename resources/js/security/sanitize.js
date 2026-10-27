// Only formatting used by authored assessment content is allowed. Parse URLs
// through the browser so entity-encoded/control-character schemes are checked
// in the same form that navigation would use.
const tags = new Set(['P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI', 'H2', 'H3', 'BLOCKQUOTE', 'TABLE', 'THEAD', 'TBODY', 'TR', 'TH', 'TD', 'DIV', 'SPAN', 'PRE', 'CODE', 'A', 'IMG']);
const attributes = {A: ['href', 'title'], IMG: ['src', 'alt', 'title'], TH: ['colspan', 'rowspan'], TD: ['colspan', 'rowspan']};

function safeUrl(value, image) {
    try {
        const url = new URL(value, location.origin);
        if (image) {
            return (['http:', 'https:'].includes(url.protocol) && url.origin === location.origin)
                || /^data:image\/(?:png|jpeg|webp|gif);base64,[a-z0-9+/=\s]+$/i.test(value);
        }
        return ['http:', 'https:', 'mailto:'].includes(url.protocol);
    } catch {
        return false;
    }
}

export function sanitize(html) {
    // Template content stays inert, including resource loading, until callers
    // insert the cleaned output into the visible document.
    const template = document.createElement('template');
    template.innerHTML = String(html || '');
    for (const element of template.content.querySelectorAll('*')) {
        if (element.namespaceURI !== 'http://www.w3.org/1999/xhtml' || !tags.has(element.tagName)) {
            element.remove();
            continue;
        }
        for (const attribute of [...element.attributes]) {
            const allowed = (attributes[element.tagName] || []).includes(attribute.name);
            if (!allowed || (['href', 'src'].includes(attribute.name) && !safeUrl(attribute.value, attribute.name === 'src'))
                || (['colspan', 'rowspan'].includes(attribute.name) && !/^[1-9][0-9]?$/.test(attribute.value))) {
                element.removeAttribute(attribute.name);
            }
        }
        if (element.tagName === 'A' && element.hasAttribute('href')) element.setAttribute('rel', 'noopener noreferrer');
    }
    return template.innerHTML;
}
