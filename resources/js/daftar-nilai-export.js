/**
 * Client-side Export Orchestrator for Admin Daftar Nilai (Excel .xlsx & CSV .csv).
 *
 * Server Protection Design:
 * - Server only sends lean JSON chunks via keyset cursor pagination (max 25 PT/request).
 * - Client enforces pacing delay (>= 260ms between requests => <= 100 PT/second) + auto Retry-After on HTTP 429.
 * - All score aggregation, HTML5 Canvas chart rendering, and ExcelJS zip/XML assembly happen in the client browser.
 */

import { CanvasChartRenderer } from './export/chart-renderer.js';
import { buildDaftarNilaiWorkbook } from './export/excel-builder.js';
import { buildDashboardCsv } from './export/rubrik-calculator.js';

let cachedExcelJsPromise = null;
async function loadExcelJs() {
    if (!cachedExcelJsPromise) {
        cachedExcelJsPromise = import('exceljs/dist/exceljs.min.js').then((m) => m.default || m);
    }
    return cachedExcelJsPromise;
}

function sleep(ms, signal) {
    return new Promise((resolve, reject) => {
        if (signal?.aborted) {
            reject(new DOMException('Export dibatalkan.', 'AbortError'));
            return;
        }
        const timer = setTimeout(() => {
            signal?.removeEventListener('abort', onAbort);
            resolve();
        }, ms);
        const onAbort = () => {
            clearTimeout(timer);
            reject(new DOMException('Export dibatalkan.', 'AbortError'));
        };
        signal?.addEventListener('abort', onAbort, { once: true });
    });
}

async function fetchJsonWithRetry(url, signal, maxRetries = 5) {
    let attempt = 0;

    while (true) {
        if (signal?.aborted) {
            throw new DOMException('Export dibatalkan.', 'AbortError');
        }

        const response = await fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            signal,
        });

        if (response.status === 429 && attempt < maxRetries) {
            attempt++;
            const retryHeader = Number(response.headers.get('Retry-After') || 1);
            const waitMs = Math.max(500, retryHeader * 1000) + attempt * 250;
            await sleep(waitMs, signal);
            continue;
        }

        if (!response.ok) {
            let errMsg = `Gagal mengambil data (HTTP ${response.status}).`;
            const detailLines = [
                `HTTP Status : ${response.status} ${response.statusText || ''}`.trim(),
                `Endpoint    : ${url}`,
            ];

            try {
                const rawText = await response.text();
                try {
                    const payload = JSON.parse(rawText);
                    if (payload?.message) errMsg = payload.message;
                    if (payload?.exception) detailLines.push(`Exception   : ${payload.exception}`);
                    if (payload?.file) detailLines.push(`File        : ${payload.file}${payload.line ? ':' + payload.line : ''}`);
                } catch {
                    if (rawText) {
                        detailLines.push(`Response    : ${rawText.slice(0, 600)}`);
                    }
                }
            } catch {
                // ignore body read error
            }

            const err = new Error(errMsg);
            err.details = [`Message     : ${errMsg}`, ...detailLines].join('\n');
            throw err;
        }

        const json = await response.json();
        if (!json?.success) {
            const errMsg = json?.message || 'Respons server tidak valid.';
            const err = new Error(errMsg);
            err.details = [
                `Message     : ${errMsg}`,
                `Endpoint    : ${url}`,
                `Payload     : ${JSON.stringify(json).slice(0, 600)}`,
            ].join('\n');
            throw err;
        }

        return json.data;
    }
}

function triggerBrowserDownload(blob, filename) {
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 10000);
}

function formatDateStamp() {
    const d = new Date();
    const pad = (n) => String(n).padStart(2, '0');
    return `${d.getFullYear()}${pad(d.getMonth() + 1)}${pad(d.getDate())}_${pad(d.getHours())}${pad(d.getMinutes())}`;
}

/**
 * Fetch all PT items in throttled, keyset-paginated chunks (<= 100 PT/sec).
 */
