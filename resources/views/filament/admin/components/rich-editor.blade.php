{{-- TinyMCE rich editor field. Livewire-safe via wire:ignore + $wire.get/set.
     HTML is sanitized server-side by HtmlSanitizer on save. Includes an
     image-only "Insert Media" modal (v0.8.1) sourced from cms_media. --}}
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        wire:ignore
        x-data="cmsRichEditor('{{ $getStatePath() }}', {{ $getEditorHeight() }}, @js($getMediaItems()))"
    >
        <textarea x-ref="input" x-on:input="syncFallback()" style="width:100%;min-height:{{ $getEditorHeight() }}px;">{{ $getState() }}</textarea>

        {{-- Writable-fallback notice (CORE-EDITOR-1B): shown only when TinyMCE
             failed to load and the plain textarea is the live editor. --}}
        <p x-show="fallbackMode" x-cloak class="cms-re-fallback-note" role="status">
            {{ tn_trans('The rich text editor failed to load. You are editing the raw HTML directly; your changes will still be saved.') }}
        </p>

        {{-- Insert Media modal (teleported to body so it sits above the editor). --}}
        <template x-teleport="body">
            <div
                class="cms-mm-overlay"
                x-show="modalOpen"
                x-cloak
                x-transition.opacity
                @keydown.escape.window="closeModal()"
            >
                <div class="cms-mm-backdrop" @click="closeModal()"></div>

                <div
                    class="cms-mm-panel"
                    role="dialog"
                    aria-modal="true"
                    aria-label="{{ tn_trans('Select media') }}"
                    :style="dark
                        ? '--cms-bg:#1f2937;--cms-fg:#f3f4f6;--cms-border:#374151;--cms-muted:#9ca3af;--cms-card:#111827;--cms-input:#111827;'
                        : '--cms-bg:#ffffff;--cms-fg:#111827;--cms-border:#e5e7eb;--cms-muted:#6b7280;--cms-card:#f9fafb;--cms-input:#ffffff;'"
                >
                    {{-- Header --}}
                    <div class="cms-mm-header">
                        <span class="cms-mm-title">{{ tn_trans('Select media') }}</span>
                        <button type="button" class="cms-mm-close" @click="closeModal()" aria-label="{{ tn_trans('Close') }}">&times;</button>
                    </div>

                    {{-- Search --}}
                    <div class="cms-mm-search">
                        <input
                            type="text"
                            x-model="search"
                            placeholder="{{ tn_trans('Search by filename, alt, title, caption or description...') }}"
                        >
                        <span class="cms-mm-count" x-show="media.length > 0"
                              x-text="@js(tn_trans('Showing :count image(s)', ['count' => '__N__'])).replace('__N__', filteredMedia().length)"></span>
                    </div>

                    {{-- Body: grid (left) + details (right) --}}
                    <div class="cms-mm-body">
                        <div class="cms-mm-gridwrap">
                            {{-- Truly empty (no image media at all) --}}
                            <div x-show="media.length === 0" class="cms-mm-empty">
                                <p style="margin:0 0 .5rem;font-weight:500;color:var(--cms-fg);">{{ tn_trans('No images found.') }}</p>
                                <p style="margin:0 0 1rem;">{{ tn_trans('Upload an image first, then return here.') }}</p>
                                <a href="/admin/media/upload" target="_blank" rel="noopener" class="cms-mm-btn cms-mm-btn-primary" style="text-decoration:none;display:inline-block;">{{ tn_trans('Upload media') }}</a>
                            </div>

                            {{-- Search returned nothing --}}
                            <div x-show="media.length > 0 && filteredMedia().length === 0" class="cms-mm-empty">
                                {{ tn_trans('No images match your search.') }}
                            </div>

                            {{-- Grid --}}
                            <div x-show="filteredMedia().length > 0" class="cms-mm-grid">
                                <template x-for="item in filteredMedia()" :key="item.id">
                                    <button
                                        type="button"
                                        class="cms-mm-card"
                                        :class="selectedId === item.id ? 'is-selected' : ''"
                                        @click="selectItem(item)"
                                        @dblclick="selectItem(item); insertSelected()"
                                        :title="item.name"
                                    >
                                        <span class="cms-mm-badge" x-show="selectedId === item.id">&check;</span>
                                        <img class="cms-mm-thumb" :src="item.url" :alt="item.alt || item.name" loading="lazy">
                                        <div class="cms-mm-name" x-text="item.name"></div>
                                        <div class="cms-mm-meta" x-show="item.width && item.height" x-text="item.width + ' × ' + item.height"></div>
                                        <div class="cms-mm-meta" x-show="item.alt" x-text="item.alt"></div>
                                    </button>
                                </template>
                            </div>
                        </div>

                        {{-- Details panel --}}
                        <div class="cms-mm-details">
                            <template x-if="selectedItem()">
                                <div style="display:flex;flex-direction:column;gap:.5rem;">
                                    <img class="cms-mm-preview" :src="selectedItem().url" :alt="insertAlt || selectedItem().name">

                                    <div>
                                        <button type="button" class="cms-mm-copy" @click="copyUrl()"
                                                x-text='copied ? @js(tn_trans("Copied!")) : @js(tn_trans("Copy URL"))'></button>
                                    </div>

                                    <div class="cms-mm-dt">{{ tn_trans('Filename') }}</div>
                                    <div class="cms-mm-dd" x-text="selectedItem().name"></div>

                                    <div class="cms-mm-dt" x-show="selectedItem().width && selectedItem().height">{{ tn_trans('Dimensions') }}</div>
                                    <div class="cms-mm-dd" x-show="selectedItem().width && selectedItem().height"
                                         x-text="selectedItem().width + ' × ' + selectedItem().height"></div>

                                    <div class="cms-mm-dt" x-show="selectedItem().description">{{ tn_trans('Description') }}</div>
                                    <div class="cms-mm-dd" x-show="selectedItem().description" x-text="selectedItem().description"></div>

                                    {{-- Insert options (alt / title / caption) --}}
                                    <label class="cms-mm-dt" for="cms-mm-alt">{{ tn_trans('Alt text') }}</label>
                                    <input id="cms-mm-alt" class="cms-mm-field" type="text" x-model="insertAlt"
                                           placeholder="{{ tn_trans('Describe the image') }}">

                                    <label class="cms-mm-dt" for="cms-mm-title">{{ tn_trans('Title') }}</label>
                                    <input id="cms-mm-title" class="cms-mm-field" type="text" x-model="insertTitle"
                                           placeholder="{{ tn_trans('Optional title') }}">

                                    <label class="cms-mm-check">
                                        <input type="checkbox" x-model="addCaption">
                                        <span>{{ tn_trans('Add caption') }}</span>
                                    </label>

                                    <template x-if="addCaption">
                                        <div>
                                            <label class="cms-mm-dt" for="cms-mm-caption">{{ tn_trans('Caption') }}</label>
                                            <input id="cms-mm-caption" class="cms-mm-field" type="text" x-model="insertCaption"
                                                   placeholder="{{ tn_trans('Caption shown under the image') }}">
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <div x-show="!selectedItem()" class="cms-mm-empty" style="padding:1.5rem .5rem;">
                                {{ tn_trans('Select an image to insert it into the editor.') }}
                            </div>
                        </div>
                    </div>

                    {{-- Footer --}}
                    <div class="cms-mm-footer">
                        <button type="button" class="cms-mm-btn cms-mm-btn-ghost" @click="closeModal()">{{ tn_trans('Cancel') }}</button>
                        <button type="button" class="cms-mm-btn cms-mm-btn-primary"
                                @click="insertSelected()" :disabled="selectedId === null">{{ tn_trans('Add to editor') }}</button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</x-dynamic-component>

