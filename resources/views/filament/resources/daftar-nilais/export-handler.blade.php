<div wire:ignore>
    @vite('resources/js/daftar-nilai-export.js')

    <style>
        .dn-export-backdrop {
            position: fixed;
            inset: 0;
            z-index: 99999;
            background: rgba(15, 23, 42, 0.55);
            backdrop-filter: blur(3px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
        }
        .dn-export-card {
            width: 100%;
            max-width: 480px;
            background: #ffffff;
            color: #111827;
            border-radius: 14px;
            border: 1px solid #e5e7eb;
            box-shadow: 0 24px 48px -12px rgba(15, 23, 42, 0.28);
            padding: 1.5rem;
            font-family: inherit;
        }
        .dark .dn-export-card {
            background: #18181b;
            color: #f4f4f5;
            border-color: #27272a;
        }
        .dn-export-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1rem;
        }
        .dn-export-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            background: #ecfdf5;
            color: #15803d;
        }
        .dark .dn-export-icon {
            background: rgba(22, 163, 74, 0.16);
            color: #4ade80;
        }
        .dn-export-icon.is-error {
            background: #fef2f2;
            color: #dc2626;
        }
        .dark .dn-export-icon.is-error {
            background: rgba(220, 38, 38, 0.18);
            color: #f87171;
        }
        .dn-export-title {
            font-size: 1rem;
            font-weight: 700;
            margin: 0;
            line-height: 1.3;
        }
        .dn-export-subtitle {
            font-size: 0.8rem;
            color: #6b7280;
            margin: 0.15rem 0 0 0;
        }
        .dark .dn-export-subtitle {
            color: #a1a1aa;
        }
        .dn-progress-track {
            width: 100%;
            height: 10px;
            border-radius: 9999px;
            background: #e5e7eb;
            overflow: hidden;
            margin: 1rem 0 0.5rem 0;
        }
        .dark .dn-progress-track {
            background: #27272a;
        }
        .dn-progress-bar {
            height: 100%;
            border-radius: 9999px;
            background: linear-gradient(90deg, #2f6d28, #16a34a);
            transition: width 0.2s ease;
        }
        .dn-progress-bar.is-error {
            background: #dc2626;
        }
        .dn-progress-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.8rem;
            color: #4b5563;
            gap: 0.75rem;
        }
        .dark .dn-progress-meta {
            color: #a1a1aa;
        }
        .dn-progress-detail {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .dn-progress-pct {
            font-weight: 700;
            font-variant-numeric: tabular-nums;
            flex-shrink: 0;
        }
        .dn-export-note {
            margin-top: 0.85rem;
            padding: 0.6rem 0.75rem;
            border-radius: 8px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            font-size: 0.75rem;
            color: #6b7280;
            line-height: 1.45;
        }
        .dark .dn-export-note {
            background: #27272a;
            border-color: #3f3f46;
            color: #a1a1aa;
        }
        .dn-error-box {
            margin-top: 0.85rem;
            padding: 0.75rem;
            border-radius: 8px;
            background: #fef2f2;
            border: 1px solid #fecaca;
            color: #991b1b;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 0.75rem;
            line-height: 1.5;
            max-height: 220px;
            overflow: auto;
            white-space: pre-wrap;
            word-break: break-word;
            user-select: text;
            -webkit-user-select: text;
            cursor: text;
        }
        .dark .dn-error-box {
            background: rgba(127, 29, 29, 0.25);
            border-color: rgba(248, 113, 113, 0.35);
            color: #fca5a5;
        }
        .dn-error-hint {
            margin-top: 0.4rem;
            font-size: 0.72rem;
            color: #6b7280;
        }
        .dark .dn-error-hint {
            color: #a1a1aa;
        }
        .dn-btn-copy {
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        .dn-export-actions {
            margin-top: 1.15rem;
            display: flex;
            justify-content: flex-end;
            gap: 0.5rem;
        }
        .dn-btn {
            padding: 0.45rem 0.95rem;
            border-radius: 8px;
            font-size: 0.825rem;
            font-weight: 600;
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #374151;
            cursor: pointer;
            transition: background 0.15s ease;
        }
        .dn-btn:hover {
            background: #f3f4f6;
        }
        .dark .dn-btn {
            background: #27272a;
            border-color: #3f3f46;
            color: #e4e4e7;
        }
        .dark .dn-btn:hover {
            background: #3f3f46;
        }
        .dn-btn-primary {
            background: #2f6d28;
            border-color: #2f6d28;
            color: #ffffff;
        }
        .dn-btn-primary:hover {
            background: #23541e;
        }
        @keyframes dn-spin {
            to { transform: rotate(360deg); }
        }
        .dn-spinner {
            animation: dn-spin 1s linear infinite;
        }
    </style>

    <div
        x-data="{
            open: false,
            running: false,
            error: null,
            errorDetails: '',
            copied: false,
            done: false,
            format: 'excel',
            percent: 0,
            title: 'Menyiapkan export...',
            detail: '',
            abortController: null,
            metaUrl: @js($metaUrl),
            chunkUrl: @js($chunkUrl),

            buildCopyableError(err, format) {
                const lines = [
                    `Timestamp   : ${new Date().toISOString()}`,
                    `Format      : ${format.toUpperCase()}`,
                ];
                if (err?.details) {
                    lines.push(err.details);
                } else {
                    lines.push(`Message     : ${err?.message || String(err || 'Unknown error')}`);
                }
                if (err?.stack) {
                    lines.push('', 'Stack Trace:', err.stack);
                }
                return lines.join('\n');
            },

            async copyErrorText() {
                const text = this.errorDetails || this.error || '';
                if (!text) return;

                try {
                    if (navigator.clipboard && window.isSecureContext) {
                        await navigator.clipboard.writeText(text);
                    } else {
                        const ta = document.createElement('textarea');
                        ta.value = text;
                        ta.setAttribute('readonly', '');
                        ta.style.position = 'fixed';
                        ta.style.left = '-9999px';
                        document.body.appendChild(ta);
                        ta.select();
                        document.execCommand('copy');
                        ta.remove();
                    }
                    this.copied = true;
                    setTimeout(() => { this.copied = false; }, 2200);
                } catch {
                    // Fallback: seleksi teks di dalam kotak error agar user bisa tekan Ctrl+C
                    const box = this.$refs.errorBox;
                    if (box && window.getSelection) {
                        const range = document.createRange();
                        range.selectNodeContents(box);
                        const sel = window.getSelection();
                        sel.removeAllRanges();
                        sel.addRange(range);
                    }
                }
            },

            async waitForEngine(timeoutMs = 8000) {
                const start = Date.now();
                while (!window.PatriotDaftarNilaiExport) {
                    if (Date.now() - start > timeoutMs) {
                        throw new Error('Modul export browser gagal dimuat. Silakan muat ulang halaman.');
                    }
                    await new Promise((r) => setTimeout(r, 80));
                }
                return window.PatriotDaftarNilaiExport;
            },

            async startExport(rawPayload) {
                if (this.running) return;

                const payload = Array.isArray(rawPayload) ? rawPayload[0] : rawPayload;
                const format = payload?.format === 'csv' ? 'csv' : 'excel';
                const filters = payload?.filters || {};

                this.format = format;
                this.open = true;
                this.running = true;
                this.error = null;
                this.errorDetails = '';
                this.copied = false;
                this.done = false;
                this.percent = 2;
                this.title = format === 'excel' ? 'Memulai Export Excel (.xlsx)...' : 'Memulai Export CSV...';
                this.detail = 'Menghubungkan ke server (mode chunked ringan)...';
                this.abortController = new AbortController();

                try {
                    const engine = await this.waitForEngine();
                    const opts = {
                        metaUrl: this.metaUrl,
                        chunkUrl: this.chunkUrl,
                        filters,
                        signal: this.abortController.signal,
                        onProgress: (p) => {
                            this.percent = p.percent ?? this.percent;
                            if (p.title) this.title = p.title;
                            if (p.detail) this.detail = p.detail;
                        },
                    };

                    if (format === 'csv') {
                        await engine.exportCsv(opts);
                    } else {
                        await engine.exportExcel(opts);
                    }

                    this.done = true;
                    this.running = false;
                } catch (err) {
                    this.running = false;
                    if (err?.name === 'AbortError') {
                        this.open = false;
                        return;
                    }
                    this.error = err?.message || 'Terjadi kesalahan saat proses export.';
                    this.errorDetails = this.buildCopyableError(err, format);
                    this.title = 'Export Gagal';
                    this.detail = this.error;
                }
            },

            cancelExport() {
                if (this.abortController) {
                    this.abortController.abort();
                }
                this.running = false;
                this.open = false;
            },

            closeModal() {
                if (this.running) return;
                this.open = false;
            }
        }"
        @daftar-nilai:start-export.window="startExport($event.detail)"
    >
        <template x-if="open">
            <div class="dn-export-backdrop" role="dialog" aria-modal="true" aria-label="Proses Export Daftar Nilai">
                <div class="dn-export-card">
                    <div class="dn-export-header">
                        <div class="dn-export-icon" :class="{ 'is-error': Boolean(error) }">
                            <template x-if="running">
                                <svg class="dn-spinner" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <path d="M21 12a9 9 0 1 1-6.219-8.56" stroke-linecap="round"/>
                                </svg>
                            </template>
                            <template x-if="!running && done">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                                    <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </template>
                            <template x-if="!running && error">
                                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2">
                                    <circle cx="12" cy="12" r="10"/>
                                    <path d="M12 8v4M12 16h.01" stroke-linecap="round"/>
                                </svg>
                            </template>
                        </div>
                        <div style="min-width: 0; flex: 1;">
                            <h3 class="dn-export-title" x-text="title"></h3>
                            <p class="dn-export-subtitle" x-text="format === 'excel' ? 'Dashboard Nilai + Sheet Rubrik & Diagram per PT (1 file .xlsx)' : 'Dashboard Nilai (.csv)'"></p>
                        </div>
                    </div>

                    <div class="dn-progress-track">
                        <div
                            class="dn-progress-bar"
                            :class="{ 'is-error': Boolean(error) }"
                            :style="`width: ${percent}%`"
                        ></div>
                    </div>

                    <div class="dn-progress-meta">
                        <span class="dn-progress-detail" x-text="detail"></span>
                        <span class="dn-progress-pct" x-text="`${percent}%`"></span>
                    </div>

                    <div class="dn-export-note" x-show="!error">
                        Pengambilan data dibatasi aman &le; <strong>100 data PT/detik</strong> (chunk 25 PT/request), lalu penyusunan tabel, diagram, dan konversi Excel diproses sepenuhnya di browser Anda agar server tetap ringan.
                    </div>

                    <template x-if="error">
                        <div>
                            <pre class="dn-error-box" x-ref="errorBox" x-text="errorDetails || error"></pre>
                            <p class="dn-error-hint">Detail error di atas dapat diblok manual atau disalin langsung dengan tombol <strong>Salin Error</strong>.</p>
                        </div>
                    </template>

                    <div class="dn-export-actions">
                        <button
                            type="button"
                            class="dn-btn dn-btn-copy"
                            x-show="!running && Boolean(error)"
                            @click="copyErrorText()"
                        >
                            <template x-if="!copied">
                                <span style="display:inline-flex;align-items:center;gap:0.35rem;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                        <rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect>
                                        <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path>
                                    </svg>
                                    Salin Error
                                </span>
                            </template>
                            <template x-if="copied">
                                <span style="display:inline-flex;align-items:center;gap:0.35rem;color:#15803d;">
                                    <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4">
                                        <path d="M20 6L9 17l-5-5" stroke-linecap="round" stroke-linejoin="round"/>
                                    </svg>
                                    Tersalin!
                                </span>
                            </template>
                        </button>
                        <button
                            type="button"
                            class="dn-btn"
                            x-show="running"
                            @click="cancelExport()"
                        >
                            Batalkan
                        </button>
                        <button
                            type="button"
                            class="dn-btn dn-btn-primary"
                            x-show="!running"
                            @click="closeModal()"
                        >
                            Tutup
                        </button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</div>