async function fetchAllChunks({ metaUrl, chunkUrl, mode, filters = {}, signal, onProgress }) {
    const baseParams = new URLSearchParams();
    if (filters.tahun_periode) baseParams.set('tahun_periode', String(filters.tahun_periode));
    if (filters.status) baseParams.set('status', String(filters.status));
    if (filters.search) baseParams.set('search', String(filters.search));

    onProgress?.({
        phase: 'meta',
        percent: 2,
        title: 'Memeriksa data di server...',
        detail: 'Menyiapkan metadata & skema rubrik...',
    });

    const metaQuery = baseParams.toString();
    const meta = await fetchJsonWithRetry(metaQuery ? `${metaUrl}?${metaQuery}` : metaUrl, signal);

    const total = Number(meta.total || 0);
    const chunkSize = Math.min(25, Math.max(1, Number(meta.chunk_size || 25)));
    const delayMs = Math.max(250, Number(meta.delay_ms || 260));

    if (total === 0) {
        return { meta, items: [] };
    }

    const items = [];
    let cursor = 0;
    let hasMore = true;
    let batchNo = 0;
    const totalBatches = Math.ceil(total / chunkSize);

    while (hasMore) {
        if (signal?.aborted) {
            throw new DOMException('Export dibatalkan.', 'AbortError');
        }

        const startedAt = performance.now();
        batchNo++;

        const params = new URLSearchParams(baseParams);
        params.set('cursor', String(cursor));
        params.set('limit', String(chunkSize));
        params.set('mode', mode);

        const chunkData = await fetchJsonWithRetry(`${chunkUrl}?${params.toString()}`, signal);
        const batchItems = Array.isArray(chunkData.items) ? chunkData.items : [];
        items.push(...batchItems);

        hasMore = Boolean(chunkData.has_more && chunkData.next_cursor);
        cursor = chunkData.next_cursor || cursor;

        // Fetch phase occupies 5%..45% for Excel (or 5%..90% for CSV)
        const maxFetchPct = mode === 'full' ? 45 : 90;
        const fetchPct = Math.min(maxFetchPct, 5 + Math.round((items.length / total) * (maxFetchPct - 5)));

        onProgress?.({
            phase: 'fetch',
            percent: fetchPct,
            title: `Mengambil data dari server (Batch ${batchNo}/${totalBatches})`,
            detail: `${items.length} / ${total} Perguruan Tinggi diambil (limit ≤ ${meta.max_per_second || 100} data/detik)`,
        });

        // Pacing delay agar server tidak pernah menerima burst request > 100 PT/detik
        if (hasMore) {
            const elapsed = performance.now() - startedAt;
            const remainingWait = Math.max(0, delayMs - elapsed);
            if (remainingWait > 0) {
                await sleep(remainingWait, signal);
            }
        }
    }

    return { meta, items };
}

/**
 * Public API mounted on window.PatriotDaftarNilaiExport
 */
window.PatriotDaftarNilaiExport = {
    async exportExcel({ metaUrl, chunkUrl, filters = {}, signal, onProgress }) {
        const { meta, items } = await fetchAllChunks({
            metaUrl,
            chunkUrl,
            mode: 'full',
            filters,
            signal,
            onProgress,
        });

        if (items.length === 0) {
            throw new Error('Tidak ada data Daftar Nilai yang sesuai filter untuk di-export.');
        }

        const ExcelJS = await loadExcelJs();
        const chartRenderer = new CanvasChartRenderer();
        try {
            const workbook = await buildDaftarNilaiWorkbook({
                ExcelJS,
                items,
                rubrikSchema: meta.rubrik_schema || [],
                chartRenderer,
                signal,
                onProgress: ({ current, total, label }) => {
                    const pct = 45 + Math.round((current / Math.max(1, total)) * 45);
                    onProgress?.({
                        phase: 'build',
                        percent: Math.min(90, pct),
                        title: 'Merender diagram & menyusun workbook Excel di browser...',
                        detail: label,
                    });
                },
            });

            if (signal?.aborted) {
                throw new DOMException('Export dibatalkan.', 'AbortError');
            }

            onProgress?.({
                phase: 'pack',
                percent: 94,
                title: 'Mengompresi file Excel (.xlsx)...',
                detail: `Menyusun ${items.length + 1} sheet (1 Dashboard + ${items.length} PT) menjadi 1 file Excel...`,
            });

            // Beri kesempatan UI melukis progress 94% sebelum kompresi zip
            await sleep(30, signal);

            const buffer = await workbook.xlsx.writeBuffer();
            const blob = new Blob([buffer], {
                type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            });

            const periodeTag = filters.tahun_periode ? `_${filters.tahun_periode}` : '';
            const filename = `Daftar_Nilai_Patriot_Metric${periodeTag}_${formatDateStamp()}.xlsx`;
            triggerBrowserDownload(blob, filename);

            onProgress?.({
                phase: 'done',
                percent: 100,
                title: 'Export Excel Selesai!',
                detail: `File "${filename}" berhasil diunduh (${items.length + 1} sheet).`,
            });

            return { filename, count: items.length };
        } finally {
            chartRenderer.dispose();
        }
    },

    async exportCsv({ metaUrl, chunkUrl, filters = {}, signal, onProgress }) {
        const { items } = await fetchAllChunks({
            metaUrl,
            chunkUrl,
            mode: 'summary',
            filters,
            signal,
            onProgress,
        });

        if (items.length === 0) {
            throw new Error('Tidak ada data Daftar Nilai yang sesuai filter untuk di-export.');
        }

        onProgress?.({
            phase: 'pack',
            percent: 95,
            title: 'Menyusun file CSV...',
            detail: `Memproses ${items.length} baris Dashboard Nilai...`,
        });

        const csvContent = buildDashboardCsv(items);
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });

        const periodeTag = filters.tahun_periode ? `_${filters.tahun_periode}` : '';
        const filename = `Dashboard_Nilai_Patriot_Metric${periodeTag}_${formatDateStamp()}.csv`;
        triggerBrowserDownload(blob, filename);

        onProgress?.({
            phase: 'done',
            percent: 100,
            title: 'Export CSV Selesai!',
            detail: `File "${filename}" berhasil diunduh (${items.length} PT).`,
        });

        return { filename, count: items.length };
    },
};