@assets
<style>
    [x-cloak]{display:none!important;}
    .cms-re-fallback-note{margin:.5rem 0 0;font-size:.8rem;color:#b45309;}

    /* ---- Insert Media modal (cms-mm-*) ---- */
    .cms-mm-overlay{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:1rem;}
    .cms-mm-backdrop{position:absolute;inset:0;background:rgba(0,0,0,.55);backdrop-filter:blur(1px);}
    .cms-mm-panel{position:relative;display:flex;flex-direction:column;width:min(1100px,94vw);height:80vh;max-height:80vh;
        background:var(--cms-bg);color:var(--cms-fg);border:1px solid var(--cms-border);border-radius:14px;
        box-shadow:0 24px 60px rgba(0,0,0,.45);overflow:hidden;}
    .cms-mm-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--cms-border);}
    .cms-mm-title{font-size:1.05rem;font-weight:600;}
    .cms-mm-close{background:none;border:none;font-size:1.5rem;line-height:1;cursor:pointer;color:var(--cms-muted);
        width:2rem;height:2rem;border-radius:8px;display:flex;align-items:center;justify-content:center;}
    .cms-mm-close:hover{background:rgba(127,127,127,.15);color:var(--cms-fg);}
    .cms-mm-search{display:flex;align-items:center;gap:.85rem;padding:.85rem 1.25rem;border-bottom:1px solid var(--cms-border);}
    .cms-mm-search input{flex:1 1 auto;padding:.55rem .8rem;border-radius:9px;border:1px solid var(--cms-border);
        background:var(--cms-input);color:var(--cms-fg);font-size:.9rem;outline:none;}
    .cms-mm-search input:focus{border-color:var(--cms-accent,#f59e0b);box-shadow:0 0 0 3px rgba(245,158,11,.25);}
    .cms-mm-count{font-size:.8rem;color:var(--cms-muted);white-space:nowrap;}
    .cms-mm-body{flex:1 1 auto;display:grid;grid-template-columns:minmax(0,1fr) 320px;min-height:0;}
    .cms-mm-gridwrap{overflow:auto;padding:1rem 1.25rem;min-height:0;}
    .cms-mm-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:.85rem;align-content:start;}
    .cms-mm-card{position:relative;text-align:left;padding:.45rem;border-radius:10px;background:var(--cms-card);
        border:2px solid transparent;cursor:pointer;transition:border-color .12s,box-shadow .12s;}
    .cms-mm-card:hover{border-color:var(--cms-muted);}
    .cms-mm-card.is-selected{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.4);background:rgba(245,158,11,.10);}
    .cms-mm-thumb{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:7px;display:block;background:rgba(127,127,127,.15);}
    .cms-mm-badge{position:absolute;top:.65rem;right:.65rem;width:1.45rem;height:1.45rem;border-radius:999px;background:#f59e0b;
        color:#1f2937;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:800;box-shadow:0 1px 4px rgba(0,0,0,.35);}
    .cms-mm-name{font-size:12px;margin-top:.45rem;font-weight:500;word-break:break-word;line-height:1.25;}
    .cms-mm-meta{font-size:11px;color:var(--cms-muted);word-break:break-word;}
    .cms-mm-details{border-left:1px solid var(--cms-border);padding:1.1rem 1.15rem;overflow:auto;}
    .cms-mm-preview{width:100%;max-height:220px;object-fit:contain;border-radius:9px;background:rgba(127,127,127,.12);}
    .cms-mm-copy{font-size:.75rem;padding:.35rem .7rem;border-radius:7px;border:1px solid var(--cms-border);background:transparent;color:var(--cms-fg);cursor:pointer;}
    .cms-mm-copy:hover{background:rgba(127,127,127,.12);}
    .cms-mm-dt{color:var(--cms-muted);text-transform:uppercase;letter-spacing:.04em;font-size:.66rem;font-weight:600;margin-top:.6rem;}
    .cms-mm-dd{font-size:.82rem;word-break:break-all;}
    .cms-mm-field{width:100%;padding:.45rem .6rem;margin-top:.15rem;border-radius:8px;border:1px solid var(--cms-border);
        background:var(--cms-input);color:var(--cms-fg);font-size:.85rem;outline:none;}
    .cms-mm-field:focus{border-color:var(--cms-accent,#f59e0b);box-shadow:0 0 0 3px rgba(245,158,11,.25);}
    .cms-mm-check{display:flex;align-items:center;gap:.5rem;margin-top:.75rem;font-size:.85rem;color:var(--cms-fg);cursor:pointer;}
    .cms-mm-check input{width:1rem;height:1rem;cursor:pointer;}
    .cms-mm-empty{padding:2.5rem 1rem;text-align:center;color:var(--cms-muted);font-size:.9rem;}
    .cms-mm-footer{display:flex;align-items:center;justify-content:flex-end;gap:.6rem;padding:.9rem 1.25rem;border-top:1px solid var(--cms-border);}
    .cms-mm-btn{padding:.55rem 1.15rem;border-radius:9px;font-size:.9rem;cursor:pointer;}
    .cms-mm-btn-ghost{border:1px solid var(--cms-border);background:transparent;color:var(--cms-fg);}
    .cms-mm-btn-ghost:hover{background:rgba(127,127,127,.12);}
    .cms-mm-btn-primary{border:none;background:#f59e0b;color:#1f2937;font-weight:600;}
    .cms-mm-btn-primary:hover:not(:disabled){background:#d97706;}
    .cms-mm-btn-primary:disabled{opacity:.45;cursor:not-allowed;}
    @media (max-width:780px){
        .cms-mm-panel{width:96vw;height:90vh;max-height:90vh;}
        .cms-mm-body{grid-template-columns:1fr;grid-template-rows:minmax(0,1fr) auto;}
        .cms-mm-details{border-left:none;border-top:1px solid var(--cms-border);max-height:40vh;}
    }
</style>
<script>
    // Self-hosted TinyMCE (no Tiny Cloud, no API key). Assets live under
    // public/vendor/tinymce and are served from /vendor/tinymce.
    window.cmsTinyBaseUrl = '/vendor/tinymce';

    window.cmsLoadTinyMce = window.cmsLoadTinyMce || function () {
        if (window.tinymce) {
            return Promise.resolve();
        }
        if (window.__cmsTinyPromise) {
            return window.__cmsTinyPromise;
        }
        window.__cmsTinyPromise = new Promise(function (resolve, reject) {
            var s = document.createElement('script');
            s.src = window.cmsTinyBaseUrl + '/tinymce.min.js';
            s.referrerPolicy = 'origin';
            s.onload = resolve;
            s.onerror = reject;
            document.head.appendChild(s);
        });
        return window.__cmsTinyPromise;
    };

    window.cmsEscapeAttr = window.cmsEscapeAttr || function (value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    };

    window.cmsRichEditor = function (statePath, height, mediaItems) {
        return {
            height: height || 500,
            editor: null,
            dark: false,
            fallbackMode: false,

            // Media modal state (per editor instance).
            media: Array.isArray(mediaItems) ? mediaItems : [],
            modalOpen: false,
            search: '',
            selectedId: null,
            copied: false,

            // Per-insert options (populated when an image is selected).
            insertAlt: '',
            insertTitle: '',
            insertCaption: '',
            addCaption: false,

            init: function () {
                var self = this;
                this.dark = document.documentElement.classList.contains('dark');
                window.cmsLoadTinyMce().then(function () {
                    self.boot();
                }).catch(function () {
                    // Assets unavailable: the plain textarea becomes the real
                    // editor (CORE-EDITOR-1B). It has no wire:model, so without
                    // syncFallback() a save would silently persist the stale
                    // server-side state while notifying success.
                    self.fallbackMode = true;
                });
                document.addEventListener('livewire:navigating', function () {
                    if (self.editor) {
                        self.editor.remove();
                        self.editor = null;
                    }
                }, { once: true });
            },

            // Sync textarea edits to Livewire whenever TinyMCE is not managing
            // the field — the load-failure fallback, and the window before a
            // slow TinyMCE boot takes over (its init setContent reads the
            // synced state, so nothing typed early is lost either way).
            syncFallback: function () {
                if (this.editor === null) {
                    this.$wire.set(statePath, this.$refs.input.value, false);
                }
            },

            filteredMedia: function () {
                var q = this.search.trim().toLowerCase();
                if (q === '') {
                    return this.media;
                }
                return this.media.filter(function (m) {
                    return (m.name && m.name.toLowerCase().indexOf(q) !== -1)
                        || (m.filename && m.filename.toLowerCase().indexOf(q) !== -1)
                        || (m.alt && m.alt.toLowerCase().indexOf(q) !== -1)
                        || (m.title && m.title.toLowerCase().indexOf(q) !== -1)
                        || (m.caption && m.caption.toLowerCase().indexOf(q) !== -1)
                        || (m.description && m.description.toLowerCase().indexOf(q) !== -1);
                });
            },

            selectedItem: function () {
                for (var i = 0; i < this.media.length; i++) {
                    if (this.media[i].id === this.selectedId) {
                        return this.media[i];
                    }
                }
                return null;
            },

            copyUrl: function () {
                var item = this.selectedItem();
                if (!item) {
                    return;
                }
                var self = this;
                var done = function () {
                    self.copied = true;
                    setTimeout(function () { self.copied = false; }, 1500);
                };
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(item.url).then(done).catch(function () {});
                } else {
                    done();
                }
            },

            // Select an image and seed the insert-option fields from its
            // metadata (alt/title fall back to the resolved SEO defaults; the
            // caption field is pre-filled from the media caption when present).
            selectItem: function (item) {
                this.selectedId = item.id;
                this.insertAlt = item.seo_alt || item.alt || item.name || '';
                this.insertTitle = item.seo_title || item.title || '';
                this.insertCaption = item.caption || '';
                this.addCaption = false;
            },

            openModal: function () {
                this.search = '';
                this.selectedId = null;
                this.copied = false;
                this.insertAlt = '';
                this.insertTitle = '';
                this.insertCaption = '';
                this.addCaption = false;
                this.modalOpen = true;
            },

            closeModal: function () {
                this.modalOpen = false;
            },

            insertSelected: function () {
                var item = this.selectedItem();
                if (!item || !this.editor) {
                    this.closeModal();
                    return;
                }

                var esc = window.cmsEscapeAttr;
                var alt = this.insertAlt || '';
                var title = this.insertTitle || '';

                var img = '<img src="' + esc(item.url) + '"'
                    + ' alt="' + esc(alt) + '"';
                if (title !== '') { img += ' title="' + esc(title) + '"'; }
                if (item.width) { img += ' width="' + parseInt(item.width, 10) + '"'; }
                if (item.height) { img += ' height="' + parseInt(item.height, 10) + '"'; }
                img += ' loading="lazy">';

                var html = img;
                if (this.addCaption) {
                    html = '<figure class="cms-image">' + img
                        + '<figcaption>' + esc(this.insertCaption || '') + '</figcaption>'
                        + '</figure>';
                }

                this.editor.insertContent(html);
                // Sync to Livewire (deferred) so the inserted image is saved.
                this.$wire.set(statePath, this.editor.getContent(), false);
                this.closeModal();
                this.selectedId = null;
                this.editor.focus();
            },

            boot: function () {
                var self = this;

                // Muted/border/soft colours follow the editor skin so the
                // in-editor preview stays readable in both light and dark.
                var muted = this.dark ? '#9ca3af' : '#6b7280';
                var border = this.dark ? '#374151' : '#e5e7eb';
                var soft = this.dark ? '#111827' : '#f3f4f6';

                window.tinymce.init({
                    target: this.$refs.input,
                    // Self-host: resolve skins/plugins/themes/models/icons from
                    // /vendor/tinymce using the .min build (no Tiny Cloud).
                    base_url: window.cmsTinyBaseUrl,
                    suffix: '.min',
                    license_key: 'gpl',
                    menubar: false,
                    height: this.height,
                    branding: false,
                    promotion: false,
                    convert_urls: false,
                    plugins: 'lists link table code image autolink',
                    toolbar: 'undo redo | blocks | bold italic underline strikethrough | link | bullist numlist | table | insertmedia image insertmediaurl medialibrary | code',
                    skin: this.dark ? 'oxide-dark' : 'oxide',
                    content_css: this.dark ? 'dark' : 'default',

                    // Paste cleanup — keep Word/Google Docs paste tidy. TinyMCE 6+
                    // merged the paste plugin into core; the legacy webkit-style
                    // options were removed, so we strip inline styles in
                    // paste_postprocess instead (HtmlSanitizer drops style on save
                    // anyway, so this just keeps the editor view consistent).
                    paste_as_text: false,
                    paste_data_images: false,
                    paste_merge_formats: true,
                    smart_paste: true,
                    paste_postprocess: function (editor, args) {
                        args.node.querySelectorAll('[style]').forEach(function (el) {
                            el.removeAttribute('style');
                        });
                    },

                    // Link behaviour — sensible defaults; the server-side
                    // sanitizer still forces rel="noopener noreferrer" on
                    // external target="_blank" links.
                    link_default_target: '_self',
                    link_assume_external_targets: 'https',
                    rel_list: [
                        { title: 'None', value: '' },
                        { title: 'nofollow', value: 'nofollow' },
                        { title: 'sponsored', value: 'sponsored' },
                        { title: 'noopener noreferrer', value: 'noopener noreferrer' },
                    ],

                    // In-editor content style — approximate the frontend so what
                    // authors see is close to what renders.
                    content_style:
                        'body{font-size:16px;line-height:1.65;}'
                        + 'h2,h3{line-height:1.25;margin:1.6rem 0 .6rem;}'
                        + 'img{max-width:100%;height:auto;}'
                        + 'figure.cms-image{margin:1.5rem 0;}'
                        + 'figure.cms-image img{display:block;max-width:100%;height:auto;border-radius:.5rem;}'
                        + 'figure.cms-image figcaption{margin-top:.5rem;font-size:.9rem;color:' + muted + ';text-align:center;}'
                        + 'table{border-collapse:collapse;width:100%;}'
                        + 'table td,table th{border:1px solid ' + border + ';padding:.5rem;}'
                        + 'blockquote{margin:1rem 0;padding:.25rem 1rem;border-left:4px solid ' + border + ';color:' + muted + ';}'
                        + 'pre{background:' + soft + ';padding:1rem;border-radius:.5rem;overflow:auto;}'
                        + 'code{background:' + soft + ';padding:.15rem .35rem;border-radius:.25rem;}',
                    setup: function (editor) {
                        self.editor = editor;

                        editor.on('init', function () {
                            editor.setContent(self.$wire.get(statePath) || '');
                        });

                        editor.on('change keyup undo redo SetContent', function () {
                            // Deferred (false): syncs to Livewire without a server
                            // round-trip; the value is sent on the next action (save).
                            self.$wire.set(statePath, editor.getContent(), false);
                        });

                        editor.ui.registry.addButton('insertmedia', {
                            text: @js(tn_trans('Insert Media')),
                            tooltip: @js(tn_trans('Insert an image from the Media Library')),
                            onAction: function () {
                                self.openModal();
                            },
                        });

                        editor.ui.registry.addButton('insertmediaurl', {
                            icon: 'image',
                            tooltip: @js(tn_trans('Insert media by URL')),
                            onAction: function () {
                                var url = window.prompt(@js(tn_trans('Image URL (paste from Media Library):')), '');
                                if (url) {
                                    editor.insertContent('<img src="' + window.cmsEscapeAttr(url) + '" alt="" loading="lazy">');
                                }
                            },
                        });

                        editor.ui.registry.addButton('medialibrary', {
                            text: @js(tn_trans('Media')),
                            tooltip: @js(tn_trans('Open Media Library in a new tab')),
                            onAction: function () {
                                window.open('/admin/media', '_blank');
                            },
                        });
                    },
                });
            },

            destroy: function () {
                if (this.editor) {
                    this.editor.remove();
                    this.editor = null;
                }
            },
        };
    };
</script>
@endassets
