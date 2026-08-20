{{-- Featured-image library tab: Alpine thumbnail grid + search + details panel.
     Selecting a thumbnail writes its URL into this field's Livewire state.

     The Alpine component is defined INLINE in x-data (and styles inline below),
     deliberately NOT via @assets/@once. This field only ever renders inside a
     lazily-mounted Filament action modal, where @assets/@filamentScripts no
     longer inject new <script>/<style> into the page — so a global init
     function would be undefined when the modal opens. Alpine evaluates an
     inline x-data object directly from the attribute, and <style> elements are
     applied even when morphed in by Livewire, so both work in the modal. --}}
<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        class="cms-fip"
        x-data="{
            statePath: @js($getStatePath()),
            searchUrl: @js($getSearchUrl()),
            // Newest-50 snapshot shown before searching, plus the live displayed
            // list (replaced by server results while searching).
            initialMedia: @js($getMediaItems()),
            media: @js($getMediaItems()),
            // The current selection, resolved server-side so its details render
            // even when it falls outside the snapshot or the active search.
            selectedItemData: @js($getSelectedItem()),
            search: '',
            loading: false,
            loadingMore: false,
            error: false,
            page: 1,
            limit: 50,
            hasMore: false,
            debounceTimer: null,
            countTemplate: @js(tn_trans(':count image(s)', ['count' => '__N__'])),
            selectedUrl: @js((string) ($getState() ?? '')),
            onSearch() {
                clearTimeout(this.debounceTimer);
                const q = this.search.trim();
                this.page = 1;
                this.hasMore = false;

                // No endpoint (e.g. outside the admin panel): degrade to a
                // client-side filter of the loaded snapshot.
                if (! this.searchUrl) {
                    this.media = this.clientFilter(q);
                    return;
                }

                if (q === '') {
                    this.media = this.initialMedia;
                    this.loading = false;
                    this.loadingMore = false;
                    this.error = false;
                    return;
                }

                this.loading = true;
                this.loadingMore = false;
                this.error = false;
                this.debounceTimer = setTimeout(() => this.fetchResults(q, 1, false), 250);
            },
            fetchResults(q, page = 1, append = false) {
                const url = this.searchUrl
                    + '?q=' + encodeURIComponent(q)
                    + '&page=' + encodeURIComponent(page)
                    + '&limit=' + encodeURIComponent(this.limit);

                fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                })
                    .then((r) => r.ok ? r.json() : Promise.reject(r))
                    .then((payload) => {
                        if (this.search.trim() !== q) { return; } // stale response

                        const items = Array.isArray(payload.items)
                            ? payload.items
                            : (Array.isArray(payload.data) ? payload.data : []);

                        this.media = append ? this.mergeMedia(this.media, items) : items;
                        this.page = Number(payload.page || page);
                        this.hasMore = Boolean(payload.has_more ?? (items.length >= this.limit));
                        this.loading = false;
                        this.loadingMore = false;
                    })
                    .catch(() => {
                        if (this.search.trim() !== q) { return; }
                        // Network/endpoint failure: still search the snapshot so
                        // the field never silently does nothing.
                        this.media = append ? this.media : this.clientFilter(q);
                        this.hasMore = false;
                        this.loading = false;
                        this.loadingMore = false;
                        this.error = true;
                    });
            },
            loadMore() {
                const q = this.search.trim();

                if (! this.searchUrl || q === '' || this.loading || this.loadingMore || ! this.hasMore) {
                    return;
                }

                this.loadingMore = true;
                this.fetchResults(q, this.page + 1, true);
            },
            mergeMedia(existing, incoming) {
                const seen = new Set(existing.map((item) => item.id || item.url));
                const fresh = incoming.filter((item) => {
                    const key = item.id || item.url;
                    if (seen.has(key)) { return false; }
                    seen.add(key);
                    return true;
                });

                return existing.concat(fresh);
            },
            countLabel() {
                return String(this.countTemplate).replace('__N__', this.media.length);
            },
            clientFilter(q) {
                const needle = q.toLowerCase();
                if (needle === '') { return this.initialMedia; }
                return this.initialMedia.filter((m) =>
                    ['name', 'filename', 'url', 'alt', 'title', 'caption', 'description']
                        .some((k) => m[k] && String(m[k]).toLowerCase().indexOf(needle) !== -1)
                );
            },
            selectedItem() {
                return this.media.find((m) => m.url === this.selectedUrl) || this.selectedItemData || null;
            },
            select(item) {
                this.selectedUrl = item.url;
                this.selectedItemData = item;
                // Deferred sync: the chosen URL is sent with the modal's
                // 'Set featured image' submit request.
                this.$wire.set(this.statePath, item.url, false);
            },
            humanSize(bytes) {
                if (! bytes) { return ''; }
                const u = ['B', 'KB', 'MB', 'GB'];
                let i = 0; let n = bytes;
                while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
                return (Math.round(n * 10) / 10) + ' ' + u[i];
            },
        }"
    >
        {{-- Search --}}
        <div class="cms-fip-search">
            <input
                type="text"
                x-model="search"
                @input="onSearch()"
                placeholder="{{ tn_trans('Search by filename, URL, alt, title, caption or description...') }}"
                aria-label="{{ tn_trans('Search media') }}"
            >
            <span class="cms-fip-count" x-show="loading">{{ tn_trans('Searching…') }}</span>
            <span
                class="cms-fip-count"
                x-show="!loading && media.length > 0"
                x-text="countLabel()"
            ></span>
        </div>

        <div class="cms-fip-body">
            {{-- Grid --}}
            <div class="cms-fip-gridwrap">
                <div x-show="!loading && media.length === 0 && search.trim() === ''" class="cms-fip-empty">
                    <p style="margin:0 0 .5rem;font-weight:500;">{{ tn_trans('No images found.') }}</p>
                    <p style="margin:0;">{!! tn_trans('Use the :tab tab to add one.', ['tab' => '<strong>' . e(tn_trans('Upload files')) . '</strong>']) !!}</p>
                </div>

                <div x-show="!loading && media.length === 0 && search.trim() !== ''" class="cms-fip-empty">
                    {{ tn_trans('No images match your search.') }}
                </div>

                <div x-show="loading" class="cms-fip-empty">
                    {{ tn_trans('Searching…') }}
                </div>

                <div x-show="!loading && media.length > 0" class="cms-fip-grid">
                    <template x-for="item in media" :key="item.id">
                        <button
                            type="button"
                            class="cms-fip-card"
                            :class="selectedUrl === item.url ? 'is-selected' : ''"
                            @click="select(item)"
                            :title="item.name"
                            :aria-pressed="selectedUrl === item.url"
                        >
                            <span class="cms-fip-badge" x-show="selectedUrl === item.url">&check;</span>
                            <img class="cms-fip-thumb" :src="item.url" :alt="item.alt || item.name" loading="lazy">
                            <div class="cms-fip-name" x-text="item.name"></div>
                            <div class="cms-fip-meta" x-show="item.width && item.height" x-text="item.width + ' × ' + item.height"></div>
                        </button>
                    </template>
                </div>

                <div x-show="!loading && media.length > 0 && hasMore" class="cms-fip-loadmore">
                    <button
                        type="button"
                        class="cms-fip-loadmore-button"
                        @click="loadMore()"
                        :disabled="loadingMore"
                    >
                        <span x-show="!loadingMore">{{ tn_trans('Load more') }}</span>
                        <span x-show="loadingMore">{{ tn_trans('Loading…') }}</span>
                    </button>
                </div>
            </div>

            {{-- Details panel --}}
            <div class="cms-fip-details">
                <template x-if="selectedItem()">
                    <div style="display:flex;flex-direction:column;gap:.4rem;">
                        <img class="cms-fip-preview" :src="selectedItem().url" :alt="selectedItem().alt || selectedItem().name">

                        <div class="cms-fip-dt">{{ tn_trans('Filename') }}</div>
                        <div class="cms-fip-dd" x-text="selectedItem().name"></div>

                        <div class="cms-fip-dt">{{ tn_trans('URL') }}</div>
                        <div class="cms-fip-dd" x-text="selectedItem().url"></div>

                        <div class="cms-fip-dt" x-show="selectedItem().width && selectedItem().height">{{ tn_trans('Dimensions') }}</div>
                        <div class="cms-fip-dd" x-show="selectedItem().width && selectedItem().height"
                             x-text="selectedItem().width + ' × ' + selectedItem().height + (humanSize(selectedItem().size) ? ' · ' + humanSize(selectedItem().size) : '')"></div>

                        <div class="cms-fip-dt" x-show="selectedItem().alt">{{ tn_trans('Alt text') }}</div>
                        <div class="cms-fip-dd" x-show="selectedItem().alt" x-text="selectedItem().alt"></div>

                        <div class="cms-fip-dt" x-show="selectedItem().title">{{ tn_trans('Title') }}</div>
                        <div class="cms-fip-dd" x-show="selectedItem().title" x-text="selectedItem().title"></div>

                        <div class="cms-fip-dt" x-show="selectedItem().caption">{{ tn_trans('Caption') }}</div>
                        <div class="cms-fip-dd" x-show="selectedItem().caption" x-text="selectedItem().caption"></div>

                        <div class="cms-fip-dt" x-show="selectedItem().description">{{ tn_trans('Description') }}</div>
                        <div class="cms-fip-dd" x-show="selectedItem().description" x-text="selectedItem().description"></div>
                    </div>
                </template>

                <div x-show="!selectedItem()" class="cms-fip-empty" style="padding:1.5rem .5rem;">
                    {!! tn_trans('Select an image to see its details, then choose :action.', ['action' => '<strong>' . e(tn_trans('Set featured image')) . '</strong>']) !!}
                </div>
            </div>
        </div>

        {{-- Styles are inline (not @assets) so they apply when this field is
             morphed into the page inside the action modal. --}}
        <style>
            .cms-fip-search{display:flex;align-items:center;gap:.85rem;margin-bottom:.85rem;}
            .cms-fip-search input{flex:1 1 auto;padding:.55rem .8rem;border-radius:9px;border:1px solid rgba(127,127,127,.35);
                background:transparent;color:inherit;font-size:.9rem;outline:none;}
            .cms-fip-search input:focus{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.25);}
            .cms-fip-count{font-size:.8rem;opacity:.7;white-space:nowrap;}
            .cms-fip-body{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:1rem;height:58vh;min-height:320px;}
            .cms-fip-gridwrap{overflow:auto;min-height:0;padding-right:.25rem;}
            .cms-fip-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.75rem;align-content:start;}
            .cms-fip-card{position:relative;text-align:left;padding:.4rem;border-radius:10px;background:rgba(127,127,127,.08);
                border:2px solid transparent;cursor:pointer;transition:border-color .12s,box-shadow .12s;color:inherit;}
            .cms-fip-card:hover{border-color:rgba(127,127,127,.5);}
            .cms-fip-card.is-selected{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.4);background:rgba(245,158,11,.10);}
            .cms-fip-thumb{width:100%;aspect-ratio:4/3;object-fit:cover;border-radius:7px;display:block;background:rgba(127,127,127,.15);}
            .cms-fip-badge{position:absolute;top:.6rem;right:.6rem;width:1.4rem;height:1.4rem;border-radius:999px;background:#f59e0b;
                color:#1f2937;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:800;box-shadow:0 1px 4px rgba(0,0,0,.35);}
            .cms-fip-name{font-size:12px;margin-top:.4rem;font-weight:500;word-break:break-word;line-height:1.25;}
            .cms-fip-meta{font-size:11px;opacity:.65;word-break:break-word;}
            .cms-fip-details{border-left:1px solid rgba(127,127,127,.25);padding-left:1rem;overflow:auto;min-height:0;}
            .cms-fip-preview{width:100%;max-height:200px;object-fit:contain;border-radius:9px;background:rgba(127,127,127,.12);}
            .cms-fip-dt{opacity:.6;text-transform:uppercase;letter-spacing:.04em;font-size:.64rem;font-weight:600;margin-top:.55rem;}
            .cms-fip-dd{font-size:.82rem;word-break:break-all;}
            .cms-fip-empty{padding:2.5rem 1rem;text-align:center;opacity:.7;font-size:.9rem;}
            .cms-fip-loadmore{display:flex;justify-content:center;padding:1rem 0 .25rem;}
            .cms-fip-loadmore-button{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;padding:.5rem .9rem;
                border-radius:9px;border:1px solid rgba(245,158,11,.65);background:rgba(245,158,11,.12);color:inherit;
                font-size:.85rem;font-weight:600;cursor:pointer;}
            .cms-fip-loadmore-button:hover{background:rgba(245,158,11,.20);}
            .cms-fip-loadmore-button:disabled{opacity:.65;cursor:wait;}
            @media (max-width:780px){
                .cms-fip-body{grid-template-columns:1fr;grid-template-rows:minmax(0,1fr) auto;height:auto;}
                .cms-fip-gridwrap{max-height:45vh;}
                .cms-fip-details{border-left:none;border-top:1px solid rgba(127,127,127,.25);padding-left:0;padding-top:.75rem;max-height:35vh;}
            }
        </style>
    </div>
</x-dynamic-component>
